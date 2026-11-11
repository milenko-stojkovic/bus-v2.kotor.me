<?php

namespace App\Services\Reservation;

use Carbon\Carbon;

/**
 * Inclusive Europe/Podgorica calendar window for the agency financial card.
 *
 * Eloquent writes TIMESTAMP values as naive Y-m-d H:i:s in the application
 * timezone (Europe/Podgorica). config/database.php does not set a MySQL
 * session timezone, and this report does not issue SET time_zone.
 *
 * MySQL compares a TIMESTAMP with the session timezone. The predicates below
 * use the same naive literal Eloquent writes and the connection returns.
 * That literal's calendar day is the Podgorica civil day when the session
 * timezone is unchanged since the write and presents Europe/Podgorica wall
 * time. A second PHP conversion is not applied.
 *
 * A half-open end (next civil midnight) keeps the index usable. When the
 * session uses Europe/Podgorica, both occurrences of a repeated autumn hour
 * fall on that civil date.
 */
final class AgencyFinancialCardWindow
{
    public const TIMEZONE = 'Europe/Podgorica';

    public const HISTORICAL_NOTICE_FROM = '2025-07-27';

    public const HISTORICAL_NOTICE_TO = '2026-06-17';

    /**
     * Inclusive calendar bounds for reservations.reservation_date.
     *
     * The column is DATE. The predicate is half-open through the next civil
     * day so both a stored Y-m-d and a stored Y-m-d 00:00:00 stay inside an
     * index range. DATE(column) is not used.
     *
     * @return array{start: string, end_exclusive: string}
     */
    public function serviceDateBounds(Carbon $from, Carbon $to): array
    {
        $start = $from->copy()->timezone(self::TIMEZONE)->startOfDay();
        $end = $to->copy()->timezone(self::TIMEZONE)->startOfDay()->addDay();

        return [
            'start' => $start->toDateString(),
            'end_exclusive' => $end->toDateString(),
        ];
    }

    /**
     * @return array{start: string, end_exclusive: string}
     */
    public function timestampBounds(Carbon $from, Carbon $to): array
    {
        $start = $from->copy()->timezone(self::TIMEZONE)->startOfDay();
        $end = $to->copy()->timezone(self::TIMEZONE)->startOfDay()->addDay()->startOfDay();

        return [
            'start' => $start->format('Y-m-d H:i:s'),
            'end_exclusive' => $end->format('Y-m-d H:i:s'),
        ];
    }

    public function overlapsHistoricalNotice(Carbon $from, Carbon $to): bool
    {
        $fromDate = $from->copy()->timezone(self::TIMEZONE)->toDateString();
        $toDate = $to->copy()->timezone(self::TIMEZONE)->toDateString();

        return $fromDate <= self::HISTORICAL_NOTICE_TO
            && $toDate >= self::HISTORICAL_NOTICE_FROM;
    }
}
