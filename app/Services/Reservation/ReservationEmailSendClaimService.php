<?php

namespace App\Services\Reservation;

use App\Models\Reservation;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * DB lock + EMAIL_SENDING claim for reservation document email jobs.
 * Reclaims stale EMAIL_SENDING locks (worker crash/OOM) after {@see STALE_SENDING_MINUTES}.
 */
final class ReservationEmailSendClaimService
{
    public const STALE_SENDING_MINUTES = 15;

    /**
     * @param  callable(Reservation): bool|null  $extraGuard  Return false to skip claim.
     * @return array{reservation: Reservation|null, claimed: bool}
     */
    public function claim(
        int $reservationId,
        bool $skipIfInvoiceAlreadySent = true,
        ?callable $extraGuard = null,
    ): array {
        /** @var Reservation|null $reservation */
        $reservation = null;
        $claimed = false;

        DB::transaction(function () use ($reservationId, $skipIfInvoiceAlreadySent, $extraGuard, &$reservation, &$claimed): void {
            $reservation = Reservation::query()
                ->whereKey($reservationId)
                ->lockForUpdate()
                ->first();

            if ($reservation === null) {
                return;
            }

            if ($extraGuard !== null && ! $extraGuard($reservation)) {
                return;
            }

            if ($skipIfInvoiceAlreadySent && $reservation->invoice_sent_at !== null) {
                return;
            }

            if ((int) $reservation->email_sent === Reservation::EMAIL_SENDING) {
                if (! $this->isStaleSendingLock($reservation)) {
                    return;
                }

                Log::channel('payments')->warning('reservation_email_sending_lock_stale_reclaimed', [
                    'reservation_id' => $reservation->id,
                    'merchant_transaction_id' => $reservation->merchant_transaction_id,
                    'email_sent' => $reservation->email_sent,
                    'updated_at' => $reservation->updated_at?->toIso8601String(),
                ]);
            }

            $reservation->update(['email_sent' => Reservation::EMAIL_SENDING]);
            $claimed = true;
        });

        return ['reservation' => $reservation, 'claimed' => $claimed];
    }

    public function isStaleSendingLock(Reservation $reservation): bool
    {
        if ($reservation->invoice_sent_at !== null) {
            return false;
        }

        $updatedAt = $reservation->updated_at;
        if ($updatedAt === null) {
            return true;
        }

        return $updatedAt->lte(now()->subMinutes(self::STALE_SENDING_MINUTES));
    }
}
