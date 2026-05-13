<?php

namespace App\Console\Commands;

use App\Models\TempData;
use Illuminate\Console\Command;

/**
 * Placeholder komanda: trenutno **ne radi poslovnu obradu** (no-op).
 *
 * Ne briše `temp_data`, ne kreira rezervacije, ne poziva fiskal. Samo broji `pending` redove i završava uspešno.
 * Bilo koja buduća implementacija mora poštovati audit pravilo: `temp_data` se ne briše na uspeh glavnog payment toka
 * (v. `docs/payment-state-machine.md`, `docs/workflow-placanje-temp-data.md`, `docs/cron-commands.md`).
 */
class ProcessPendingReservations extends Command
{
    protected $signature = 'reservations:process-pending';

    protected $description = '[No-op stub] Counts pending temp_data rows; does not modify DB, fiscal, or reservations';

    public function handle(): int
    {
        $rows = TempData::where('status', TempData::STATUS_PENDING)->get();

        foreach ($rows as $_) {
            // No-op stub: no DB updates (v. PHPDoc).
        }

        $this->info('reservations:process-pending (no-op): scanned '.$rows->count().' pending temp_data row(s); no changes applied.');

        return self::SUCCESS;
    }
}
