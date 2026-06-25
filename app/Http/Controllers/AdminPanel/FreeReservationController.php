<?php

namespace App\Http\Controllers\AdminPanel;

use App\Exceptions\AdminFreeReservationSlotsUnavailableException;
use App\Exceptions\AmbiguousFreeReservationLinkException;
use App\Exceptions\DuplicateTerminiReservationException;
use App\Exceptions\FreeReservationLinkedToOtherRequestException;
use App\Http\Controllers\Controller;
use App\Http\Requests\AdminPanel\AdminFreeReservationRequest;
use App\Http\Requests\AdminPanel\AdminFreeReservationRequestFulfillRequest;
use App\Http\Requests\AdminPanel\AdminFreeReservationRequestRejectRequest;
use App\Http\Requests\AdminPanel\AdminFreeReservationRequestUpdateRequest;
use App\Models\AdminAlert;
use App\Models\FreeReservationRequest;
use App\Models\FreeReservationRequestAttachment;
use App\Services\AdminPanel\FreeReservation\AdminDirectFreeReservationService;
use App\Services\AdminPanel\FreeReservation\FreeReservationRequestAvailability;
use App\Services\AdminPanel\FreeReservation\FreeReservationRequestFulfillmentService;
use App\Services\AdminPanel\FreeReservation\ServeFzbrAttachmentFile;
use App\Services\ExternalArchive\ExternalFileArchiveService;
use App\Services\Reservation\ReservationBookingPageData;
use Carbon\Carbon;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\App;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

class FreeReservationController extends Controller
{
    private const TZ = 'Europe/Podgorica';

    public function create(Request $request, ReservationBookingPageData $pageData): View|RedirectResponse
    {
        App::setLocale('cg');

        $fzbrReviewSearchPerformed = $request->hasAny(['fzbr_review', 'fzbr_date_from', 'fzbr_date_to']);

        $fzbrReview = null;
        $fzbrDateFrom = null;
        $fzbrDateTo = null;
        $fzbrReviewRequests = collect();

        if ($fzbrReviewSearchPerformed) {
            $today = Carbon::now(self::TZ)->toDateString();
            $fzbrReview = $request->query('fzbr_review', 'approved');
            if (! in_array($fzbrReview, ['approved', 'rejected'], true)) {
                $fzbrReview = 'approved';
            }
            $fzbrDateFrom = $request->query('fzbr_date_from', $today);
            $fzbrDateTo = $request->query('fzbr_date_to', $today);

            $fzbrValidator = Validator::make(
                [
                    'fzbr_date_from' => $fzbrDateFrom,
                    'fzbr_date_to' => $fzbrDateTo,
                ],
                [
                    'fzbr_date_from' => ['required', 'date'],
                    'fzbr_date_to' => ['required', 'date', 'after_or_equal:fzbr_date_from'],
                ]
            );

            if ($fzbrValidator->fails()) {
                return redirect()
                    ->route('panel_admin.free-reservations', array_filter([
                        'fzbr_review' => $fzbrReview,
                        'fzbr_date_from' => $fzbrDateFrom,
                        'fzbr_date_to' => $fzbrDateTo,
                    ], static fn ($v) => $v !== null && $v !== ''), false)
                    ->withErrors($fzbrValidator)
                    ->withInput();
            }

            /** @var array{fzbr_date_from: string, fzbr_date_to: string} $fzbrDates */
            $fzbrDates = $fzbrValidator->validated();
            $fzbrDateFrom = $fzbrDates['fzbr_date_from'];
            $fzbrDateTo = $fzbrDates['fzbr_date_to'];

            $fzbrFrom = Carbon::parse($fzbrDateFrom, self::TZ)->startOfDay();
            $fzbrTo = Carbon::parse($fzbrDateTo, self::TZ)->endOfDay();

            $status = $fzbrReview === 'approved'
                ? FreeReservationRequest::STATUS_FULFILLED
                : FreeReservationRequest::STATUS_REJECTED;

            $fzbrReviewRequests = FreeReservationRequest::query()
                ->where('status', $status)
                ->whereBetween('updated_at', [$fzbrFrom, $fzbrTo])
                ->with([
                    'attachments',
                    'dropOffTimeSlot',
                    'pickUpTimeSlot',
                    'segments.dropOffTimeSlot',
                    'segments.pickUpTimeSlot',
                    'segments.vehicles',
                    'user',
                ])
                ->orderByDesc('updated_at')
                ->get();
        }

        $requests = FreeReservationRequest::query()
            ->whereIn('status', [
                FreeReservationRequest::STATUS_SUBMITTED,
                FreeReservationRequest::STATUS_UPDATED,
            ])
            ->with([
                'segments.dropOffTimeSlot',
                'segments.pickUpTimeSlot',
                'segments.vehicles.vehicleType.translations',
                'attachments',
            ])
            ->orderByDesc('created_at')
            ->get();

        // Attach availability flags for UI (no extra queries per card beyond eager-loaded vehicles).
        $availability = app(FreeReservationRequestAvailability::class);
        foreach ($requests as $r) {
            $a = $availability->forRequest($r);
            $r->setAttribute('can_fulfill', (bool) $a['can_fulfill']);
            $r->setAttribute('required_vehicles', (int) $a['required']); // total demanded vehicles across all segments
            $r->setAttribute('min_available', $a['min_available']);
            $r->setAttribute('segments_availability', $a['segments'] ?? []);
        }

        $fzbrReviewFilterActive = $fzbrReviewSearchPerformed;

        return view('admin-panel.free-reservations', array_merge(
            $pageData->forAdminPanel($request),
            [
                'navActive' => 'free-reservations',
                'pageTitle' => 'Besplatne rezervacije',
                'freeReservationRequests' => $requests,
                'fzbrReview' => $fzbrReview,
                'fzbrDateFrom' => $fzbrDateFrom,
                'fzbrDateTo' => $fzbrDateTo,
                'fzbrReviewRequests' => $fzbrReviewRequests,
                'fzbrReviewFilterActive' => $fzbrReviewFilterActive,
                'fzbrReviewSearchPerformed' => $fzbrReviewSearchPerformed,
            ]
        ));
    }

    public function store(
        AdminFreeReservationRequest $request,
        AdminDirectFreeReservationService $service,
    ): RedirectResponse {
        App::setLocale('cg');

        try {
            $service->create($request->validated());
        } catch (AdminFreeReservationSlotsUnavailableException) {
            return redirect()->to(route('panel_admin.free-reservations', [
                'name' => $request->input('name'),
                'country' => $request->input('country'),
                'license_plate' => $request->input('license_plate'),
                'email' => $request->input('email'),
                'vehicle_type_id' => $request->input('vehicle_type_id'),
            ], false))->with(
                'error',
                'Izabrani termini više nisu dostupni (zauzeti ili blokirani). Svi unosi su sačuvani osim datuma i vremena — izaberite ponovo termine.'
            );
        } catch (DuplicateTerminiReservationException $e) {
            return redirect()->to(route('panel_admin.free-reservations', [
                'name' => $request->input('name'),
                'country' => $request->input('country'),
                'license_plate' => $request->input('license_plate'),
                'email' => $request->input('email'),
                'vehicle_type_id' => $request->input('vehicle_type_id'),
            ], false))->with('error', $e->getMessage());
        }

        return redirect()->to(route('panel_admin.free-reservations', [], false))
            ->with(
                'status',
                'Besplatna rezervacija je kreirana. Potvrda sa PDF-om biće poslata na navedeni email.'
            );
    }

    public function fulfillRequest(
        AdminFreeReservationRequestFulfillRequest $request,
        FreeReservationRequest $freeReservationRequest,
        FreeReservationRequestFulfillmentService $service,
    ): RedirectResponse {
        App::setLocale('cg');

        try {
            $result = $service->fulfill($freeReservationRequest);
        } catch (AdminFreeReservationSlotsUnavailableException) {
            return back()->with('error', 'Kapacitet više nije dostupan za traženi datum/termine. Nijedna rezervacija nije kreirana.');
        } catch (DuplicateTerminiReservationException $e) {
            return back()->with('error', $e->getMessage());
        } catch (AmbiguousFreeReservationLinkException $e) {
            return back()->with('error', $e->getMessage());
        } catch (FreeReservationLinkedToOtherRequestException $e) {
            return back()->with('error', $e->getMessage());
        }

        if (! $result['mail_sent']) {
            if ($result['idempotent'] || $result['linked_existing'] > 0) {
                return back()->with(
                    'status',
                    'Zahtjev je označen kao obrađen (postojeće rezervacije su povezane). Slanje potvrda nije uspjelo — pokušajte ponovo.'
                );
            }

            return back()->with(
                'status',
                'Zahtjev je označen kao obrađen, ali slanje potvrda nije uspjelo. Pokušajte ponovo kasnije.'
            );
        }

        if ($result['idempotent'] || $result['linked_existing'] > 0) {
            return back()->with(
                'status',
                'Besplatne rezervacije su već postojale; zahtjev je označen kao obrađen i potvrde su poslate na email iz zahtjeva.'
            );
        }

        return back()->with('status', 'Zahtjev je obrađen. Kreirane su besplatne rezervacije i potvrde su poslate na email iz zahtjeva.');
    }

    public function updateRequest(
        AdminFreeReservationRequestUpdateRequest $request,
        FreeReservationRequest $freeReservationRequest,
        FreeReservationRequestAvailability $availability,
    ): RedirectResponse {
        App::setLocale('cg');

        $freeReservationRequest->loadMissing(['segments.vehicles']);
        $date = (string) $request->validated('reservation_date');
        /** @var array<int, array{id:int,drop_off_time_slot_id:int,pick_up_time_slot_id:int}> $segmentsIn */
        $segmentsIn = $request->validated('segments', []);

        // Reuse helper by cloning and applying new date/slots to segments (no save yet).
        $clone = clone $freeReservationRequest;
        $clone->reservation_date = \Carbon\Carbon::parse($date);
        $cloneSegs = $freeReservationRequest->segments->map(function ($seg) use ($date, $segmentsIn) {
            $in = collect($segmentsIn)->firstWhere('id', (int) $seg->id);
            $new = clone $seg;
            $new->reservation_date = \Carbon\Carbon::parse($date);
            if (is_array($in)) {
                $new->drop_off_time_slot_id = (int) $in['drop_off_time_slot_id'];
                $new->pick_up_time_slot_id = (int) $in['pick_up_time_slot_id'];
            }
            $new->setRelation('vehicles', $seg->vehicles);
            return $new;
        });
        $clone->setRelation('segments', $cloneSegs);

        $a = $availability->forRequest($clone);
        if (! $a['can_fulfill']) {
            return back()->with('error', 'Za izabrani datum i termine nema dovoljno slobodnih kapaciteta za ovaj zahtjev.');
        }

        DB::transaction(function () use ($freeReservationRequest, $date, $segmentsIn): void {
            $freeReservationRequest->update([
                'reservation_date' => $date,
                // Legacy: keep synced with first segment after update.
                'drop_off_time_slot_id' => (int) ($segmentsIn[0]['drop_off_time_slot_id'] ?? $freeReservationRequest->drop_off_time_slot_id),
                'pick_up_time_slot_id' => (int) ($segmentsIn[0]['pick_up_time_slot_id'] ?? $freeReservationRequest->pick_up_time_slot_id),
                'status' => FreeReservationRequest::STATUS_UPDATED,
            ]);

            foreach ($segmentsIn as $segIn) {
                $segId = (int) ($segIn['id'] ?? 0);
                if ($segId < 1) {
                    continue;
                }
                $seg = $freeReservationRequest->segments->firstWhere('id', $segId);
                if (! $seg) {
                    continue;
                }
                $seg->update([
                    'reservation_date' => $date,
                    'drop_off_time_slot_id' => (int) $segIn['drop_off_time_slot_id'],
                    'pick_up_time_slot_id' => (int) $segIn['pick_up_time_slot_id'],
                ]);
            }
        });

        return back()->with('status', 'Zahtjev je izmijenjen.');
    }

    public function rejectRequest(
        AdminFreeReservationRequestRejectRequest $request,
        FreeReservationRequest $freeReservationRequest,
    ): RedirectResponse {
        App::setLocale('cg');

        // Remove warning pointers then hard-delete request.
        \App\Models\AdminAlert::query()
            ->where('type', 'free_reservation_request')
            ->whereNull('removed_at')
            ->where('payload_json->free_reservation_request_id', $freeReservationRequest->id)
            ->update([
                'status' => \App\Models\AdminAlert::STATUS_DONE,
                'resolved_at' => now(),
                'removed_at' => now(),
            ]);

        $freeReservationRequest->update([
            'status' => FreeReservationRequest::STATUS_REJECTED,
        ]);

        return back()->with('status', 'Zahtjev je odbačen.');
    }

    public function previewAttachment(
        FreeReservationRequest $freeReservationRequest,
        FreeReservationRequestAttachment $attachment,
        ExternalFileArchiveService $archiveService,
        ServeFzbrAttachmentFile $serve,
    ): BinaryFileResponse {
        if ((int) $attachment->request_id !== (int) $freeReservationRequest->id) {
            abort(404);
        }

        // Mark related warning as "in progress" once an admin previews documentation.
        AdminAlert::query()
            ->where('type', 'free_reservation_request')
            ->whereNull('removed_at')
            ->where('payload_json->free_reservation_request_id', $freeReservationRequest->id)
            ->where('status', AdminAlert::STATUS_UNREAD)
            ->update([
                'status' => AdminAlert::STATUS_IN_PROGRESS,
            ]);

        return $serve->respond($attachment, $archiveService);
    }
}
