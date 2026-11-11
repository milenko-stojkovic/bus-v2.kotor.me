<?php

namespace App\Services\Reservation;

use App\Models\Reservation;
use App\Models\User;
use App\Support\ReservationOrigin;
use Carbon\Carbon;

final class PanelStatisticsDateBounds
{
    /**
     * Earliest reservation_date for this agency, or today when it has none.
     * Purchase, top-up, and correction dates can move the shared calendar
     * earlier in PanelController.
     */
    public function minDateFor(User $user): Carbon
    {
        $tz = (string) config('reservations.operations_timezone', 'Europe/Podgorica');

        // Earliest stored service date for this agency, including attributed
        // historical rows. DATE(column) is not used: a session timezone must
        // not move the calendar day, and a non-string aggregate must not fall
        // through to today.
        $min = Reservation::query()
            ->where('user_id', $user->id)
            ->where('reservation_date', '>=', '1000-01-01') // skip zero dates only
            ->min('reservation_date');
        $parsed = self::calendarDay($min, $tz);
        if ($parsed !== null) {
            return $parsed;
        }

        return Carbon::today($tz)->startOfDay();
    }

    /**
     * Calendar day of the earliest imported V1 created_at in the system.
     *
     * One aggregate on the V1 boundary (created_at < ReservationOrigin::IMPORTED_BEFORE).
     * It does not read another agency's report rows. Null when no V1 row exists.
     */
    public function globalHistoricalMinDate(): ?Carbon
    {
        $tz = (string) config('reservations.operations_timezone', 'Europe/Podgorica');
        $query = Reservation::query();
        ReservationOrigin::scopeImportedV1($query);

        return self::calendarDay($query->min('created_at'), $tz);
    }

    private static function calendarDay(mixed $value, string $tz): ?Carbon
    {
        if ($value instanceof \DateTimeInterface) {
            $value = $value->format('Y-m-d H:i:s');
        }
        if (! is_string($value) && ! is_numeric($value)) {
            return null;
        }

        $raw = trim((string) $value);
        if (strlen($raw) < 10 || ! preg_match('/^(\d{4}-\d{2}-\d{2})/', $raw, $match)) {
            return null;
        }
        if (str_starts_with($match[1], '0000')) {
            return null;
        }

        try {
            return Carbon::parse($match[1], $tz)->startOfDay();
        } catch (\Throwable) {
            return null;
        }
    }

    /**
     * max date:
     * - today + 90 days
     */
    public function maxDateFor(User $user): Carbon
    {
        $tz = (string) config('reservations.operations_timezone', 'Europe/Podgorica');

        return Carbon::today($tz)->addDays(90)->startOfDay();
    }
}

