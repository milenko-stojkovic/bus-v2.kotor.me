<?php

namespace App\Console\Commands;

use App\Services\Reservation\HistoricalAttributionBatchDryRun;
use Illuminate\Console\Command;
use RuntimeException;
use Throwable;

/**
 * Read-only dry-run for one proposed historical attribution CSV.
 * Not scheduled. It does not update reservations or write audit rows.
 */
class DryRunV1AttributionCommand extends Command
{
    protected $signature = 'reservations:dry-run-v1-attribution
        {csv : Path to the candidate CSV}
        {--report= : Exclusion CSV written outside this repository}';

    protected $description = 'Read-only check of a proposed V1 attribution CSV. Writes nothing to the database.';

    public function handle(HistoricalAttributionBatchDryRun $dryRun): int
    {
        $csv = (string) $this->argument('csv');
        $report = $this->option('report');
        if (! is_string($report) || trim($report) === '') {
            $this->error('Pass --report with a file path outside the repository.');

            return self::FAILURE;
        }

        if (! is_file($csv)) {
            $this->error('The candidate CSV was not found.');

            return self::FAILURE;
        }

        if ($this->reportIsInsideRepository($report)) {
            $this->error('The report path must be an existing directory outside the repository.');

            return self::FAILURE;
        }

        if ($this->sameFile($csv, $report)) {
            $this->error('The report path must not be the candidate CSV.');

            return self::FAILURE;
        }

        try {
            $result = $dryRun->evaluate($csv);
            $this->writeReport($report, $result['exclusions']);
        } catch (RuntimeException $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        } catch (Throwable $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }

        $this->line('Candidates: '.$result['total']);
        $this->line('Accepted: '.$result['accepted']);
        $this->line('Excluded: '.$result['excluded']);
        $this->line('Agencies: '.$result['agencies']);
        $this->line('Excluded by reason:');
        if ($result['by_reason'] === []) {
            $this->line('none');
        }
        foreach ($result['by_reason'] as $reason => $count) {
            $this->line($reason.': '.$count);
        }
        $this->line('Accepted by agency:');
        if ($result['by_agency'] === []) {
            $this->line('none');
        }
        foreach ($result['by_agency'] as $agency) {
            $this->line('user_id='.$agency['user_id'].' count='.$agency['count'].' name='.$agency['name']);
        }
        $this->line('Exclusion report: '.$report);
        $this->line('No reservation or audit row was written.');

        return self::SUCCESS;
    }

    /**
     * @param  list<array{reservation_id: string, proposed_user_id: string, reason: string}>  $exclusions
     */
    private function writeReport(string $path, array $exclusions): void
    {
        $handle = fopen($path, 'wb');
        if ($handle === false) {
            throw new RuntimeException('The exclusion report could not be written.');
        }

        try {
            fputcsv($handle, ['reservation_id', 'proposed_user_id', 'reason']);
            foreach ($exclusions as $exclusion) {
                fputcsv($handle, [$exclusion['reservation_id'], $exclusion['proposed_user_id'], $exclusion['reason']]);
            }
        } finally {
            fclose($handle);
        }
    }

    private function reportIsInsideRepository(string $path): bool
    {
        $directory = dirname($path);
        $realDirectory = realpath($directory);
        $root = realpath(base_path());
        if ($realDirectory === false || $root === false) {
            return true;
        }

        $realDirectory = rtrim(str_replace('\\', '/', strtolower($realDirectory)), '/');
        $root = rtrim(str_replace('\\', '/', strtolower($root)), '/');

        return $realDirectory === $root || str_starts_with($realDirectory, $root.'/');
    }

    private function sameFile(string $csv, string $report): bool
    {
        $csvReal = realpath($csv);
        if ($csvReal === false) {
            return false;
        }

        $reportReal = realpath($report);
        if ($reportReal !== false) {
            return $this->normalizePath($csvReal) === $this->normalizePath($reportReal);
        }

        $directory = realpath(dirname($report));
        if ($directory === false) {
            return false;
        }

        return $this->normalizePath($csvReal) === $this->normalizePath($directory.'/'.basename($report));
    }

    private function normalizePath(string $path): string
    {
        return rtrim(str_replace('\\', '/', strtolower($path)), '/');
    }
}
