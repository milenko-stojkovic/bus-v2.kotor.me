<?php

namespace App\Http\Controllers;

use App\Http\Requests\StoreVehicleCategoryChangeRequestRequest;
use App\Http\Requests\StoreVehicleRequest;
use App\Http\Requests\UpdateVehicleRequest;
use App\Models\AdminAlert;
use App\Models\Vehicle;
use App\Models\VehicleCategoryChangeRequest;
use App\Models\VehicleCategoryChangeRequestAttachment;
use App\Models\VehicleType;
use App\Services\Reservation\PanelReservationListService;
use App\Services\Reservation\VehicleReplacementCandidateService;
use App\Support\UiText;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;
use Illuminate\View\View;

class VehicleController extends Controller
{
    public function index(Request $request): View
    {
        $vehicles = $request->user()
            ->vehicles()
            ->where('status', Vehicle::STATUS_ACTIVE)
            ->with('vehicleType.translations')
            ->orderByDesc('id')
            ->paginate(10)
            ->withQueryString();

        $vehicleTypes = VehicleType::query()->with('translations')->orderBy('id')->get();

        return view('panel.vehicles', [
            'vehicles' => $vehicles,
            'vehicleTypes' => $vehicleTypes,
        ]);
    }

    public function store(StoreVehicleRequest $request): RedirectResponse
    {
        $locale = app()->getLocale();
        $validated = $request->validated();

        $plate = (string) $validated['license_plate'];
        $requestedTypeId = (int) $validated['vehicle_type_id'];

        $removedSamePlate = Vehicle::query()
            ->where('user_id', $request->user()->id)
            ->where('license_plate', $plate)
            ->where('status', Vehicle::STATUS_REMOVED)
            ->first();

        if ($removedSamePlate) {
            if ((int) $removedSamePlate->vehicle_type_id === $requestedTypeId) {
                $hasPendingCategoryChange = VehicleCategoryChangeRequest::query()
                    ->where('user_id', $request->user()->id)
                    ->where('status', VehicleCategoryChangeRequest::STATUS_PENDING)
                    ->where(function ($q) use ($removedSamePlate, $plate): void {
                        $q->where('old_vehicle_id', $removedSamePlate->id)
                            ->orWhere('license_plate', $plate);
                    })
                    ->exists();

                if ($hasPendingCategoryChange) {
                    return redirect()->route('panel.vehicles')->with(
                        'error',
                        UiText::t(
                            'panel',
                            'vehicle_category_change_pending_blocks_reactivation',
                            $locale === 'en'
                                ? 'A category change request for this plate is awaiting admin decision. The vehicle cannot be reactivated until the request is approved or rejected.'
                                : 'Zahtjev za promjenu kategorije za ovu tablicu čeka odluku administratora. Vozilo se ne može reaktivirati dok zahtjev nije odobren ili odbijen.',
                            $locale
                        )
                    );
                }

                $removedSamePlate->update([
                    'status' => Vehicle::STATUS_ACTIVE,
                ]);

                return redirect()->route('panel.vehicles')->with(
                    'message',
                    UiText::t('panel', 'vehicle_reactivated', 'Vehicle reactivated.', $locale)
                );
            }

            // Block direct creation; show request upload form.
            return redirect()
                ->to(route('panel.vehicles', [], false))
                ->with('category_change_needed', [
                    'old_vehicle_id' => (int) $removedSamePlate->id,
                    'license_plate' => $plate,
                    'old_vehicle_type_id' => (int) $removedSamePlate->vehicle_type_id,
                    'requested_vehicle_type_id' => $requestedTypeId,
                ])
                ->with('error', UiText::t(
                    'panel',
                    'vehicle_category_change_requires_approval',
                    $locale === 'en'
                        ? 'This vehicle plate exists with a different category. Please submit a category change request with a document.'
                        : 'Ova tablica već postoji u drugoj kategoriji. Pošaljite zahtjev za promjenu kategorije uz dokument.',
                    $locale
                ));
        }

        $request->user()->vehicles()->create(array_merge($validated, [
            'status' => Vehicle::STATUS_ACTIVE,
        ]));

        return redirect()->route('panel.vehicles')->with(
            'message',
            UiText::t('panel', 'vehicle_added', 'Vehicle added.', $locale)
        );
    }

    public function storeCategoryChangeRequest(StoreVehicleCategoryChangeRequestRequest $request): RedirectResponse
    {
        $user = $request->user();
        $locale = app()->getLocale();

        $data = $request->validated();

        $plate = (string) $data['license_plate'];
        $requestedTypeId = (int) $data['requested_vehicle_type_id'];

        // Ensure old vehicle matches and is removed.
        $oldVehicle = Vehicle::query()
            ->where('user_id', $user->id)
            ->whereKey((int) $data['old_vehicle_id'])
            ->firstOrFail();

        if ($oldVehicle->status !== Vehicle::STATUS_REMOVED || (string) $oldVehicle->license_plate !== $plate) {
            abort(422);
        }

        if ((int) $oldVehicle->vehicle_type_id !== (int) $data['old_vehicle_type_id']) {
            abort(422);
        }

        // Deduplicate: one pending per user+plate+requested type.
        $existingPending = VehicleCategoryChangeRequest::query()
            ->where('user_id', $user->id)
            ->where('license_plate', $plate)
            ->where('requested_vehicle_type_id', $requestedTypeId)
            ->where('status', VehicleCategoryChangeRequest::STATUS_PENDING)
            ->first();

        if ($existingPending) {
            return redirect()->route('panel.vehicles')->with(
                'message',
                UiText::t('panel', 'vehicle_category_change_already_sent', 'Zahtjev je već poslat administratoru.', $locale)
            );
        }

        /** @var array<int, \Illuminate\Http\UploadedFile> $files */
        $files = array_values($request->file('documents', []));
        if ($files === []) {
            abort(422);
        }

        $req = null;

        DB::transaction(function () use ($user, $locale, $oldVehicle, $plate, $requestedTypeId, $files, &$req): void {
            $firstFile = $files[0];

            $req = VehicleCategoryChangeRequest::query()->create([
                'user_id' => $user->id,
                'old_vehicle_id' => $oldVehicle->id,
                'license_plate' => $plate,
                'old_vehicle_type_id' => $oldVehicle->vehicle_type_id,
                'requested_vehicle_type_id' => $requestedTypeId,
                'status' => VehicleCategoryChangeRequest::STATUS_PENDING,
                'document_original_name' => $firstFile->getClientOriginalName(),
                'document_path' => 'tmp',
                'document_mime_type' => (string) ($firstFile->getClientMimeType() ?? 'application/octet-stream'),
                'document_size_bytes' => (int) $firstFile->getSize(),
                'locale' => (string) $locale,
                'reviewed_by_admin_id' => null,
                'reviewed_at' => null,
            ]);

            $firstPath = null;

            foreach ($files as $file) {
                $attachment = VehicleCategoryChangeRequestAttachment::query()->create([
                    'vehicle_category_change_request_id' => $req->id,
                    'disk' => 'local',
                    'path' => 'tmp',
                    'original_name' => $file->getClientOriginalName(),
                    'mime_type' => (string) ($file->getClientMimeType() ?? 'application/octet-stream'),
                    'size' => (int) $file->getSize(),
                ]);

                $path = 'vehicle-category-change-requests/'.$req->id.'/attachments/'.$attachment->id.'/file';
                Storage::disk('local')->putFileAs(dirname($path), $file, basename($path));

                $attachment->update(['path' => $path]);

                $firstPath ??= $path;
            }

            if ($firstPath !== null) {
                $req->update(['document_path' => $firstPath]);
            }

            AdminAlert::query()->create([
                'type' => 'vehicle_category_change_request',
                'status' => AdminAlert::STATUS_UNREAD,
                'title' => 'Zahtjev za promjenu kategorije vozila',
                'message' => "Došao je zahtjev za promjenu kategorije vozila od {$user->name}. Tablica {$plate}. Vidi pod Agencije.",
                'payload_json' => [
                    'vehicle_category_change_request_id' => (int) $req->id,
                    'user_id' => (int) $user->id,
                    'license_plate' => $plate,
                ],
            ]);
        });

        // Mail admin (cg) — link to review page; do not attach all files.
        try {
            $mailTo = 'bus@kotor.me';
            $oldType = VehicleType::query()->with('translations')->find((int) $oldVehicle->vehicle_type_id);
            $reqType = VehicleType::query()->with('translations')->find($requestedTypeId);

            $oldLabel = $oldType?->formatLabel('cg', 'EUR') ?? ('#'.$oldVehicle->vehicle_type_id);
            $reqLabel = $reqType?->formatLabel('cg', 'EUR') ?? ('#'.$requestedTypeId);

            $attachmentCount = $req ? $req->attachments()->count() : count($files);
            $adminReviewUrl = $req
                ? route('panel_admin.agencies.vehicle_category_change_requests.show', [
                    'user' => $user->id,
                    'request' => $req->id,
                ], true)
                : '';

            $mailable = new \App\Mail\VehicleCategoryChangeRequestMail(
                agencyName: (string) $user->name,
                agencyEmail: (string) $user->email,
                licensePlate: $plate,
                oldCategory: $oldLabel,
                requestedCategory: $reqLabel,
                attachmentCount: $attachmentCount,
                adminReviewUrl: $adminReviewUrl,
            );

            Mail::to($mailTo)->send($mailable);
        } catch (\Throwable) {
            // Do not fail request creation due to mail issues.
        }

        return redirect()->route('panel.vehicles')->with(
            'message',
            UiText::t('panel', 'vehicle_category_change_sent', 'Zahtjev je poslat administratoru.', $locale)
        );
    }

    public function update(UpdateVehicleRequest $request, int $vehicle): RedirectResponse
    {
        $model = $this->ownedVehicleOrFail($request, $vehicle);
        $model->update($request->validated());

        $locale = app()->getLocale();

        return redirect()->route('panel.vehicles')->with(
            'message',
            UiText::t('panel', 'vehicle_updated', 'Vehicle updated.', $locale)
        );
    }

    public function destroy(Request $request, int $vehicle, PanelReservationListService $lists): RedirectResponse
    {
        $model = $this->ownedVehicleOrFail($request, $vehicle);
        $locale = app()->getLocale();

        $usedInUpcoming = $lists->upcomingFor($request->user())
            ->contains(fn ($r) => (int) ($r->vehicle_id ?? 0) === (int) $model->id);

        if ($usedInUpcoming) {
            // Start replacement workflow.
            return redirect()->to(route('panel.vehicles.remove', ['vehicle' => $model->id], false));
        }

        $this->removeVehiclePreservingHistory($model);

        return redirect()->route('panel.vehicles')->with(
            'message',
            UiText::t('panel', 'vehicle_removed', 'Vehicle removed.', $locale)
        );
    }

    public function remove(
        Request $request,
        int $vehicle,
        PanelReservationListService $lists,
        VehicleReplacementCandidateService $candidates,
    ): View {
        $user = $request->user();
        $target = $this->ownedVehicleOrFail($request, $vehicle)->loadMissing('vehicleType');
        $locale = app()->getLocale();

        $upcoming = $lists->upcomingFor($user)->filter(fn ($r) => (int) ($r->vehicle_id ?? 0) === (int) $target->id)->values();

        $candidateMap = [];
        $missingAny = false;
        foreach ($upcoming as $r) {
            $candidateMap[(int) $r->id] = $candidates->candidatesForReservation($user, $r, [
                'exclude_vehicle_id' => (int) $target->id,
            ]);
            if ($candidateMap[(int) $r->id]->isEmpty()) {
                $missingAny = true;
            }
        }

        return view('panel.vehicles-remove', [
            'targetVehicle' => $target,
            'upcomingReservations' => $upcoming,
            'candidateVehiclesByReservationId' => $candidateMap,
            'missingAnyCandidate' => $missingAny,
            'locale' => $locale,
        ]);
    }

    public function applyRemove(
        Request $request,
        int $vehicle,
        PanelReservationListService $lists,
        VehicleReplacementCandidateService $candidates,
    ): RedirectResponse {
        $user = $request->user();
        $target = $this->ownedVehicleOrFail($request, $vehicle)->loadMissing('vehicleType');
        $locale = app()->getLocale();

        $upcoming = $lists->upcomingFor($user)->filter(fn ($r) => (int) ($r->vehicle_id ?? 0) === (int) $target->id)->values();
        if ($upcoming->isEmpty()) {
            $this->removeVehiclePreservingHistory($target);

            return redirect()->route('panel.vehicles')->with(
                'message',
                UiText::t('panel', 'vehicle_removed', 'Vehicle removed.', $locale)
            );
        }

        $data = $request->validate([
            'replacements' => ['required', 'array'],
            'replacements.*' => ['required', 'integer'],
        ]);

        /** @var array<int,int> $replacementMap */
        $replacementMap = [];
        foreach ($upcoming as $r) {
            $rid = (int) $r->id;
            $replacementMap[$rid] = (int) ($data['replacements'][$rid] ?? 0);
        }

        // Basic check: all reservation ids present and non-zero.
        foreach ($replacementMap as $rid => $vid) {
            if ($vid < 1) {
                return back()->with('error', UiText::t('panel', 'vehicle_remove_replacements_required', 'Select a replacement vehicle for each upcoming reservation.', $locale));
            }
        }

        try {
            DB::transaction(function () use ($user, $target, $replacementMap, $candidates): void {
                // Lock target vehicle row (exists + owned).
                $lockedTarget = Vehicle::query()
                    ->where('user_id', $user->id)
                    ->whereKey($target->id)
                    ->lockForUpdate()
                    ->with('vehicleType')
                    ->firstOrFail();

                // Lock reservations we are updating.
                $reservationIds = array_keys($replacementMap);
                $lockedReservations = \App\Models\Reservation::query()
                    ->where('user_id', $user->id)
                    ->whereIn('id', $reservationIds)
                    ->lockForUpdate()
                    ->with(['pickUpTimeSlot', 'dropOffTimeSlot', 'vehicleType'])
                    ->get()
                    ->keyBy('id');

                if ($lockedReservations->count() !== count($reservationIds)) {
                    abort(422);
                }

                // Lock candidate vehicles.
                $candidateIds = array_values(array_unique(array_values($replacementMap)));
                $lockedVehicles = Vehicle::query()
                    ->where('user_id', $user->id)
                    ->whereIn('id', $candidateIds)
                    ->lockForUpdate()
                    ->with('vehicleType')
                    ->get()
                    ->keyBy('id');

                if ($lockedVehicles->count() !== count($candidateIds)) {
                    abort(422);
                }

                // Pairwise planned assignment validation.
                if (! $candidates->validateReplacementCombination($user, $replacementMap)) {
                    abort(422);
                }

                // For each reservation: verify still upcoming, still uses target, candidate is eligible and conflict-free.
                foreach ($replacementMap as $rid => $newVehicleId) {
                    /** @var \App\Models\Reservation $res */
                    $res = $lockedReservations->get($rid);
                    if (! $res || ! PanelReservationListService::isUpcoming($res)) {
                        abort(422);
                    }
                    if ((int) ($res->vehicle_id ?? 0) !== (int) $lockedTarget->id) {
                        abort(422);
                    }

                    /** @var Vehicle $newVehicle */
                    $newVehicle = $lockedVehicles->get($newVehicleId);
                    if (! $newVehicle || (int) $newVehicle->id === (int) $lockedTarget->id) {
                        abort(422);
                    }

                    if (! $candidates->isVehicleCategoryAllowed($res, $newVehicle)) {
                        abort(422);
                    }

                    // Hard membership check: ensure selected vehicle is an actual candidate for this reservation.
                    $candidateList = $candidates->candidatesForReservation($user, $res, [
                        'exclude_vehicle_id' => (int) $lockedTarget->id,
                    ]);
                    $isMember = $candidateList->contains(fn (Vehicle $v) => (int) $v->id === (int) $newVehicle->id);
                    if (! $isMember) {
                        abort(422);
                    }

                    // Lock all same-date reservations for this candidate vehicle to prevent race.
                    $date = $res->reservation_date?->toDateString() ?? '';
                    \App\Models\Reservation::query()
                        ->where('user_id', $user->id)
                        ->where('vehicle_id', $newVehicle->id)
                        ->whereDate('reservation_date', $date)
                        ->lockForUpdate()
                        ->get();

                    if ($candidates->hasConflictWithUpcoming($user, $newVehicle->id, $res, ignoreReservationIds: [$res->id])) {
                        abort(422);
                    }
                }

                // Apply updates.
                foreach ($replacementMap as $rid => $newVehicleId) {
                    /** @var \App\Models\Reservation $res */
                    $res = $lockedReservations->get($rid);
                    /** @var Vehicle $newVehicle */
                    $newVehicle = $lockedVehicles->get($newVehicleId);

                    $res->update([
                        'vehicle_id' => $newVehicle->id,
                        'license_plate' => $newVehicle->license_plate,
                    ]);
                }

                // Target vehicle participated in reservations (we just moved them away) -> keep history via removed status.
                $lockedTarget->update([
                    'status' => Vehicle::STATUS_REMOVED,
                ]);
            });
        } catch (\Throwable $e) {
            return back()->with('error', UiText::t('panel', 'vehicle_remove_replace_failed', 'Vehicle could not be removed due to a conflict. Please try again.', $locale));
        }

        return redirect()->route('panel.vehicles')->with(
            'message',
            UiText::t('panel', 'vehicle_removed', 'Vehicle removed.', $locale)
        );
    }

    private function ownedVehicleOrFail(Request $request, int $id): Vehicle
    {
        return Vehicle::query()
            ->where('user_id', $request->user()->id)
            ->whereKey($id)
            ->firstOrFail();
    }

    private function removeVehiclePreservingHistory(Vehicle $vehicle): void
    {
        $hasAnyReservation = \App\Models\Reservation::query()
            ->where('vehicle_id', $vehicle->id)
            ->exists();

        if (! $hasAnyReservation) {
            $vehicle->delete();
            return;
        }

        $vehicle->update([
            'status' => Vehicle::STATUS_REMOVED,
        ]);
    }
}
