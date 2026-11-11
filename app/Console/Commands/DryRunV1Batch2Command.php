<?php

namespace App\Console\Commands;

use App\Services\Reservation\HistoricalAttributionBatch2DryRun;
use Illuminate\Console\Command;
use RuntimeException;
use Throwable;

/**
 * Read-only discovery of V1 reservations whose email and agency name
 * identify the same account. Not scheduled. It has no write mode.
 */
class DryRunV1Batch2Command extends Command
{
    protected $signature = 'reservations:dry-run-v1-batch2
        {--candidates= : Optional CSV of identity-candidate ids, outside this repository}';

    protected $description = 'Read-only classification of unattributed V1 reservations. Writes nothing to the database.';

    public function handle(HistoricalAttributionBatch2DryRun $dryRun): int
    {
        $candidatesPath = $this->option('candidates');
        $candidatesPath = is_string($candidatesPath) ? trim($candidatesPath) : '';
        if ($candidatesPath !== '' && ! $this->candidatesPathAllowed($candidatesPath)) {
            return self::FAILURE;
        }

        try {
            $result = $dryRun->classify();
            if ($candidatesPath !== '') {
                $this->writeCandidates($candidatesPath, $result['candidates']);
            }
        } catch (RuntimeException $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        } catch (Throwable $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }

        $this->line('Scanned V1 NULL-user reservations: '.$result['scanned']);
        $this->line('Excluded active attribution audit: '.$result['excluded_active_audit']);
        $this->line('Eligible: '.$result['eligible']);
        foreach (HistoricalAttributionBatch2DryRun::CATEGORIES as $category) {
            $this->line($category.': '.$result['counts'][$category]);
        }
        $this->line('Category sum: '.$result['category_sum']);
        $this->line('Identity candidates: '.$result['identity_candidates']);
        $this->line('Distinct candidate agencies: '.$result['distinct_agencies']);
        foreach (HistoricalAttributionBatch2DryRun::IDENTITY_CATEGORIES as $category) {
            $this->line('Distinct agencies '.$category.': '.$result['agencies_by_category'][$category]);
        }
        $this->line('A1 other agency: '.$result['a1_other_agency']);
        $this->line('A1 extra null owner: '.$result['a1_extra_null_owner']);
        if ($candidatesPath !== '') {
            $this->line('Candidates file: '.$candidatesPath);
        }
        $this->line('No reservation or audit row was written.');
        $this->line('Identity candidates are not approved for attribution.');

        return self::SUCCESS;
    }

    /**
     * @param  list<array{reservation_id: int, proposed_user_id: int, plate_category: string}>  $candidates
     */
    private function writeCandidates(string $path, array $candidates): void
    {
        $handle = fopen($path, 'xb');
        if ($handle === false) {
            throw new RuntimeException('The candidates file could not be created.');
        }

        try {
            fputcsv($handle, ['reservation_id', 'proposed_user_id', 'plate_category']);
            foreach ($candidates as $candidate) {
                fputcsv($handle, [
                    (string) $candidate['reservation_id'],
                    (string) $candidate['proposed_user_id'],
                    $candidate['plate_category'],
                ]);
            }
        } finally {
            fclose($handle);
        }
    }

    private function candidatesPathAllowed(string $path): bool
    {
        if (is_file($path)) {
            $this->error('The candidates file already exists and was not overwritten.');

            return false;
        }

        $directory = dirname($path);
        $realDirectory = realpath($directory);
        $root = realpath(base_path());
        if ($realDirectory === false || $root === false || is_dir($path)) {
            $this->error('The candidates path must be a new file in an existing directory outside the repository.');

            return false;
        }

        $realDirectory = rtrim(str_replace('\\', '/', strtolower($realDirectory)), '/');
        $root = rtrim(str_replace('\\', '/', strtolower($root)), '/');
        if ($realDirectory === $root || str_starts_with($realDirectory, $root.'/')) {
            $this->error('The candidates path must be a new file in an existing directory outside the repository.');

            return false;
        }

        return true;
    }
}
