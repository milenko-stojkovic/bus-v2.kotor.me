<?php

namespace App\Services\Pricing;

use App\Models\PriceChangeSchedule;
use App\Models\VehicleType;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Current vehicle-category tariff.
 *
 * vehicle_types.price stays the current catalog price. A due schedule is applied
 * inside the same call that reads the price, so checkout does not wait for the
 * minute scheduler. Category rank elsewhere still reads vehicle_types.price;
 * this class does not invent a second ordering.
 */
final class VehicleTariff
{
    public function timezone(): string
    {
        return (string) config('pricing.timezone', 'Europe/Podgorica');
    }

    public function now(): Carbon
    {
        return Carbon::now($this->timezone());
    }

    public function cardBlackoutMinutes(): int
    {
        return max(0, (int) config('pricing.card_blackout_minutes', 30));
    }

    public function advanceWarningMinutes(): int
    {
        return max(0, (int) config('pricing.advance_warning_minutes', 15));
    }

    /**
     * Apply every scheduled change whose 00:00 instant is at or before $at.
     * Safe to call repeatedly.
     */
    public function applyDue(?Carbon $at = null): int
    {
        $at = $this->inTimezone($at ?? $this->now());
        $applied = 0;

        DB::transaction(function () use ($at, &$applied): void {
            $due = PriceChangeSchedule::query()
                ->where('status', PriceChangeSchedule::STATUS_SCHEDULED)
                ->whereDate('effective_on', '<=', $at->toDateString())
                ->orderBy('effective_on')
                ->orderBy('id')
                ->lockForUpdate()
                ->get();

            foreach ($due as $schedule) {
                if ($schedule->effectiveAt()->greaterThan($at)) {
                    continue;
                }

                $schedule->load('lines');
                foreach ($schedule->lines as $line) {
                    VehicleType::query()
                        ->whereKey($line->vehicle_type_id)
                        ->lockForUpdate()
                        ->update(['price' => $line->price_after]);
                }

                $schedule->status = PriceChangeSchedule::STATUS_EFFECTIVE;
                $schedule->pending_slot = null;
                $schedule->applied_at = $at;
                $schedule->save();
                $applied++;

                Log::channel('payments')->info('price_change_applied', [
                    'schedule_id' => $schedule->id,
                    'effective_on' => $schedule->effectiveAt()->toDateString(),
                    'line_count' => $schedule->lines->count(),
                ]);
            }
        });

        return $applied;
    }

    public function pendingSchedule(): ?PriceChangeSchedule
    {
        return PriceChangeSchedule::query()
            ->where('status', PriceChangeSchedule::STATUS_SCHEDULED)
            ->orderBy('effective_on')
            ->orderBy('id')
            ->first();
    }

    /**
     * @return array{card_blocked: bool, advance_warning: bool, effective_on: ?string}
     */
    public function scheduleWindow(?Carbon $at = null): array
    {
        $at = $this->inTimezone($at ?? $this->now());
        $this->applyDue($at);
        $schedule = $this->pendingSchedule();
        $cardBlocked = false;
        $advanceWarning = false;
        $effectiveOn = null;

        if ($schedule !== null) {
            $effective = $schedule->effectiveAt();
            $effectiveOn = $effective->toDateString();
            $cardBlocked = $at->greaterThanOrEqualTo($effective->copy()->subMinutes($this->cardBlackoutMinutes()))
                && $at->lessThan($effective);
            $advanceWarning = $at->greaterThanOrEqualTo($effective->copy()->subMinutes($this->advanceWarningMinutes()))
                && $at->lessThan($effective);
        }

        return [
            'card_blocked' => $cardBlocked,
            'advance_warning' => $advanceWarning,
            'effective_on' => $effectiveOn,
        ];
    }

    public function quote(int $vehicleTypeId, ?Carbon $at = null): TariffQuote
    {
        $at = $this->inTimezone($at ?? $this->now());
        $window = $this->scheduleWindow($at);

        $price = VehicleType::query()->whereKey($vehicleTypeId)->value('price');
        if ($price === null || ! is_numeric((string) $price)) {
            throw new \InvalidArgumentException('Vehicle type price is missing.');
        }

        $payable = self::money($price);
        $incoming = $payable;
        $schedule = $this->pendingSchedule();
        if ($schedule !== null) {
            $schedule->loadMissing('lines');
            $line = $schedule->lines->firstWhere('vehicle_type_id', $vehicleTypeId);
            if ($line !== null) {
                $incoming = self::money($line->price_after);
            }
        }

        return new TariffQuote(
            $payable,
            $incoming,
            $window['card_blocked'],
            $window['advance_warning'],
            $window['effective_on'],
        );
    }

    /**
     * A new paid checkout must send the amount the customer is confirming.
     * A missing or different value does not authorize the charge.
     */
    public function confirmedAmountMatches(?string $confirmed, string $payable): bool
    {
        if ($confirmed === null || trim($confirmed) === '') {
            return false;
        }

        $parsed = self::parseMoney($confirmed);

        return $parsed !== null && self::cmp($parsed, $payable) === 0;
    }

    public static function money(mixed $value): string
    {
        return number_format((float) $value, 2, '.', '');
    }

    public static function parseMoney(string $raw): ?string
    {
        $raw = trim($raw);
        if (! preg_match('/^(?:0|[1-9]\d{0,7})(?:\.\d{1,2})?$/', $raw)) {
            return null;
        }

        $normalized = self::money($raw);
        if (self::cmp($normalized, '0.01') < 0 || self::cmp($normalized, '99999999.99') > 0) {
            return null;
        }

        return $normalized;
    }

    public static function cmp(string $left, string $right): int
    {
        return bccomp(self::money($left), self::money($right), 2);
    }

    public static function moneyMax(string $left, string $right): string
    {
        return self::cmp($left, $right) >= 0 ? self::money($left) : self::money($right);
    }

    private function inTimezone(Carbon $at): Carbon
    {
        return $at->copy()->timezone($this->timezone());
    }
}
