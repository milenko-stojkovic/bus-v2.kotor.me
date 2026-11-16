<?php

namespace App\Console\Commands;

use App\Services\Reservation\HistoricalAttributionBatch3Catalog;
use App\Services\Reservation\HistoricalAttributionBatch3Preparer;
use Illuminate\Console\Command;
use Throwable;

/**
 * Freeze one Batch 3 candidate population. Not scheduled. Writes no database rows.
 */
class PrepareV1Batch3Command extends Command
{
    protected $signature = 'reservations:prepare-v1-batch3
        {--out= : Empty existing directory outside this repository}';

    protected $description = 'Prepare a frozen, read-only V1 batch 3 candidate manifest. Writes nothing to the database.';

    public function handle(HistoricalAttributionBatch3Preparer $preparer): int
    {
        $out = $this->option('out');
        if (! is_string($out) || trim($out) === '') {
            $this->error('Pass --out with an empty directory outside the repository.');

            return self::FAILURE;
        }

        try {
            $result = $preparer->prepare(trim($out), $this->expectedClass8(), $this->expectedHold());
        } catch (Throwable $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }

        $this->line('Mode: prepare');
        $this->line('Class 8: '.$result['class8']);
        $this->line('Held: '.$result['hold']);
        $this->line('Eligible: '.$result['eligible']);
        $this->line('Groups: '.$result['groups']);
        $this->line('Manifest: '.$result['manifest']);
        $this->line('No reservation or audit row was written.');

        return self::SUCCESS;
    }

    protected function expectedClass8(): int
    {
        return HistoricalAttributionBatch3Catalog::EXPECTED_CLASS8;
    }

    protected function expectedHold(): int
    {
        return HistoricalAttributionBatch3Catalog::EXPECTED_HOLD;
    }
}
