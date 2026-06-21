<?php

namespace App\Http\Controllers\AdminPanel;

use App\Http\Controllers\Controller;
use App\Http\Requests\AdminPanel\AdminAgencyAdvanceCorrectionRequest;
use App\Models\AdminAlert;
use App\Models\AgencyAdvanceTopup;
use App\Models\AgencyAdvanceTransaction;
use App\Models\User;
use App\Models\Vehicle;
use App\Models\VehicleCategoryChangeRequest;
use App\Services\AgencyAdvance\AdvanceTopupConfirmationService;
use App\Services\AgencyAdvance\AgencyAdvanceService;
use App\Services\AdminPanel\Agency\AdminAgencySearchService;
use Illuminate\Http\Response;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

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

    public function show(User $user, AgencyAdvanceService $advance): View
    {
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
            ->with(['oldVehicle', 'oldVehicleType.translations', 'requestedVehicleType.translations'])
            ->orderByDesc('created_at')
            ->get();

        return view('admin-panel.agencies.show', [
            'user' => $user,
            'advanceEnabled' => $advanceEnabled,
            'balance' => $balance,
            'ledger' => $ledger,
            'topups' => $topups,
            'pendingVehicleCategoryChangeRequests' => $pendingCategoryRequests,
        ]);
    }

    public function showVehicleCategoryChangeRequest(User $user, VehicleCategoryChangeRequest $request): View
    {
        if ((int) $request->user_id !== (int) $user->id) {
            abort(404);
        }

        $request->load(['oldVehicleType.translations', 'requestedVehicleType.translations']);

        return view('admin-panel.agencies.vehicle-category-change-request', [
            'user' => $user,
            'categoryChangeRequest' => $request,
        ]);
    }

    public function previewVehicleCategoryChangeDocument(User $user, VehicleCategoryChangeRequest $request): Response
    {
        if ((int) $request->user_id !== (int) $user->id) {
            abort(404);
        }

        $path = (string) $request->document_path;
        if ($path === '' || ! Storage::disk('local')->exists($path)) {
            abort(404);
        }

        $name = $request->document_original_name ?: 'document';
        $mime = $request->document_mime_type ?: 'application/octet-stream';

        return Storage::disk('local')->response($path, $name, [
            'Content-Type' => $mime,
            'Content-Disposition' => 'inline; filename="'.$name.'"',
        ]);
    }

    public function approveVehicleCategoryChangeRequest(User $user, VehicleCategoryChangeRequest $request): RedirectResponse
    {
        if ((int) $request->user_id !== (int) $user->id) {
            abort(404);
        }

        DB::transaction(function () use ($user, $request): void {
            /** @var VehicleCategoryChangeRequest $locked */
            $locked = VehicleCategoryChangeRequest::query()
                ->whereKey($request->id)
                ->lockForUpdate()
                ->firstOrFail();

            if ($locked->status !== VehicleCategoryChangeRequest::STATUS_PENDING) {
                throw ValidationException::withMessages(['status' => ['Zahtjev više nije pending.']]);
            }

            /** @var Vehicle $vehicle */
            $vehicle = Vehicle::query()
                ->where('user_id', $user->id)
                ->whereKey((int) $locked->old_vehicle_id)
                ->lockForUpdate()
                ->firstOrFail();

            if ($vehicle->status !== Vehicle::STATUS_REMOVED) {
                throw ValidationException::withMessages(['vehicle' => ['Vozilo nije u statusu removed.']]);
            }

            if ((string) $vehicle->license_plate !== (string) $locked->license_plate) {
                throw ValidationException::withMessages(['vehicle' => ['Tablica ne odgovara zahtjevu.']]);
            }

            $vehicle->update([
                'vehicle_type_id' => (int) $locked->requested_vehicle_type_id,
                'status' => Vehicle::STATUS_ACTIVE,
            ]);

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

        return redirect()
            ->to(route('panel_admin.agencies.show', $user, false))
            ->with('status', 'Zahtjev je prihvaćen i vozilo je reaktivirano.');
    }

    public function rejectVehicleCategoryChangeRequest(User $user, VehicleCategoryChangeRequest $request): RedirectResponse
    {
        if ((int) $request->user_id !== (int) $user->id) {
            abort(404);
        }

        DB::transaction(function () use ($user, $request): void {
            /** @var VehicleCategoryChangeRequest $locked */
            $locked = VehicleCategoryChangeRequest::query()
                ->whereKey($request->id)
                ->lockForUpdate()
                ->firstOrFail();

            if ($locked->status !== VehicleCategoryChangeRequest::STATUS_PENDING) {
                throw ValidationException::withMessages(['status' => ['Zahtjev više nije pending.']]);
            }

            // Ensure old vehicle belongs and stays removed.
            $vehicle = Vehicle::query()
                ->where('user_id', $user->id)
                ->whereKey((int) $locked->old_vehicle_id)
                ->lockForUpdate()
                ->firstOrFail();

            if ((string) $vehicle->license_plate !== (string) $locked->license_plate) {
                throw ValidationException::withMessages(['vehicle' => ['Tablica ne odgovara zahtjevu.']]);
            }

            $locked->update([
                'status' => VehicleCategoryChangeRequest::STATUS_REJECTED,
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

            Log::channel('payments')->info('vehicle_category_change_request_rejected', [
                'request_id' => (int) $locked->id,
                'agency_user_id' => (int) $user->id,
                'admin_id' => request()->user('panel_admin')?->id,
            ]);
        });

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

