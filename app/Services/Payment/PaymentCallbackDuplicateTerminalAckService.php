<?php

namespace App\Services\Payment;

use App\Models\Reservation;
use App\Models\TempData;

/**
 * Detects Bankart SUCCESS callbacks that only repeat an already completed payment.
 *
 * Safe to ACK without {@see \App\Jobs\PaymentCallbackJob} when temp_data is processed
 * and a reservation already exists for the same merchant_transaction_id.
 */
final class PaymentCallbackDuplicateTerminalAckService
{
    /**
     * @return array{temp_data_id:int,reservation_id:int,temp_status:string}|null
     */
    public function contextForImmediateAck(string $merchantTransactionId, string $normalizedCallbackStatus): ?array
    {
        if ($normalizedCallbackStatus !== 'success') {
            return null;
        }

        $mtid = trim($merchantTransactionId);
        if ($mtid === '') {
            return null;
        }

        $temp = TempData::query()
            ->where('merchant_transaction_id', $mtid)
            ->first();

        if (! $temp || $temp->status !== TempData::STATUS_PROCESSED) {
            return null;
        }

        $reservationId = Reservation::query()
            ->where('merchant_transaction_id', $mtid)
            ->value('id');

        if ($reservationId === null) {
            return null;
        }

        return [
            'temp_data_id' => (int) $temp->id,
            'reservation_id' => (int) $reservationId,
            'temp_status' => (string) $temp->status,
        ];
    }
}
