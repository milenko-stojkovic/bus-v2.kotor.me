<?php

namespace App\Services\Payment;

use App\Jobs\ProcessReservationAfterPaymentJob;
use App\Models\Reservation;
use App\Models\TempData;
use App\Services\AdminPanel\Blocking\BlockZoneWorklistService;
use App\Services\Reservation\GuestPaidLowerCategoryAlertService;
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
        $result = DB::transaction(function () use ($tempDataId): array {
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
                'Admin forced reservation from late_success'
            );

            // Soft-lock pending was already released at expire / late_manual_review —
            // only increment reserved (same end state as normal SUCCESS parking).
            $this->paymentSuccessHandler->incrementReservedForForcedCreate($temp);

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
            ]);
        }

        return $result;
    }

    /**
     * @return array{ok:bool,message:string}
     */
    public function reject(int $tempDataId): array
    {
        return DB::transaction(function () use ($tempDataId): array {
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
                'Admin rejected late_success payment'
            );

            Log::channel('payments')->info('late_success_admin_rejected', [
                'temp_data_id' => $temp->id,
                'merchant_transaction_id' => $temp->merchant_transaction_id,
            ]);

            return ['ok' => true, 'message' => 'Plaćanje je odbijeno (late_rejected). Rezervacija nije kreirana.'];
        });
    }
}
