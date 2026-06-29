<?php

namespace App\Services\Payment;

use App\Models\TempData;
use App\Services\AdminPanel\Blocking\BlockZoneWorklistService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * When Bankart createSession fails before redirect, temp_data must not stay pending (blocks retry + holds capacity).
 */
class PaymentInitFailureService
{
    public const RESOLUTION_REASON = 'payment_init_failed';

    public function failAndRelease(
        TempData $temp,
        string $stage,
        ?int $httpStatus = null,
        ?string $reason = null,
    ): void {
        $merchantTransactionId = $temp->merchant_transaction_id;
        $tempDataId = $temp->id;

        DB::transaction(function () use ($temp): void {
            $temp = TempData::where('id', $temp->id)->lockForUpdate()->first();
            if (! $temp || $temp->status !== TempData::STATUS_PENDING) {
                return;
            }

            $from = $temp->status;
            $temp->update([
                'status' => TempData::STATUS_CANCELED,
                'resolution_reason' => self::RESOLUTION_REASON,
            ]);
            TempData::logStateTransition(
                $temp->merchant_transaction_id,
                $from,
                TempData::STATUS_CANCELED,
                'createSession failed before bank redirect',
            );

            app(PaymentSuccessHandler::class)->releaseSoftLock($temp, false);
            app(BlockZoneWorklistService::class)->onTempDataFailedOrExpired($temp, 'canceled');
        });

        Log::channel('payments')->warning('payment_init_failed', array_merge(
            [
                'stage' => $stage,
                'merchant_transaction_id' => $merchantTransactionId,
                'temp_data_id' => $tempDataId,
                'http_status' => $httpStatus,
                'reason' => $reason ?? 'unavailable',
            ],
            array_filter([
                'guest' => $temp->user_id === null,
                'user_id' => $temp->user_id,
                'email' => $temp->email,
                'user_name' => $temp->user_name,
                'license_plate' => $temp->license_plate,
                'reservation_kind' => $temp->reservation_kind,
                'reservation_date' => $temp->reservation_date?->format('Y-m-d'),
                'drop_off_time_slot_id' => $temp->drop_off_time_slot_id,
                'pick_up_time_slot_id' => $temp->pick_up_time_slot_id,
                'vehicle_type_id' => $temp->vehicle_type_id,
            ], static fn ($v) => $v !== null && $v !== ''),
        ));
    }
}
