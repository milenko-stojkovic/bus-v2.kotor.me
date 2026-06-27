<?php

namespace App\Support;

use App\Models\Reservation;

/**
 * Canonical reservation PDF attachment/download filenames (V1-compatible).
 */
final class ReservationPdfFilename
{
    public static function forReservation(Reservation $reservation): string
    {
        if ((string) $reservation->status === 'free') {
            return self::freeConfirmation($reservation);
        }

        return self::invoice($reservation);
    }

    public static function invoice(Reservation $reservation): string
    {
        return sprintf('invoice-%d-%s.pdf', (int) $reservation->id, self::dateSegment($reservation));
    }

    public static function freeConfirmation(Reservation $reservation): string
    {
        return sprintf('free-confirmation-%d-%s.pdf', (int) $reservation->id, self::dateSegment($reservation));
    }

    public static function dateSegment(Reservation $reservation): string
    {
        $raw = $reservation->reservation_date?->format('Y-m-d')
            ?? $reservation->created_at?->format('Y-m-d')
            ?? now()->format('Y-m-d');

        return self::sanitizeDateSegment($raw);
    }

    /**
     * Safe attachment segment: digits and hyphens only (Y-m-d).
     */
    public static function sanitizeDateSegment(string $raw): string
    {
        if (preg_match('/(\d{4}-\d{2}-\d{2})/', $raw, $matches) === 1) {
            return $matches[1];
        }

        $clean = preg_replace('/[^0-9-]/', '', $raw) ?? '';

        if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $clean) === 1) {
            return $clean;
        }

        return now()->format('Y-m-d');
    }
}
