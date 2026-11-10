<?php

namespace App\Console\Commands;

use App\Services\Pricing\VehicleTariff;
use Illuminate\Console\Command;

/**
 * Backstop for due tariff changes. Checkout and the settings page apply the same
 * rows themselves, so a late or missed run does not charge the old price.
 */
class ApplyDuePriceChanges extends Command
{
    protected $signature = 'prices:apply-due';

    protected $description = 'Apply vehicle-category price changes whose effective time has passed';

    public function handle(VehicleTariff $tariff): int
    {
        $applied = $tariff->applyDue();
        $this->info('Applied '.$applied.' price change(s).');

        return self::SUCCESS;
    }
}
