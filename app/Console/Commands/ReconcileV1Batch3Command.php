<?php

namespace App\Console\Commands;

use App\Services\Reservation\HistoricalAttributionBatch3Catalog;
use App\Services\Reservation\HistoricalAttributionBatch3Reconcile;
use Illuminate\Console\Command;
use RuntimeException;
use Throwable;

/**
 * Compare active Batch 3 audits with approved reservation ids. Not scheduled.
 * Writes nothing to the database.
 */
class ReconcileV1Batch3Command extends Command
{
    protected $signature = 'reservations:reconcile-v1-batch3
        {--manifest= : Frozen manifest.json}
        {--approved-dir= : Directory of approved CSV files and sidecars. Defaults to approved/ beside the manifest}
        {--report= : Optional reconciliation CSV outside this repository}';

    protected $description = 'Read-only Batch 3 reconciliation by reservation id.';

    public function handle(HistoricalAttributionBatch3Reconcile $reconcile): int
    {
        $manifest = $this->option('manifest');
        if (! is_string($manifest) || trim($manifest) === '' || ! is_file(trim($manifest))) {
            $this->error('Pass --manifest with the frozen manifest.json.');

            return self::FAILURE;
        }
        $manifest = trim($manifest);
        $approved = $this->option('approved-dir');
        if (! is_string($approved) || trim($approved) === '') {
            $approved = dirname($manifest).DIRECTORY_SEPARATOR.'approved';
        } else {
            $approved = trim($approved);
        }
        $report = $this->option('report');
        if (is_string($report) && trim($report) !== '' && ! HistoricalAttributionBatch3Catalog::fileOutsideRepository(trim($report))) {
            $this->error('The report path must be a new file in an existing directory outside the repository.');

            return self::FAILURE;
        }

        try {
            $result = $reconcile->compare($manifest, $approved);
            if (is_string($report) && trim($report) !== '') {
                $this->writeReport(trim($report), $result['problems'], $result['pending']);
            }
        } catch (Throwable $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }

        $this->line('Mode: reconcile');
        $this->line('Frozen class 8: '.$result['frozen_class8']);
        $this->line('Frozen held: '.$result['frozen_hold']);
        $this->line('Frozen eligible: '.$result['frozen_eligible']);
        $this->line('Approved: '.$result['approved']);
        $this->line('Active Batch 3: '.$result['active_batch3']);
        $this->line('Pending: '.count($result['pending']));
        $this->line('Problems: '.count($result['problems']));
        $this->line('Batch 1 active: '.$result['batch1_active']);
        $this->line('Batch 2 active: '.$result['batch2_active']);
        foreach ($result['problems'] as $problem) {
            $this->line($problem['problem'].' reservation '.$problem['reservation_id']);
        }
        $this->line('No reservation or audit row was written.');
        if (! $result['agreed']) {
            $this->error('Reconciliation found a reservation-id mismatch.');

            return self::FAILURE;
        }

        return self::SUCCESS;
    }

    /**
     * @param  list<array{reservation_id: string, problem: string, evidence: string, agency_user_id: string}>  $problems
     * @param  list<int>  $pending
     */
    private function writeReport(string $path, array $problems, array $pending): void
    {
        $handle = fopen($path, 'xb');
        if ($handle === false) {
            throw new RuntimeException('The report file could not be created.');
        }
        try {
            fputcsv($handle, ['reservation_id', 'problem', 'evidence', 'agency_user_id'], ',', '"', '\\', "\n");
            foreach ($problems as $problem) {
                fputcsv($handle, [$problem['reservation_id'], $problem['problem'], $problem['evidence'], $problem['agency_user_id']], ',', '"', '\\', "\n");
            }
            foreach ($pending as $reservationId) {
                fputcsv($handle, [(string) $reservationId, 'pending', '', ''], ',', '"', '\\', "\n");
            }
        } catch (Throwable $e) {
            fclose($handle);
            if (is_file($path)) {
                unlink($path);
            }
            throw $e;
        }
        fclose($handle);
    }
}
