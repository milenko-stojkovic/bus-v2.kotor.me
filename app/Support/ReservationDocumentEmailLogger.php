<?php

namespace App\Support;

use App\Models\Reservation;
use Throwable;

/**
 * Consistent payments-channel logging for customer reservation document emails.
 */
final class ReservationDocumentEmailLogger
{
    /**
     * @param  array<string, mixed>  $extra
     */
    public static function started(string $event, Reservation $reservation, string $attachmentFilename, array $extra = []): void
    {
        self::log('info', $event.'_started', $reservation, $attachmentFilename, $extra);
    }

    /**
     * @param  array<string, mixed>  $extra
     */
    public static function sent(string $event, Reservation $reservation, string $attachmentFilename, array $extra = []): void
    {
        self::log('info', $event.'_sent', $reservation, $attachmentFilename, $extra);
    }

    /**
     * @param  array<string, mixed>  $extra
     */
    public static function failed(string $event, Reservation $reservation, string $attachmentFilename, Throwable $e, array $extra = []): void
    {
        self::log('warning', $event.'_failed', $reservation, $attachmentFilename, array_merge($extra, [
            'message' => $e->getMessage(),
            'exception' => $e::class,
        ]));
    }

    /**
     * @param  array<string, mixed>  $extra
     */
    private static function log(string $level, string $event, Reservation $reservation, string $attachmentFilename, array $extra = []): void
    {
        $context = array_merge([
            'event' => $event,
            'reservation_id' => $reservation->id,
            'merchant_transaction_id' => $reservation->merchant_transaction_id,
            'recipient_email' => $reservation->email,
            'reservation_status' => $reservation->status,
            'reservation_kind' => $reservation->reservation_kind,
            'attachment_filename' => $attachmentFilename,
        ], $extra);

        \Illuminate\Support\Facades\Log::channel('payments')->{$level}($event, $context);
    }
}
