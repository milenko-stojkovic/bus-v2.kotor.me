<?php

namespace App\Services\Reservation;

use App\Jobs\SendFreeReservationConfirmationJob;
use App\Jobs\SendInvoiceEmailJob;
use App\Models\Reservation;
use Illuminate\Support\Facades\Log;

final class ReservationDocumentResendService
{
    /**
     * Reset email flags and queue the appropriate document email job.
     *
     * @return 'queued'|'not_found'|'unsupported_status'
     */
    public function queue(int $reservationId): string
    {
        /** @var Reservation|null $reservation */
        $reservation = Reservation::query()->find($reservationId);
        if ($reservation === null) {
            return 'not_found';
        }

        if (! in_array($reservation->status, ['paid', 'free'], true)) {
            return 'unsupported_status';
        }

        $reservation->update([
            'invoice_sent_at' => null,
            'email_sent' => Reservation::EMAIL_NOT_SENT,
        ]);

        if ($reservation->status === 'free') {
            SendFreeReservationConfirmationJob::dispatch($reservation->id);
        } else {
            SendInvoiceEmailJob::dispatch($reservation->id, $reservation->fiscal_jir !== null);
        }

        Log::channel('payments')->info('reservation_document_resend_queued', [
            'reservation_id' => $reservation->id,
            'merchant_transaction_id' => $reservation->merchant_transaction_id,
            'recipient_email' => $reservation->email,
            'reservation_status' => $reservation->status,
            'reservation_kind' => $reservation->reservation_kind,
            'is_fiscal' => $reservation->status === 'paid' && $reservation->fiscal_jir !== null,
        ]);

        return 'queued';
    }
}
