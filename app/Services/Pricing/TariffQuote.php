<?php

namespace App\Services\Pricing;

/**
 * Tariff in force at a single instant, after any due schedule has been applied.
 */
final class TariffQuote
{
    public function __construct(
        public readonly string $payable,
        public readonly string $incoming,
        public readonly bool $cardBlocked,
        public readonly bool $advanceWarning,
        public readonly ?string $effectiveOn,
    ) {}

    public function balanceRequired(): string
    {
        if ($this->advanceWarning) {
            return VehicleTariff::moneyMax($this->payable, $this->incoming);
        }

        return $this->payable;
    }
}
