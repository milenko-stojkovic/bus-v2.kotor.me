<?php

namespace App\Services\Payment;

use App\Models\TempData;
use App\Services\AdminPanel\AdminAlertService;

/**
 * Deduped admin alert when Bankart createSession fails before redirect (not per-attempt spam).
 */
final class CheckoutPaymentInitAlertService
{
    public const TYPE = 'checkout_payment_init_failed';

    public function notifyIfNeeded(
        TempData $temp,
        string $stage,
        ?int $httpStatus = null,
        ?string $reason = null,
    ): void {
        $dedupeKey = self::TYPE.':'.($reason ?? 'unknown');

        app(AdminAlertService::class)->createOnce(
            self::TYPE,
            'Checkout: Bankart createSession nije uspio',
            sprintf(
                'Otvaranje prozora za plaćanje nije uspjelo (stage: %s, reason: %s). MTID: %s, email: %s, tablica: %s.',
                $stage,
                $reason ?? 'unknown',
                $temp->merchant_transaction_id ?? '—',
                $temp->email ?? '—',
                $temp->license_plate ?? '—',
            ),
            'medium',
            $dedupeKey,
            [
                'stage' => $stage,
                'reason' => $reason,
                'http_status' => $httpStatus,
                'merchant_transaction_id' => $temp->merchant_transaction_id,
                'temp_data_id' => $temp->id,
                'email' => $temp->email,
                'license_plate' => $temp->license_plate,
                'reservation_date' => $temp->reservation_date?->format('Y-m-d'),
                'guest' => $temp->user_id === null,
            ],
        );
    }
}
