<?php

namespace App\Services\AdminPanel\Analytics;

use App\Models\ListOfTimeSlot;

final class AdminAnalyticsDefinitions
{
    public const PART_FREE_MORNING = 'free_morning';
    public const PART_PAID_DAY = 'paid_day';
    public const PART_FREE_EVENING = 'free_evening';

    /** Outside Benovo slot grouping (Autoboka / Puč daily ticket). */
    public const PART_DAILY_TICKET = 'daily_ticket';

    public static function dayPartLabel(string $key): string
    {
        return match ($key) {
            self::PART_FREE_MORNING => 'Free jutro (00:00–07:00)',
            self::PART_PAID_DAY => 'Paid dnevni prozor (07:00–20:00)',
            self::PART_FREE_EVENING => 'Free veče (20:00–24:00)',
            self::PART_DAILY_TICKET => 'Dnevna karta (bez termina)',
            default => $key,
        };
    }

    /**
     * Determine day part by slot start time.
     * Uses start HH from ListOfTimeSlot::time_slot ("HH:MM - ...").
     */
    public static function dayPartForSlot(?ListOfTimeSlot $slot): string
    {
        $h = self::startHour($slot?->time_slot ?? '');
        if ($h === null) {
            return self::PART_PAID_DAY;
        }
        if ($h < 7) {
            return self::PART_FREE_MORNING;
        }
        if ($h >= 20) {
            return self::PART_FREE_EVENING;
        }

        return self::PART_PAID_DAY;
    }

    private static function startHour(string $timeSlot): ?int
    {
        if (! preg_match('/^\s*(\d{1,2}):(\d{2})\s*-\s*/', $timeSlot, $m)) {
            return null;
        }
        $h = (int) $m[1];
        if ($h < 0 || $h > 24) {
            return null;
        }

        return $h;
    }
}

