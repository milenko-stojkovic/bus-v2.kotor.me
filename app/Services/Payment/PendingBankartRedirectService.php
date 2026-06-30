<?php

namespace App\Services\Payment;

use App\Contracts\PaymentSessionResult;
use App\Models\TempData;

/**
 * Reuse Bankart redirect URL for pending temp_data (avoid duplicate createSession per MTID).
 */
final class PendingBankartRedirectService
{
    public const DUPLICATE_TRANSACTION_ERROR_CODE = '3004';

    public function persistRedirectUrl(TempData $temp, string $paymentUrl): void
    {
        $paymentUrl = trim($paymentUrl);
        if ($paymentUrl === '') {
            return;
        }

        if ($temp->payment_redirect_url === $paymentUrl) {
            return;
        }

        $temp->forceFill(['payment_redirect_url' => $paymentUrl])->save();
    }

    public function storedRedirectUrl(TempData $temp): ?string
    {
        $url = trim((string) ($temp->payment_redirect_url ?? ''));

        return $url !== '' ? $url : null;
    }

    public function isDuplicateTransactionSession(PaymentSessionResult $session): bool
    {
        if ($session->gatewayErrorCode === self::DUPLICATE_TRANSACTION_ERROR_CODE) {
            return true;
        }

        $message = strtolower((string) ($session->errorMessage ?? ''));

        return str_contains($message, 'already exists');
    }

    public static function isExistingPendingStage(string $stage): bool
    {
        return in_array($stage, [
            'checkout_existing_pending',
            'checkout_after_unique_violation',
            'checkout_daily_ticket_after_unique_violation',
        ], true);
    }
}
