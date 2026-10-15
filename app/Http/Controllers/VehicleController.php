<?php

namespace App\Http\Controllers;

use App\Http\Requests\StoreVehicleCategoryChangeRequestRequest;
use App\Http\Requests\StoreVehicleRequest;
use App\Http\Requests\UpdateVehicleRequest;
use App\Models\AdminAlert;
use App\Models\User;
use App\Models\Vehicle;
use App\Models\VehicleCategoryChangeRequest;
use App\Models\VehicleCategoryChangeRequestAttachment;
use App\Models\VehicleType;
use App\Services\Reservation\PanelReservationListService;
use App\Services\Reservation\VehicleReplacementCandidateService;
use App\Services\Vehicle\VehiclePlateCategoryConsistency;
use App\Services\Vehicle\VehiclePlateCategoryConsistencyException;
use App\Services\Vehicle\VehiclePlateCategoryDecision;
use App\Services\Vehicle\VehiclePlateLock;
use App\Services\Vehicle\VehiclePlateLockNotAcquired;
use App\Support\UiText;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
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
        $plates = $vehicles->getCollection()->pluck('license_plate')->unique()->filter()->values();
        $conflictedPlates = [];
        if ($plates->isNotEmpty()) {
            $byPlate = Vehicle::query()
                ->whereIn('license_plate', $plates->all())
                ->get(['license_plate', 'vehicle_type_id'])
                ->groupBy('license_plate');
            foreach ($byPlate as $plate => $rows) {
                $types = $rows->pluck('vehicle_type_id')->unique();
                if ($types->count() > 1) {
                    $conflictedPlates[] = (string) $plate;
                }
            }
        }
        $pendingPlates = $plates->isEmpty()
            ? []
            : VehicleCategoryChangeRequest::query()
                ->where('user_id', $request->user()->id)
                ->where('status', VehicleCategoryChangeRequest::STATUS_PENDING)
                ->whereIn('license_plate', $plates->all())
                ->pluck('license_plate')
                ->map(fn ($plate): string => (string) $plate)
                ->unique()
                ->values()
                ->all();

        return view('panel.vehicles', [
            'vehicles' => $vehicles,
            'vehicleTypes' => $vehicleTypes,
            'conflictedPlates' => $conflictedPlates,
            'pendingPlates' => $pendingPlates,
        ]);
    }

    public function store(
        StoreVehicleRequest $request,
        VehiclePlateLock $locks,
        VehiclePlateCategoryConsistency $consistency,
    ): RedirectResponse {
        $locale = app()->getLocale();
        $validated = $request->validated();
        $plate = (string) $validated['license_plate'];
        $requestedTypeId = (int) $validated['vehicle_type_id'];

        try {
            return $locks->run($plate, function () use ($request, $consistency, $locale, $validated, $plate, $requestedTypeId): RedirectResponse {
                $rows = $consistency->lockedRows($plate);
                $userId = (int) $request->user()->id;

                $alreadyActive = $rows->first(function (Vehicle $row) use ($userId): bool {
                    return (int) $row->user_id === $userId && $row->status === Vehicle::STATUS_ACTIVE;
                });
                if ($alreadyActive instanceof Vehicle) {
                    return redirect()->route('panel.vehicles')->withErrors([
                        'license_plate' => 'The license plate has already been taken.',
                    ]);
                }

                if (($validated['category_choice'] ?? null) === 'use_recorded') {
                    $expected = (int) ($validated['expected_recorded_vehicle_type_id'] ?? 0);
                    if ($consistency->isConflicted($rows)) {
                        throw new VehiclePlateCategoryConsistencyException(VehiclePlateCategoryConsistencyException::CONFLICT);
                    }
                    $recordedNow = $consistency->singleRecordedTypeId($rows);
                    if ($recordedNow === null || $recordedNow !== $expected || $requestedTypeId !== $expected) {
                        return $this->staleCategoryChoiceRedirect($locale);
                    }
                }

                $decision = $consistency->decideAdd($rows, $userId, $requestedTypeId);

                if ($decision->action === VehiclePlateCategoryDecision::REACTIVATE) {
                    return $this->reactivateRemovedVehicle($request, $decision->ownRemoved, $plate, $locale);
                }

                if ($decision->action === VehiclePlateCategoryDecision::CHOOSE) {
                    return redirect()
                        ->to(route('panel.vehicles', [], false))
                        ->with('category_change_needed', [
                            'old_vehicle_id' => $decision->ownRemoved?->id,
                            'license_plate' => $plate,
                            'recorded_vehicle_type_id' => (int) $decision->recordedTypeId,
                            'submitted_vehicle_type_id' => $requestedTypeId,
                            'old_vehicle_type_id' => (int) $decision->recordedTypeId,
                            'requested_vehicle_type_id' => $requestedTypeId,
                        ])
                        ->with('error', $this->categoryChoiceIntro($locale));
                }

                $request->user()->vehicles()->create([
                    'license_plate' => $plate,
                    'vehicle_type_id' => $requestedTypeId,
                    'status' => Vehicle::STATUS_ACTIVE,
                ]);

                return redirect()->route('panel.vehicles')->with(
                    'message',
                    UiText::t('panel', 'vehicle_added', 'Vehicle added.', $locale)
                );
            });
        } catch (VehiclePlateLockNotAcquired) {
            return redirect()->route('panel.vehicles')->with('error', $this->plateLockTimeoutMessage($locale));
        } catch (VehiclePlateCategoryConsistencyException $e) {
            return redirect()->route('panel.vehicles')->with('error', $e->userMessage($locale));
        }
    }

    private function reactivateRemovedVehicle(Request $request, Vehicle $removedSamePlate, string $plate, string $locale): RedirectResponse
    {
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

    private function categoryChoiceIntro(string $locale): string
    {
        return UiText::t(
            'panel',
            'vehicle_category_choice_intro',
            $locale === 'en'
                ? 'This registration plate is already recorded in another category. Choose one option. The category you entered is not applied automatically.'
                : 'Ova registarska tablica je već evidentirana u drugoj kategoriji. Izaberite jednu opciju. Kategorija koju ste unijeli ne primjenjuje se automatski.',
            $locale === 'en' ? 'en' : 'cg'
        );
    }

    private function staleCategoryChoiceRedirect(string $locale): RedirectResponse
    {
        return redirect()->route('panel.vehicles')->with(
            'error',
            UiText::t(
                'panel',
                'vehicle_category_choice_stale',
                $locale === 'en'
                    ? 'The recorded category for this plate changed. Nothing was saved. Choose again.'
                    : 'Evidentirana kategorija za ovu tablicu je promijenjena. Ništa nije sačuvano. Izaberite ponovo.',
                $locale === 'en' ? 'en' : 'cg'
            )
        );
    }

    private function plateLockTimeoutMessage(string $locale): string
    {
        return UiText::t(
            'panel',
            'vehicle_plate_lock_timeout',
            $locale === 'en'
                ? 'The vehicle could not be saved because another update for this plate is in progress. Please try again.'
                : 'Vozilo nije sačuvano jer je druga izmjena ove tablice u toku. Pokušajte ponovo.',
            $locale === 'en' ? 'en' : 'cg'
        );
    }

    public function storeCategoryChangeRequest(
        StoreVehicleCategoryChangeRequestRequest $request,
        VehiclePlateLock $locks,
        VehiclePlateCategoryConsistency $consistency,
    ): RedirectResponse {
        $user = $request->user();
        $locale = app()->getLocale();

        $data = $request->validated();

        $plate = (string) $data['license_plate'];
        $requestedTypeId = (int) $data['requested_vehicle_type_id'];

        try {
            $stored = $locks->run($plate, function () use ($request, $user, $locale, $data, $plate, $requestedTypeId, $consistency): array {
                return $this->storeCategoryChangeRequestLocked($request, $user, $locale, $data, $plate, $requestedTypeId, $consistency);
            });
        } catch (VehiclePlateLockNotAcquired) {
            return redirect()->route('panel.vehicles')->with('error', $this->plateLockTimeoutMessage($locale));
        } catch (VehiclePlateCategoryConsistencyException $e) {
            return redirect()->route('panel.vehicles')->with('error', $e->userMessage($locale));
        }

        if (is_array($stored['notification'] ?? null)) {
            $this->sendCategoryChangeRequestNotification($stored['notification']);
        }

        return $stored['redirect'];
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array{redirect: RedirectResponse, notification: ?array}
     */
    private function storeCategoryChangeRequestLocked(
        StoreVehicleCategoryChangeRequestRequest $request,
        User $user,
        string $locale,
        array $data,
        string $plate,
        int $requestedTypeId,
        VehiclePlateCategoryConsistency $consistency,
    ): array {
        $rows = $consistency->lockedRows($plate);
        if ($consistency->isConflicted($rows)) {
            throw new VehiclePlateCategoryConsistencyException(VehiclePlateCategoryConsistencyException::CONFLICT);
        }

        $recordedTypeId = $consistency->singleRecordedTypeId($rows);
        $claimedOldTypeId = (int) $data['old_vehicle_type_id'];
        if ($recordedTypeId === null || $recordedTypeId !== $claimedOldTypeId || $requestedTypeId === $recordedTypeId) {
            return [
                'redirect' => $this->staleCategoryChoiceRedirect($locale),
                'notification' => null,
            ];
        }

        $oldVehicle = $this->categoryChangeOldVehicle($user, $rows, $data, $plate, $recordedTypeId);
        if ($oldVehicle === false) {
            return [
                'redirect' => $this->staleCategoryChoiceRedirect($locale),
                'notification' => null,
            ];
        }

        // One pending correction per agency and plate.
        $existingPending = VehicleCategoryChangeRequest::query()
            ->where('user_id', $user->id)
            ->where('license_plate', $plate)
            ->where('status', VehicleCategoryChangeRequest::STATUS_PENDING)
            ->first();

        if ($existingPending) {
            return [
                'redirect' => redirect()->route('panel.vehicles')->with(
                    'message',
                    UiText::t('panel', 'vehicle_category_change_already_sent', 'Zahtjev je već poslat administratoru.', $locale)
                ),
                'notification' => null,
            ];
        }

        /** @var array<int, \Illuminate\Http\UploadedFile> $files */
        $files = array_values($request->file('documents', []));
        if ($files === []) {
            abort(422);
        }

        $req = null;

        DB::transaction(function () use ($user, $locale, $oldVehicle, $plate, $claimedOldTypeId, $requestedTypeId, $files, &$req): void {
            $firstFile = $files[0];

            $req = VehicleCategoryChangeRequest::query()->create([
                'user_id' => $user->id,
                'old_vehicle_id' => $oldVehicle?->id,
                'license_plate' => $plate,
                'old_vehicle_type_id' => $claimedOldTypeId,
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

        return [
            'redirect' => redirect()->route('panel.vehicles')->with(
                'message',
                UiText::t('panel', 'vehicle_category_change_sent', 'Zahtjev je poslat administratoru.', $locale)
            ),
            'notification' => [
                'agency_name' => (string) $user->name,
                'agency_email' => (string) $user->email,
                'license_plate' => $plate,
                'old_vehicle_type_id' => $claimedOldTypeId,
                'requested_vehicle_type_id' => $requestedTypeId,
                'request_id' => (int) $req->id,
                'user_id' => (int) $user->id,
                'attachment_count' => $req->attachments()->count(),
            ],
        ];
    }

    /**
     * @param  Collection<int, Vehicle>  $rows
     * @param  array<string, mixed>  $data
     * @return Vehicle|null|false null when this agency has no row; false when the submitted row does not match the plate
     */
    private function categoryChangeOldVehicle(User $user, Collection $rows, array $data, string $plate, int $recordedTypeId): Vehicle|null|false
    {
        $own = $rows->filter(fn (Vehicle $row): bool => (int) $row->user_id === (int) $user->id);
        $submittedId = $data['old_vehicle_id'] ?? null;

        if ($submittedId !== null && $submittedId !== '') {
            $vehicle = $own->first(fn (Vehicle $row): bool => (int) $row->id === (int) $submittedId);
            if (! $vehicle instanceof Vehicle
                || (string) $vehicle->license_plate !== $plate
                || (int) $vehicle->vehicle_type_id !== $recordedTypeId) {
                return false;
            }

            return $vehicle;
        }

        $preferred = $own->first(fn (Vehicle $row): bool => $row->status === Vehicle::STATUS_REMOVED)
            ?? $own->first();

        if ($preferred instanceof Vehicle && (int) $preferred->vehicle_type_id !== $recordedTypeId) {
            return false;
        }

        return $preferred instanceof Vehicle ? $preferred : null;
    }

    /**
     * Sent only after the plate lock is released and the request transaction has committed.
     *
     * @param  array{agency_name: string, agency_email: string, license_plate: string, old_vehicle_type_id: int, requested_vehicle_type_id: int, request_id: int, user_id: int, attachment_count: int}  $notification
     */
    private function sendCategoryChangeRequestNotification(array $notification): void
    {
        try {
            $oldType = VehicleType::query()->with('translations')->find($notification['old_vehicle_type_id']);
            $reqType = VehicleType::query()->with('translations')->find($notification['requested_vehicle_type_id']);
            $oldLabel = $oldType?->formatLabel('cg', 'EUR') ?? ('#'.$notification['old_vehicle_type_id']);
            $reqLabel = $reqType?->formatLabel('cg', 'EUR') ?? ('#'.$notification['requested_vehicle_type_id']);
            $adminReviewUrl = route('panel_admin.agencies.vehicle_category_change_requests.show', [
                'user' => $notification['user_id'],
                'request' => $notification['request_id'],
            ], true);

            Mail::to('bus@kotor.me')->send(new \App\Mail\VehicleCategoryChangeRequestMail(
                agencyName: $notification['agency_name'],
                agencyEmail: $notification['agency_email'],
                licensePlate: $notification['license_plate'],
                oldCategory: $oldLabel,
                requestedCategory: $reqLabel,
                attachmentCount: $notification['attachment_count'],
                adminReviewUrl: $adminReviewUrl,
            ));
        } catch (\Throwable) {
            // Do not fail request creation due to mail issues.
        }
    }

    public function update(
        UpdateVehicleRequest $request,
        int $vehicle,
        VehiclePlateLock $locks,
        VehiclePlateCategoryConsistency $consistency,
    ): RedirectResponse {
        $model = $this->ownedVehicleOrFail($request, $vehicle);
        $locale = app()->getLocale();
        $newPlate = (string) $request->validated('license_plate');
        $newTypeId = (int) $request->validated('vehicle_type_id');

        try {
            $locks->run([$model->license_plate, $newPlate], function () use ($request, $model, $newPlate, $newTypeId, $consistency): void {
                $locked = Vehicle::query()
                    ->where('user_id', $request->user()->id)
                    ->whereKey($model->id)
                    ->lockForUpdate()
                    ->firstOrFail();
                $consistency->assertAssignmentKeepsOneCategory(
                    $consistency->lockedRows($newPlate),
                    (int) $locked->id,
                    $newTypeId,
                );
                $locked->update([
                    'license_plate' => $newPlate,
                    'vehicle_type_id' => $newTypeId,
                ]);
            });
        } catch (VehiclePlateLockNotAcquired) {
            return redirect()->route('panel.vehicles')->with('error', $this->plateLockTimeoutMessage($locale));
        } catch (VehiclePlateCategoryConsistencyException $e) {
            return redirect()->route('panel.vehicles')->with('error', $e->userMessage($locale));
        }

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
