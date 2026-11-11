<?php

namespace App\Services\Reservation;

use App\Models\Reservation;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Builder;

/**
 * Individual reservations for one stored license plate of the authenticated agency.
 *
 * Ownership is reservations.user_id. The plate is the exact stored string.
 * reservations.license_plate uses utf8mb4_unicode_ci, so a plain equality is
 * not case-sensitive on MySQL. utf8mb4_bin is PAD SPACE, so it also treats a
 * trailing space as equal. MySQL and MariaDB therefore compare
 * CAST(license_plate AS BINARY), which keeps case and trailing spaces and does
 * not need ANY_VALUE(). The indexed equality stays for the row lookup. Returned
 * rows are kept only when the stored value is identical. The date filter is the
 * inclusive reservation_date interval, including reservations that are not yet
 * realized. HTML and PDF use this same list.
 */
final class AgencyLicensePlateReportService
{
    public const TIMEZONE = 'Europe/Podgorica';

    public const MYSQL_EXACT_PLATE = 'CAST(license_plate AS BINARY) = CAST(? AS BINARY)';

    /**
     * Distinct stored plates on this agency's reservations, including attributed history.
     *
     * @return list<string>
     */
    public function platesFor(User $user): array
    {
        $query = Reservation::query()
            ->where('user_id', $user->id)
            ->whereNotNull('license_plate')
            ->where('license_plate', '!=', '');

        if (self::driverNeedsBinaryPlateMatch($query->getConnection()->getDriverName())) {
            $query->selectRaw('DISTINCT CAST(license_plate AS BINARY) as license_plate');
        } else {
            $query->distinct()->orderBy('license_plate');
        }

        $plates = [];
        foreach ($query->pluck('license_plate') as $plate) {
            $plate = (string) $plate;
            if ($plate !== '') {
                $plates[$plate] = $plate;
            }
        }

        $values = array_values($plates);
        sort($values, SORT_STRING);

        return $values;
    }

    public function ownsPlate(User $user, string $plate): bool
    {
        return $this->exactPlate(
            Reservation::query()->where('user_id', $user->id),
            $plate,
        )->exists();
    }

    public static function driverNeedsBinaryPlateMatch(string $driver): bool
    {
        return $driver === 'mysql' || $driver === 'mariadb';
    }

    /**
     * @param  Builder<Reservation>  $query
     * @return Builder<Reservation>
     */
    private function exactPlate(Builder $query, string $plate): Builder
    {
        $query->where('license_plate', $plate);
        if (self::driverNeedsBinaryPlateMatch($query->getConnection()->getDriverName())) {
            $query->whereRaw(self::MYSQL_EXACT_PLATE, [$plate]);
        }

        return $query;
    }

    /**
     * @return array{
     *   date_from: string,
     *   date_to: string,
     *   license_plate: string,
     *   lines: list<array{
     *     date: string,
     *     license_plate: string,
     *     category: string,
     *     daily_ticket: bool,
     *     drop_off_slot: ?string,
     *     pick_up_slot: ?string,
     *     merchant_transaction_id: ?string,
     *     status: string,
     *     amount: ?string
     *   }>,
     *   reservation_count: int,
     *   amount_total: string
     * }
     */
    public function build(User $user, string $plate, Carbon $from, Carbon $to, string $locale): array
    {
        $fromDate = $from->copy()->timezone(self::TIMEZONE)->toDateString();
        $toDate = $to->copy()->timezone(self::TIMEZONE)->toDateString();
        $endExclusive = $to->copy()->timezone(self::TIMEZONE)->startOfDay()->addDay()->toDateString();

        $rows = $this->exactPlate(
            Reservation::query()->where('user_id', $user->id),
            $plate,
        )
            ->where('reservation_date', '>=', $fromDate)
            ->where('reservation_date', '<', $endExclusive)
            ->with([
                'dropOffTimeSlot',
                'pickUpTimeSlot',
                'vehicleType.translations',
            ])
            ->orderBy('reservation_date')
            ->orderBy('id')
            ->get()
            ->filter(fn (Reservation $reservation): bool => $reservation->license_plate === $plate)
            ->values();

        $lines = [];
        $total = 0;
        foreach ($rows as $reservation) {
            $amount = self::presentedAmount($reservation);
            if ($amount !== null) {
                $total += self::cents($amount);
            }
            $category = trim((string) ($reservation->vehicleType?->getTranslatedName($locale) ?? ''));
            $mtid = is_string($reservation->merchant_transaction_id) ? trim($reservation->merchant_transaction_id) : '';
            $lines[] = [
                'date' => $reservation->reservation_date?->toDateString() ?? '',
                'license_plate' => (string) $reservation->license_plate,
                'category' => $category !== '' ? $category : '—',
                'daily_ticket' => $reservation->isDailyTicket(),
                'drop_off_slot' => $reservation->isDailyTicket() ? null : self::slotLabel($reservation->dropOffTimeSlot?->time_slot),
                'pick_up_slot' => $reservation->isDailyTicket() ? null : self::slotLabel($reservation->pickUpTimeSlot?->time_slot),
                'merchant_transaction_id' => $mtid !== '' ? $mtid : null,
                'status' => (string) $reservation->status,
                'amount' => $amount,
            ];
        }

        return [
            'date_from' => $fromDate,
            'date_to' => $toDate,
            'license_plate' => $plate,
            'lines' => $lines,
            'reservation_count' => count($lines),
            'amount_total' => self::formatCents($total),
        ];
    }

    private static function slotLabel(mixed $slot): ?string
    {
        $text = is_string($slot) ? trim($slot) : '';

        return $text !== '' ? $text : null;
    }

    /**
     * Free reservations are presented as 0.00. The stored invoice_amount is not changed.
     * Any other status shows the stored amount. A missing amount stays empty.
     */
    private static function presentedAmount(Reservation $reservation): ?string
    {
        if ($reservation->status === 'free') {
            return '0.00';
        }

        $stored = $reservation->invoice_amount;
        if ($stored === null || $stored === '') {
            return null;
        }

        return self::formatCents(self::cents($stored));
    }

    private static function cents(mixed $amount): int
    {
        $raw = trim((string) $amount);
        $negative = str_starts_with($raw, '-');
        $raw = ltrim($raw, '+-');
        if (! str_contains($raw, '.')) {
            $raw .= '.00';
        }
        [$whole, $fraction] = explode('.', $raw, 2);
        $whole = ltrim($whole, '0');
        $whole = $whole === '' ? '0' : $whole;
        $fraction = substr(str_pad(preg_replace('/\D/', '', $fraction) ?? '', 2, '0'), 0, 2);

        $cents = ((int) $whole) * 100 + (int) $fraction;

        return $negative ? -$cents : $cents;
    }

    private static function formatCents(int $cents): string
    {
        $sign = $cents < 0 ? '-' : '';
        $absolute = abs($cents);

        return $sign.intdiv($absolute, 100).'.'.str_pad((string) ($absolute % 100), 2, '0', STR_PAD_LEFT);
    }
}
