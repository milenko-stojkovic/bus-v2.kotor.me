<?php

namespace App\Services\Payment;

use App\Jobs\ProcessReservationAfterPaymentJob;
use App\Models\AdminAlert;
use App\Models\Reservation;
use App\Models\TempData;
use App\Models\User;
use App\Services\AdminPanel\Blocking\BlockZoneWorklistService;
use App\Services\Reservation\GuestPaidLowerCategoryAlertService;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Staff resolution for guest late_success (and legacy late_manual_review).
 * Agency late_success → advance conversion remains separate and unchanged.
 */
final class LateSuccessManualResolutionService
{
    public function __construct(
        private readonly PaymentSuccessHandler $paymentSuccessHandler,
    ) {}

    public function exposesManualActions(TempData $temp): bool
    {
        if ($temp->status === TempData::STATUS_LATE_MANUAL_REVIEW) {
            return true;
        }

        if ($temp->status !== TempData::STATUS_LATE_SUCCESS) {
            return false;
        }

        // Guest only — agencies use advance conversion.
        if ($temp->user_id !== null) {
            return false;
        }

        // Already converted to advance (should not happen for guests, defensive).
        if ((string) ($temp->resolution_reason ?? '') === 'converted_to_advance') {
            return false;
        }

        return true;
    }

    /**
     * @return array{ok:bool,message:string,reservation_id?:int,created_now?:bool}
     */
    public function forceCreate(int $tempDataId): array
    {
        $admin = $this->actingAdminContext();

        $result = DB::transaction(function () use ($tempDataId, $admin): array {
            /** @var TempData|null $temp */
            $temp = TempData::query()->whereKey($tempDataId)->lockForUpdate()->first();
            if (! $temp) {
                return ['ok' => false, 'message' => 'Zapis nije pronađen.'];
            }

            if (! $this->exposesManualActions($temp)) {
                return ['ok' => false, 'message' => 'Ovaj zapis nije dostupan za Force (samo guest late_success ili late_manual_review).'];
            }

            $existing = Reservation::query()
                ->where('merchant_transaction_id', $temp->merchant_transaction_id)
                ->first();
            if ($existing) {
                if ($temp->status !== TempData::STATUS_PROCESSED) {
                    $from = $temp->status;
                    $temp->update([
                        'status' => TempData::STATUS_PROCESSED,
                        'resolution_reason' => 'admin_forced',
                    ]);
                    TempData::logStateTransition(
                        $temp->merchant_transaction_id,
                        $from,
                        TempData::STATUS_PROCESSED,
                        'Admin forced create — reservation already existed'
                    );
                }

                $this->resolveGuestLateSuccessAlerts($temp, 'force', $admin, (int) $existing->id);

                return [
                    'ok' => true,
                    'message' => 'Rezervacija već postoji; nije izvršena akcija.',
                    'reservation_id' => $existing->id,
                    'created_now' => false,
                ];
            }

            if ($temp->status === TempData::STATUS_PROCESSED) {
                return ['ok' => false, 'message' => 'Zapis je već processed.'];
            }

            $reservation = $this->paymentSuccessHandler->createReservationFromTempDataPublic($temp, 'paid');

            Log::channel('payments')->info('payment_reservation_created', [
                'reservation_id' => $reservation->id,
                'merchant_transaction_id' => $reservation->merchant_transaction_id,
                'user_id' => $reservation->user_id,
                'status' => $reservation->status,
                'source' => 'admin_forced_late_success',
                'admin_user_id' => $admin['admin_user_id'],
                'admin_email' => $admin['admin_email'],
            ]);

            app(BlockZoneWorklistService::class)->onReservationCreated($reservation, $temp);

            $from = $temp->status;
            $temp->update([
                'status' => TempData::STATUS_PROCESSED,
                'resolution_reason' => 'admin_forced',
            ]);
            TempData::logStateTransition(
                $temp->merchant_transaction_id,
                $from,
                TempData::STATUS_PROCESSED,
                'Administrator forced reservation from late_success'
            );

            // Soft-lock pending was already released at expire / late_manual_review —
            // only increment reserved (same end state as normal SUCCESS parking).
            $this->paymentSuccessHandler->incrementReservedForForcedCreate($temp);

            $this->resolveGuestLateSuccessAlerts($temp, 'force', $admin, (int) $reservation->id);

            return [
                'ok' => true,
                'message' => 'Rezervacija je kreirana admin override-om.',
                'reservation_id' => $reservation->id,
                'created_now' => true,
            ];
        });

        if (($result['created_now'] ?? false) && ! empty($result['reservation_id'])) {
            ProcessReservationAfterPaymentJob::dispatch((int) $result['reservation_id']);

            $reservation = Reservation::query()->find((int) $result['reservation_id']);
            if ($reservation) {
                app(GuestPaidLowerCategoryAlertService::class)->evaluate($reservation);
            }

            Log::channel('payments')->info('late_success_admin_forced_pipeline_dispatched', [
                'reservation_id' => $result['reservation_id'],
                'temp_data_id' => $tempDataId,
                'admin_user_id' => $admin['admin_user_id'],
                'admin_email' => $admin['admin_email'],
            ]);
        }

        return $result;
    }

    /**
     * @return array{ok:bool,message:string}
     */
    public function reject(int $tempDataId): array
    {
        $admin = $this->actingAdminContext();

        return DB::transaction(function () use ($tempDataId, $admin): array {
            /** @var TempData|null $temp */
            $temp = TempData::query()->whereKey($tempDataId)->lockForUpdate()->first();
            if (! $temp) {
                return ['ok' => false, 'message' => 'Zapis nije pronađen.'];
            }

            if (! $this->exposesManualActions($temp)) {
                return ['ok' => false, 'message' => 'Ovaj zapis nije dostupan za Reject.'];
            }

            if (Reservation::query()->where('merchant_transaction_id', $temp->merchant_transaction_id)->exists()) {
                return ['ok' => false, 'message' => 'Rezervacija već postoji; Reject nije dozvoljen.'];
            }

            $from = $temp->status;
            $temp->update([
                'status' => TempData::STATUS_LATE_REJECTED,
                'resolution_reason' => 'admin_rejected',
            ]);
            TempData::logStateTransition(
                $temp->merchant_transaction_id,
                $from,
                TempData::STATUS_LATE_REJECTED,
                'Administrator rejected late_success payment'
            );

            Log::channel('payments')->info('late_success_admin_rejected', [
                'temp_data_id' => $temp->id,
                'merchant_transaction_id' => $temp->merchant_transaction_id,
                'admin_user_id' => $admin['admin_user_id'],
                'admin_email' => $admin['admin_email'],
            ]);

            $this->resolveGuestLateSuccessAlerts($temp, 'reject', $admin, null);

            return ['ok' => true, 'message' => 'Plaćanje je odbijeno (late_rejected). Rezervacija nije kreirana.'];
        });
    }

    /**
     * @return array{admin_user_id: int|null, admin_email: string|null}
     */
    private function actingAdminContext(): array
    {
        $user = Auth::user();
        if (! $user instanceof User) {
            return [
                'admin_user_id' => null,
                'admin_email' => null,
            ];
        }

        return [
            'admin_user_id' => (int) $user->id,
            'admin_email' => is_string($user->email) ? $user->email : null,
        ];
    }

    /**
     * Mark matching open guest_late_success alerts done (do not delete).
     *
     * @param  array{admin_user_id: int|null, admin_email: string|null}  $admin
     */
    private function resolveGuestLateSuccessAlerts(
        TempData $temp,
        string $action,
        array $admin,
        ?int $reservationId,
    ): void {
        $alerts = AdminAlert::query()
            ->where('type', 'guest_late_success')
            ->whereNull('removed_at')
            ->whereNot('status', AdminAlert::STATUS_DONE)
            ->where(function ($q) use ($temp): void {
                $q->where('temp_data_id', $temp->id);
                if (is_string($temp->merchant_transaction_id) && $temp->merchant_transaction_id !== '') {
                    $q->orWhere('merchant_transaction_id', $temp->merchant_transaction_id);
                }
            })
            ->get();

        if ($alerts->isEmpty()) {
            return;
        }

        foreach ($alerts as $alert) {
            $payload = is_array($alert->payload_json) ? $alert->payload_json : [];
            $payload['resolution_action'] = $action;
            $payload['resolved_by_admin_user_id'] = $admin['admin_user_id'];
            $payload['resolved_by_admin_email'] = $admin['admin_email'];

            $alert->update([
                'status' => AdminAlert::STATUS_DONE,
                'resolved_at' => now(),
                'reservation_id' => $reservationId ?? $alert->reservation_id,
                'payload_json' => $payload,
            ]);
        }

        Log::channel('payments')->info('guest_late_success_alert_resolved', [
            'temp_data_id' => $temp->id,
            'merchant_transaction_id' => $temp->merchant_transaction_id,
            'action' => $action,
            'reservation_id' => $reservationId,
            'rows_updated' => $alerts->count(),
            'admin_user_id' => $admin['admin_user_id'],
            'admin_email' => $admin['admin_email'],
        ]);
    }
}
