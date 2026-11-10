<?php

namespace App\Support;

use App\Models\TempData;
use App\Models\VehicleType;
use Illuminate\Support\Facades\Log;

/**
 * Snapshot iznosa računa u trenutku kreiranja rezervacije (ne iz PDF generatora).
 */
final class ReservationInvoiceAmount
{
    /**
     * @param  'paid'|'free'  $status
     */
    public static function snapshotForNewReservation(string $status, ?int $vehicleTypeId): string
    {
        if ($status === 'free') {
            return '0.00';
        }

        if ($vehicleTypeId === null) {
            return '0.00';
        }

        $price = VehicleType::query()->whereKey($vehicleTypeId)->value('price');

        return number_format((float) ($price ?? 0), 2, '.', '');
    }

    /**
     * Amount for a reservation created from an existing card attempt.
     * Paid rows use invoice_amount_snapshot. A missing snapshot keeps the legacy
     * live-price fallback and logs a warning. Free stays 0.00.
     */
    public static function forPaymentAttempt(TempData $temp, string $status): string
    {
        if ($status === 'free') {
            return '0.00';
        }

        $snapshot = $temp->invoice_amount_snapshot;
        if ($snapshot !== null && is_numeric((string) $snapshot)) {
            return number_format((float) $snapshot, 2, '.', '');
        }

        Log::channel('payments')->warning('reservation_invoice_amount_snapshot_missing', [
            'temp_data_id' => $temp->id,
            'merchant_transaction_id' => $temp->merchant_transaction_id,
            'vehicle_type_id' => $temp->vehicle_type_id,
        ]);

        return self::snapshotForNewReservation('paid', $temp->vehicle_type_id !== null ? (int) $temp->vehicle_type_id : null);
    }
}
