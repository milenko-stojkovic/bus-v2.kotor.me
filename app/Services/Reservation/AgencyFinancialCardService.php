<?php

namespace App\Services\Reservation;

use App\Models\AgencyAdvanceTopup;
use App\Models\AgencyAdvanceTransaction;
use App\Models\Reservation;
use App\Models\User;
use App\Support\ReservationOrigin;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;

/**
 * Agency financial card. One chronological list of recorded events.
 *
 * Incoming card purchases, paid advance top-ups, realized paid services, and
 * signed advance corrections stay in separate totals. This is not the advance
 * ledger balance and not a bank statement.
 *
 * A paid top-up with a null paid_at is not given a substitute date. It is
 * omitted from lines and from the incoming total, and reported as a count for
 * the authenticated agency on every card, because it cannot be placed in a range.
 */
final class AgencyFinancialCardService
{
    public function __construct(
        private readonly AgencyFinancialCardWindow $window,
    ) {}

    /**
     * @return array{
     *   date_from: string,
     *   date_to: string,
     *   lines: list<array{
     *     date: string,
     *     kind: string,
     *     note: ?string,
     *     merchant_transaction_id: ?string,
     *     license_plate: ?string,
     *     incoming: ?string,
     *     service: ?string,
     *     correction: ?string
     *   }>,
     *   total_incoming: string,
     *   total_services: string,
     *   total_corrections: string,
     *   historical_notice: bool,
     *   undated_paid_topup_count: int
     * }
     */
    public function build(User $user, Carbon $from, Carbon $to): array
    {
        $bounds = $this->window->timestampBounds($from, $to);
        $serviceDates = $this->window->serviceDateBounds($from, $to);
        $fromDate = $from->copy()->timezone(AgencyFinancialCardWindow::TIMEZONE)->toDateString();
        $toDate = $to->copy()->timezone(AgencyFinancialCardWindow::TIMEZONE)->toDateString();

        $lines = [];
        foreach ($this->cardPurchases($user, $bounds['start'], $bounds['end_exclusive']) as $row) {
            $lines[] = $row;
        }
        foreach ($this->topups($user, $bounds['start'], $bounds['end_exclusive']) as $row) {
            $lines[] = $row;
        }
        foreach ($this->services($user, $serviceDates['start'], $serviceDates['end_exclusive']) as $row) {
            $lines[] = $row;
        }
        foreach ($this->corrections($user, $bounds['start'], $bounds['end_exclusive']) as $row) {
            $lines[] = $row;
        }

        usort($lines, function (array $a, array $b): int {
            return [$a['sort_at'], $a['kind_rank'], $a['source_id']]
                <=> [$b['sort_at'], $b['kind_rank'], $b['source_id']];
        });

        $incoming = 0;
        $services = 0;
        $corrections = 0;
        $public = [];
        foreach ($lines as $line) {
            $incoming += self::cents($line['incoming']);
            $services += self::cents($line['service']);
            $corrections += self::cents($line['correction']);
            unset($line['sort_at'], $line['kind_rank'], $line['source_id']);
            $public[] = $line;
        }

        return [
            'date_from' => $fromDate,
            'date_to' => $toDate,
            'lines' => $public,
            'total_incoming' => self::formatCents($incoming),
            'total_services' => self::formatCents($services),
            'total_corrections' => self::formatCents($corrections),
            'historical_notice' => $this->window->overlapsHistoricalNotice($from, $to),
            'undated_paid_topup_count' => $this->undatedPaidTopupCount($user),
        ];
    }

    public function earliestSelectableDate(User $user): ?Carbon
    {
        $dates = [];

        $created = Reservation::query()->where('user_id', $user->id)->min('created_at');
        $dates[] = self::storedDate($created);

        $service = Reservation::query()
            ->where('user_id', $user->id)
            ->where('reservation_date', '>=', '1000-01-01')
            ->min('reservation_date');
        $dates[] = self::storedDate($service);

        $paidAt = AgencyAdvanceTopup::query()
            ->where('agency_user_id', $user->id)
            ->where('status', AgencyAdvanceTopup::STATUS_PAID)
            ->whereNotNull('paid_at')
            ->min('paid_at');
        $dates[] = self::storedDate($paidAt);

        $corrected = AgencyAdvanceTransaction::query()
            ->where('agency_user_id', $user->id)
            ->where('type', AgencyAdvanceTransaction::TYPE_CORRECTION)
            ->min('created_at');
        $dates[] = self::storedDate($corrected);

        $dates = array_values(array_filter($dates, fn (?string $date): bool => is_string($date) && $date !== ''));
        if ($dates === []) {
            return null;
        }

        sort($dates);

        return Carbon::parse($dates[0], AgencyFinancialCardWindow::TIMEZONE)->startOfDay();
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function cardPurchases(User $user, string $start, string $endExclusive): array
    {
        $rows = Reservation::query()
            ->where('user_id', $user->id)
            ->where('status', 'paid')
            ->where('created_at', '>=', $start)
            ->where('created_at', '<', $endExclusive)
            ->where(function (Builder $query): void {
                $query->where(function (Builder $imported): void {
                    ReservationOrigin::scopeImportedV1($imported);
                })->orWhere(function (Builder $native): void {
                    ReservationOrigin::scopeNativeV2($native);
                    $native->whereNull('payment_method')
                        ->whereNotExists(function ($usage): void {
                            $usage->select(DB::raw('1'))
                                ->from('agency_advance_transactions')
                                ->whereColumn('agency_advance_transactions.reference_id', 'reservations.id')
                                ->where('agency_advance_transactions.reference_type', 'reservation')
                                ->where('agency_advance_transactions.type', AgencyAdvanceTransaction::TYPE_USAGE);
                        });
                });
            })
            ->orderBy('id')
            ->get(['id', 'created_at', 'invoice_amount', 'merchant_transaction_id', 'license_plate']);

        $lines = [];
        foreach ($rows as $reservation) {
            $stored = $reservation->created_at?->format('Y-m-d H:i:s');
            if ($stored === null) {
                continue;
            }
            $lines[] = $this->line(
                date: substr($stored, 0, 10),
                sortAt: $stored,
                kind: 'card_purchase',
                rank: 1,
                sourceId: (int) $reservation->id,
                mtid: $reservation->merchant_transaction_id,
                plate: $reservation->license_plate,
                incoming: self::money($reservation->invoice_amount),
            );
        }

        return $lines;
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function topups(User $user, string $start, string $endExclusive): array
    {
        $rows = AgencyAdvanceTopup::query()
            ->where('agency_user_id', $user->id)
            ->where('status', AgencyAdvanceTopup::STATUS_PAID)
            ->whereNotNull('paid_at')
            ->where('paid_at', '>=', $start)
            ->where('paid_at', '<', $endExclusive)
            ->orderBy('id')
            ->get(['id', 'paid_at', 'amount', 'merchant_transaction_id']);

        $lines = [];
        foreach ($rows as $topup) {
            $stored = $topup->paid_at?->format('Y-m-d H:i:s');
            if ($stored === null) {
                continue;
            }
            $lines[] = $this->line(
                date: substr($stored, 0, 10),
                sortAt: $stored,
                kind: 'topup',
                rank: 2,
                sourceId: (int) $topup->id,
                mtid: $topup->merchant_transaction_id,
                plate: null,
                incoming: self::money($topup->amount),
            );
        }

        return $lines;
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function services(User $user, string $fromDate, string $endExclusive): array
    {
        $rows = Reservation::query()
            ->where('user_id', $user->id)
            ->where('status', 'paid')
            ->where('reservation_date', '>=', $fromDate)
            ->where('reservation_date', '<', $endExclusive)
            ->with('pickUpTimeSlot')
            ->orderBy('id')
            ->get();

        $lines = [];
        foreach ($rows as $reservation) {
            if (! PanelReservationListService::isRealized($reservation)) {
                continue;
            }
            $date = $reservation->reservation_date?->toDateString();
            if ($date === null) {
                continue;
            }
            $lines[] = $this->line(
                date: $date,
                sortAt: $date.' 00:00:00',
                kind: 'service',
                rank: 3,
                sourceId: (int) $reservation->id,
                mtid: $reservation->merchant_transaction_id,
                plate: $reservation->license_plate,
                service: self::money($reservation->invoice_amount),
            );
        }

        return $lines;
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function corrections(User $user, string $start, string $endExclusive): array
    {
        $rows = AgencyAdvanceTransaction::query()
            ->where('agency_user_id', $user->id)
            ->where('type', AgencyAdvanceTransaction::TYPE_CORRECTION)
            ->where('created_at', '>=', $start)
            ->where('created_at', '<', $endExclusive)
            ->orderBy('id')
            ->get(['id', 'created_at', 'amount', 'note']);

        $lines = [];
        foreach ($rows as $correction) {
            $stored = $correction->created_at?->format('Y-m-d H:i:s');
            if ($stored === null) {
                continue;
            }
            $note = trim((string) ($correction->note ?? ''));
            $lines[] = $this->line(
                date: substr($stored, 0, 10),
                sortAt: $stored,
                kind: 'correction',
                rank: 4,
                sourceId: (int) $correction->id,
                mtid: null,
                plate: null,
                correction: self::money($correction->amount),
                note: $note !== '' ? $note : null,
            );
        }

        return $lines;
    }

    private function undatedPaidTopupCount(User $user): int
    {
        return AgencyAdvanceTopup::query()
            ->where('agency_user_id', $user->id)
            ->where('status', AgencyAdvanceTopup::STATUS_PAID)
            ->whereNull('paid_at')
            ->count();
    }

    /**
     * @return array<string, mixed>
     */
    private function line(
        string $date,
        string $sortAt,
        string $kind,
        int $rank,
        int $sourceId,
        ?string $mtid,
        ?string $plate,
        ?string $incoming = null,
        ?string $service = null,
        ?string $correction = null,
        ?string $note = null,
    ): array {
        $mtid = is_string($mtid) && trim($mtid) !== '' ? $mtid : null;
        $plate = is_string($plate) && trim($plate) !== '' ? $plate : null;

        return [
            'date' => $date,
            'sort_at' => $sortAt,
            'kind' => $kind,
            'kind_rank' => $rank,
            'source_id' => $sourceId,
            'note' => $note,
            'merchant_transaction_id' => $mtid,
            'license_plate' => $plate,
            'incoming' => $incoming,
            'service' => $service,
            'correction' => $correction,
        ];
    }

    private static function storedDate(mixed $value): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }

        $stored = $value instanceof Carbon ? $value->format('Y-m-d H:i:s') : (string) $value;

        return strlen($stored) >= 10 ? substr($stored, 0, 10) : null;
    }

    private static function money(mixed $amount): string
    {
        return self::formatCents(self::cents($amount));
    }

    private static function cents(mixed $amount): int
    {
        if ($amount === null || $amount === '') {
            return 0;
        }

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
