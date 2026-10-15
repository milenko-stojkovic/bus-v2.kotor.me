<?php

namespace App\Http\Controllers\AdminPanel;

use App\Jobs\ArchiveVehicleCategoryChangeRequestAttachmentsJob;
use App\Http\Controllers\Controller;
use App\Http\Requests\AdminPanel\AdminAgencyAdvanceCorrectionRequest;
use App\Http\Requests\AdminPanel\RejectVehicleCategoryChangeRequestRequest;
use App\Models\AdminAlert;
use App\Models\AgencyAdvanceTopup;
use App\Models\AgencyAdvanceTransaction;
use App\Models\User;
use App\Models\Vehicle;
use App\Models\VehicleCategoryChangeRequest;
use App\Models\VehicleCategoryChangeRequestAttachment;
use App\Services\AgencyAdvance\AdvanceTopupConfirmationService;
use App\Services\AgencyAdvance\AgencyAdvanceService;
use App\Services\AdminPanel\Agency\AdminAgencySearchService;
use App\Services\AdminPanel\Agency\AgencyV1HistoricalEstimateService;
use App\Services\AdminPanel\Agency\AgencyV2ReservationStatisticsService;
use App\Services\Vehicle\VehiclePlateCategoryConsistency;
use App\Services\Vehicle\VehiclePlateLock;
use App\Services\Vehicle\VehiclePlateLockNotAcquired;
use App\Services\VehicleCategoryChange\VehicleCategoryChangeDecisionNotificationService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\StreamedResponse;

final class AgencyController extends Controller
{
    public function index(Request $request, AdminAgencySearchService $search): View
    {
        $validated = $request->validate([
            'q' => ['nullable', 'string', 'max:120'],
        ]);

        $searchTerm = isset($validated['q']) ? trim((string) $validated['q']) : '';

        $users = User::query()
            ->when($searchTerm !== '', fn ($query) => $search->applySearch($query, $searchTerm))
            ->withCount('reservations')
            ->withSum('agencyAdvanceTransactions as advance_balance', 'amount')
            ->orderBy('users.name')
            ->paginate(20)
            ->withQueryString();

        return view('admin-panel.agencies.index', [
            'users' => $users,
            'advanceEnabled' => (bool) config('features.advance_payments'),
            'search' => $searchTerm,
        ]);
    }

    public function show(
        Request $request,
        User $user,
        AgencyAdvanceService $advance,
        AgencyV2ReservationStatisticsService $v2Stats,
        AgencyV1HistoricalEstimateService $v1Estimate,
    ): View {
        $advanceEnabled = (bool) config('features.advance_payments');

        $balance = null;
        $ledger = collect();
        $topups = collect();

        if ($advanceEnabled) {
            $balance = $advance->balance((int) $user->id);

            $ledger = AgencyAdvanceTransaction::query()
                ->where('agency_user_id', $user->id)
                ->orderByDesc('created_at')
                ->limit(50)
                ->get();

            $topups = AgencyAdvanceTopup::query()
                ->where('agency_user_id', $user->id)
                ->orderByDesc('created_at')
                ->limit(50)
                ->get();
        }

        $pendingCategoryRequests = VehicleCategoryChangeRequest::query()
            ->where('user_id', $user->id)
            ->where('status', VehicleCategoryChangeRequest::STATUS_PENDING)
            ->with(['oldVehicle', 'oldVehicleType.translations', 'requestedVehicleType.translations', 'attachments'])
            ->orderByDesc('created_at')
            ->get();

        $v1Sort = in_array((string) $request->query('v1_sort'), ['confidence', 'date'], true)
            ? (string) $request->query('v1_sort')
            : 'confidence';

        return view('admin-panel.agencies.show', [
            'user' => $user,
            'advanceEnabled' => $advanceEnabled,
            'balance' => $balance,
            'ledger' => $ledger,
            'topups' => $topups,
            'pendingVehicleCategoryChangeRequests' => $pendingCategoryRequests,
            'v2ReservationStats' => $v2Stats->compute($user),
            'v1HistoricalEstimate' => $v1Estimate->compute($user, $v1Sort),
            'v1Sort' => $v1Sort,
        ]);
    }

    public function showVehicleCategoryChangeRequest(User $user, VehicleCategoryChangeRequest $request): View
    {
        if ((int) $request->user_id !== (int) $user->id) {
            abort(404);
        }

        $request->load(['oldVehicleType.translations', 'requestedVehicleType.translations', 'attachments']);

        return view('admin-panel.agencies.vehicle-category-change-request', [
            'user' => $user,
            'categoryChangeRequest' => $request,
        ]);
    }

    public function previewVehicleCategoryChangeDocument(User $user, VehicleCategoryChangeRequest $request): StreamedResponse
    {
        if ((int) $request->user_id !== (int) $user->id) {
            abort(404);
        }

        $path = (string) $request->document_path;
        if ($path === '' || ! Storage::disk('local')->exists($path)) {
            Log::channel('payments')->warning('vehicle_category_change_document_missing', [
                'request_id' => (int) $request->id,
                'agency_user_id' => (int) $user->id,
                'document_path' => $path !== '' ? $path : null,
            ]);
            abort(404, 'Dokument zahtjeva nije pronađen.');
        }

        $name = $request->document_original_name ?: 'document';
        $mime = $request->document_mime_type ?: 'application/octet-stream';

        return Storage::disk('local')->response($path, $name, [
            'Content-Type' => $mime,
            'Content-Disposition' => 'inline; filename="'.$name.'"',
        ]);
    }

    public function previewVehicleCategoryChangeAttachment(
        User $user,
        VehicleCategoryChangeRequest $request,
        VehicleCategoryChangeRequestAttachment $attachment,
    ): StreamedResponse {
        if ((int) $request->user_id !== (int) $user->id
            || (int) $attachment->vehicle_category_change_request_id !== (int) $request->id) {
            abort(404);
        }

        $disk = $attachment->disk ?: 'local';
        $path = (string) $attachment->path;

        if ($path === '' || ! Storage::disk($disk)->exists($path)) {
            Log::channel('payments')->warning('vehicle_category_change_attachment_missing', [
                'request_id' => (int) $request->id,
                'attachment_id' => (int) $attachment->id,
                'agency_user_id' => (int) $user->id,
                'document_path' => $path !== '' ? $path : null,
            ]);
            abort(404, 'Prilog zahtjeva nije pronađen.');
        }

        $name = $attachment->original_name ?: 'document';
        $mime = $attachment->mime_type ?: 'application/octet-stream';

        return Storage::disk($disk)->response($path, $name, [
            'Content-Type' => $mime,
            'Content-Disposition' => 'inline; filename="'.$name.'"',
        ]);
    }

    public function approveVehicleCategoryChangeRequest(
        User $user,
        VehicleCategoryChangeRequest $request,
        VehicleCategoryChangeDecisionNotificationService $notifier,
        VehiclePlateLock $locks,
        VehiclePlateCategoryConsistency $consistency,
    ): RedirectResponse {
        if ((int) $request->user_id !== (int) $user->id) {
            abort(404);
        }

        $plate = VehiclePlateCategoryConsistency::normalize((string) $request->license_plate);

        try {
            $locks->run($plate, function () use ($user, $request, $consistency): void {
                $this->approveVehicleCategoryChangeLocked($user, $request, $consistency);
            });
        } catch (VehiclePlateLockNotAcquired) {
            throw ValidationException::withMessages([
                'vehicle' => ['Zaključavanje tablice nije uspjelo. Pokušajte ponovo.'],
            ]);
        }

        ArchiveVehicleCategoryChangeRequestAttachmentsJob::dispatch((int) $request->id);
        $notifier->notifyApproved((int) $request->id);

        return redirect()
            ->to(route('panel_admin.agencies.show', $user, false))
            ->with('status', 'Zahtjev je prihvaćen. Kategorija je primijenjena na sve zapise ove tablice.');
    }

    private function approveVehicleCategoryChangeLocked(
        User $user,
        VehicleCategoryChangeRequest $request,
        VehiclePlateCategoryConsistency $consistency,
    ): void {
        DB::transaction(function () use ($user, $request, $consistency): void {
            /** @var VehicleCategoryChangeRequest $locked */
            $locked = VehicleCategoryChangeRequest::query()
                ->whereKey($request->id)
                ->lockForUpdate()
                ->firstOrFail();

            if ($locked->status !== VehicleCategoryChangeRequest::STATUS_PENDING) {
                throw ValidationException::withMessages(['status' => ['Zahtjev više nije pending.']]);
            }

            $plate = VehiclePlateCategoryConsistency::normalize((string) $locked->license_plate);
            $rows = $consistency->lockedRows($plate);
            $oldTypeId = (int) $locked->old_vehicle_type_id;
            $requestedTypeId = (int) $locked->requested_vehicle_type_id;

            if ($locked->old_vehicle_id !== null) {
                /** @var Vehicle $vehicle */
                $vehicle = Vehicle::query()
                    ->where('user_id', $user->id)
                    ->whereKey((int) $locked->old_vehicle_id)
                    ->lockForUpdate()
                    ->firstOrFail();

                if (VehiclePlateCategoryConsistency::normalize((string) $vehicle->license_plate) !== $plate) {
                    throw ValidationException::withMessages(['vehicle' => ['Tablica ne odgovara zahtjevu.']]);
                }

                if ($vehicle->status !== Vehicle::STATUS_REMOVED && $vehicle->status !== Vehicle::STATUS_ACTIVE) {
                    throw ValidationException::withMessages([
                        'vehicle' => ['Vozilo nije u podržanom statusu za odobrenje (removed ili active).'],
                    ]);
                }
            }

            if ($consistency->isConflicted($rows)) {
                throw ValidationException::withMessages([
                    'vehicle' => ['Tablica već ima neusaglašene kategorije. Zahtjev nije odobren.'],
                ]);
            }

            $recordedTypeId = $consistency->singleRecordedTypeId($rows);
            if ($recordedTypeId !== null && $recordedTypeId === $requestedTypeId) {
                throw ValidationException::withMessages([
                    'vehicle' => ['Vozilo je već na traženoj kategoriji; zahtjev se ne može odobriti iz ovog stanja.'],
                ]);
            }

            if ($recordedTypeId === null || $recordedTypeId !== $oldTypeId) {
                throw ValidationException::withMessages([
                    'vehicle' => ['Trenutna kategorija vozila ne odgovara staroj kategoriji zahtjeva.'],
                ]);
            }

            $requesterId = (int) $user->id;
            $hasActive = $rows->contains(function (Vehicle $row) use ($requesterId): bool {
                return (int) $row->user_id === $requesterId && $row->status === Vehicle::STATUS_ACTIVE;
            });
            $hasOwnRow = $rows->contains(fn (Vehicle $row): bool => (int) $row->user_id === $requesterId);

            foreach ($rows as $row) {
                $updates = [];
                if ((int) $row->vehicle_type_id !== $requestedTypeId) {
                    $updates['vehicle_type_id'] = $requestedTypeId;
                }
                $reactivate = ! $hasActive
                    && (int) $row->user_id === $requesterId
                    && $row->status === Vehicle::STATUS_REMOVED;
                if ($reactivate) {
                    $updates['status'] = Vehicle::STATUS_ACTIVE;
                }
                if ($updates !== []) {
                    $row->update($updates);
                }
            }

            if (! $hasActive && ! $hasOwnRow) {
                Vehicle::query()->create([
                    'user_id' => $user->id,
                    'license_plate' => $plate,
                    'vehicle_type_id' => $requestedTypeId,
                    'status' => Vehicle::STATUS_ACTIVE,
                ]);
            }

            $locked->update([
                'status' => VehicleCategoryChangeRequest::STATUS_APPROVED,
                'reviewed_by_admin_id' => request()->user('panel_admin')?->id,
                'reviewed_at' => now(),
            ]);

            AdminAlert::query()
                ->whereNull('removed_at')
                ->where('type', 'vehicle_category_change_request')
                ->where('payload_json->vehicle_category_change_request_id', (int) $locked->id)
                ->update([
                    'removed_at' => now(),
                ]);

            Log::channel('payments')->info('vehicle_category_change_request_approved', [
                'request_id' => (int) $locked->id,
                'agency_user_id' => (int) $user->id,
                'admin_id' => request()->user('panel_admin')?->id,
            ]);
        });
    }

    public function rejectVehicleCategoryChangeRequest(
        User $user,
        VehicleCategoryChangeRequest $request,
        RejectVehicleCategoryChangeRequestRequest $formRequest,
        VehicleCategoryChangeDecisionNotificationService $notifier,
    ): RedirectResponse {
        if ((int) $request->user_id !== (int) $user->id) {
            abort(404);
        }

        DB::transaction(function () use ($user, $request, $formRequest): void {
            /** @var VehicleCategoryChangeRequest $locked */
            $locked = VehicleCategoryChangeRequest::query()
                ->whereKey($request->id)
                ->lockForUpdate()
                ->firstOrFail();

            if ($locked->status !== VehicleCategoryChangeRequest::STATUS_PENDING) {
                throw ValidationException::withMessages(['status' => ['Zahtjev više nije pending.']]);
            }

            if ($locked->old_vehicle_id !== null) {
                $vehicle = Vehicle::query()
                    ->where('user_id', $user->id)
                    ->whereKey((int) $locked->old_vehicle_id)
                    ->lockForUpdate()
                    ->firstOrFail();

                if ((string) $vehicle->license_plate !== (string) $locked->license_plate) {
                    throw ValidationException::withMessages(['vehicle' => ['Tablica ne odgovara zahtjevu.']]);
                }
            }

            $locked->update([
                'status' => VehicleCategoryChangeRequest::STATUS_REJECTED,
                'reviewed_by_admin_id' => request()->user('panel_admin')?->id,
                'reviewed_at' => now(),
                'rejection_reason' => (string) $formRequest->validated('reason'),
            ]);

            AdminAlert::query()
                ->whereNull('removed_at')
                ->where('type', 'vehicle_category_change_request')
                ->where('payload_json->vehicle_category_change_request_id', (int) $locked->id)
                ->update([
                    'removed_at' => now(),
                ]);

            Log::channel('payments')->info('vehicle_category_change_request_rejected', [
                'request_id' => (int) $locked->id,
                'agency_user_id' => (int) $user->id,
                'admin_id' => request()->user('panel_admin')?->id,
            ]);
        });

        ArchiveVehicleCategoryChangeRequestAttachmentsJob::dispatch((int) $request->id);
        $notifier->notifyRejected((int) $request->id);

        return redirect()
            ->to(route('panel_admin.agencies.show', $user, false))
            ->with('status', 'Zahtjev je odbijen.');
    }

    public function storeAdvanceCorrection(User $user, AdminAgencyAdvanceCorrectionRequest $request, AgencyAdvanceService $advance): RedirectResponse
    {
        if (! (bool) config('features.advance_payments')) {
            abort(404);
        }

        $validated = $request->validated();
        $amountRaw = (float) $validated['amount'];
        $direction = (string) $validated['direction'];
        $reason = trim((string) $validated['reason']);

        $abs = number_format($amountRaw, 2, '.', '');
        $signed = $direction === 'decrease'
            ? number_format(-1 * (float) $abs, 2, '.', '')
            : $abs;

        DB::transaction(function () use ($user, $advance, $direction, $abs, $signed, $reason, $request): void {
            // Serialize corrections per agency.
            User::query()->whereKey($user->id)->lockForUpdate()->first();

            if ($direction === 'decrease') {
                $bal = (float) $advance->balance((int) $user->id);
                if ($bal + 0.000001 < (float) $abs) {
                    throw ValidationException::withMessages([
                        'amount' => ['Korekcija ne može umanjiti saldo ispod 0.00 EUR.'],
                    ]);
                }
            }

            AgencyAdvanceTransaction::query()->create([
                'agency_user_id' => $user->id,
                'amount' => $signed,
                'type' => AgencyAdvanceTransaction::TYPE_CORRECTION,
                'reference_type' => null,
                'reference_id' => null,
                'merchant_transaction_id' => null,
                'note' => $reason,
                'created_by_admin_id' => $request->user('panel_admin')?->id,
            ]);

            Log::channel('payments')->info('advance_balance_correction_created', [
                'agency_user_id' => $user->id,
                'admin_id' => $request->user('panel_admin')?->id,
                'direction' => $direction,
                'amount_abs' => $abs,
                'amount_signed' => $signed,
            ]);
        });

        return redirect()
            ->to(route('panel_admin.agencies.show', $user, false))
            ->with('status', 'Korekcija avansa je evidentirana.');
    }

    public function resendAdvanceTopupConfirmation(User $user, AgencyAdvanceTopup $topup, AdvanceTopupConfirmationService $confirm): RedirectResponse
    {
        if (! (bool) config('features.advance_payments')) {
            abort(404);
        }

        if ((int) $topup->agency_user_id !== (int) $user->id) {
            abort(404);
        }

        if ($topup->status !== AgencyAdvanceTopup::STATUS_PAID) {
            return redirect()
                ->to(route('panel_admin.agencies.show', $user, false))
                ->with('error', 'Potvrda se šalje samo za uplate sa statusom paid.');
        }

        if ($topup->confirmation_sent_at !== null) {
            return redirect()
                ->to(route('panel_admin.agencies.show', $user, false))
                ->with('message', 'Potvrda je već poslata.');
        }

        $result = $confirm->sendIfNeeded((string) $topup->merchant_transaction_id);

        return match ($result) {
            'sent' => redirect()
                ->to(route('panel_admin.agencies.show', $user, false))
                ->with('status', 'Potvrda je poslata.'),
            'already_sent' => redirect()
                ->to(route('panel_admin.agencies.show', $user, false))
                ->with('message', 'Potvrda je već poslata.'),
            default => redirect()
                ->to(route('panel_admin.agencies.show', $user, false))
                ->with('error', 'Slanje potvrde nije uspjelo. Pokušajte ponovo.'),
        };
    }
}

