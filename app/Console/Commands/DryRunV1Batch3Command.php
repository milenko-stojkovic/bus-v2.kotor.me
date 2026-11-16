<?php

namespace App\Console\Commands;

use App\Services\Reservation\HistoricalAttributionBatch3DryRun;
use Illuminate\Console\Command;
use RuntimeException;
use Throwable;

/**
 * Read-only Batch 3 name heuristic. Not scheduled. It has no write mode.
 * Class name_unique_email_unregistered is not approval to attribute.
 */
class DryRunV1Batch3Command extends Command
{
    protected $signature = 'reservations:dry-run-v1-batch3
        {--report= : Optional CSV of class assignments, outside this repository}';

    protected $description = 'Read-only Batch 3 name heuristic. Writes nothing to the database.';

    public function handle(HistoricalAttributionBatch3DryRun $dryRun): int
    {
        $reportPath = $this->option('report');
        $reportPath = is_string($reportPath) ? trim($reportPath) : '';
        if ($reportPath !== '' && ! $this->reportPathAllowed($reportPath)) {
            return self::FAILURE;
        }

        try {
            $result = $this->classification($dryRun);
        } catch (RuntimeException $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        } catch (Throwable $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }

        $this->line('V1 Batch 3 name heuristic, read-only.');
        $this->line('Plate ownership is not used.');
        $this->line('No reservation or audit row was written.');
        $this->line('Class name_unique_email_unregistered is not approved for attribution.');
        $this->line('Cohort: '.$result['cohort']);
        $this->line('user_id set: '.$result['user_id_set']);
        $this->line('user_id null: '.$result['user_id_null']);
        foreach (HistoricalAttributionBatch3DryRun::CLASSES as $class) {
            $this->line($class.': '.$result['counts'][$class]);
        }
        $this->line('Class sum: '.$result['class_sum']);
        $this->line('Class 8 distinct proposed agencies: '.$result['class8_agencies']);
        $this->line('Class 8 by proposed agency, not an attribution list:');
        foreach ($result['class8_by_agency'] as $agency) {
            $this->line('Agency '.$agency['user_id'].': reservations '.$agency['reservations'].', distinct emails '.$agency['distinct_emails']);
        }
        $this->line('Class 9 email owned by one other user: '.$result['class9_parts']['email_unique_other_user']);
        $this->line('Class 9 email owned by the name owner and other users: '.$result['class9_parts']['email_includes_name_owner']);
        $this->line('Class 9 email owned by several other users: '.$result['class9_parts']['email_multiple_other_users']);
        foreach (HistoricalAttributionBatch3DryRun::EMAIL_SUBCOUNT_CLASSES as $class) {
            foreach (HistoricalAttributionBatch3DryRun::EMAIL_SHAPES as $shape) {
                $this->line($class.' email '.$shape.': '.$result['email_subcounts'][$class][$shape]);
            }
        }
        $this->line('Class 8 country comparable: '.$result['class8_country_comparable']);
        $this->line('Class 8 country mismatch: '.$result['class8_country_mismatch']);
        $this->line('Class 8 country not comparable: '.$result['class8_country_not_comparable']);
        $this->line('Class 8 gmail-dot alias of a registered email: '.$result['class8_gmail_dot_alias']);
        $this->line('Class 8 plus-tag alias of a registered email: '.$result['class8_plus_alias']);
        $this->line('Class 8 unregistered emails shared by multiple proposed agencies: '.$result['class8_shared_emails']);
        $this->line('Class 8 reservations using those shared emails: '.$result['class8_shared_email_reservations']);
        $this->line('Country, alias, and shared-email figures are diagnostics. They do not change class membership.');
        $this->line('Class sum equals cohort: '.$this->yesNo($result['class_sum'] === $result['cohort']));
        $this->line('Already attributed equals user_id set: '.$this->yesNo($result['counts']['already_attributed'] === $result['user_id_set']));
        $this->line('Other classes equal user_id null: '.$this->yesNo(($result['class_sum'] - $result['counts']['already_attributed']) === $result['user_id_null']));
        $this->line('Class 9 parts equal class 9: '.$this->yesNo(array_sum($result['class9_parts']) === $result['counts']['name_unique_email_other']));
        foreach (HistoricalAttributionBatch3DryRun::EMAIL_SUBCOUNT_CLASSES as $class) {
            $this->line($class.' email parts equal '.$class.': '.$this->yesNo(array_sum($result['email_subcounts'][$class]) === $result['counts'][$class]));
        }
        $this->line('Class 8 country parts equal class 8: '.$this->yesNo(
            ($result['class8_country_comparable'] + $result['class8_country_not_comparable']) === $result['counts']['name_unique_email_unregistered']
        ));
        $this->line('Class 8 country mismatches are within comparable rows: '.$this->yesNo(
            $result['class8_country_mismatch'] <= $result['class8_country_comparable']
        ));
        $this->line('Reconciliation: '.($result['reconciliation_passed'] ? 'passed' : 'failed'));

        if (! $result['reconciliation_passed']) {
            if ($reportPath !== '') {
                $this->error('Reconciliation failed. No report file was written.');
            }

            return self::FAILURE;
        }

        if ($reportPath !== '') {
            try {
                $this->writeReport($reportPath, $result['rows']);
            } catch (RuntimeException $e) {
                $this->error($e->getMessage());

                return self::FAILURE;
            }
            $this->line('Report file: '.$reportPath);
            $this->line('The report is a classification, not an attribution file.');
        }

        return self::SUCCESS;
    }

    /**
     * @return array<string, mixed>
     */
    protected function classification(HistoricalAttributionBatch3DryRun $dryRun): array
    {
        return $dryRun->classify();
    }

    /**
     * @param  list<array{reservation_id: int, class: string, name_owner_user_id: ?int}>  $rows
     */
    private function writeReport(string $path, array $rows): void
    {
        $handle = fopen($path, 'xb');
        if ($handle === false) {
            throw new RuntimeException('The report file could not be created.');
        }

        $failed = null;
        try {
            $this->writeReportRows($handle, $rows);
        } catch (Throwable $e) {
            $failed = $e;
        } finally {
            fclose($handle);
        }

        if ($failed === null) {
            return;
        }

        if (is_file($path)) {
            unlink($path);
        }

        if ($failed instanceof RuntimeException) {
            throw $failed;
        }

        throw new RuntimeException('The report file could not be written.', 0, $failed);
    }

    /**
     * @param  resource  $handle
     * @param  list<array{reservation_id: int, class: string, name_owner_user_id: ?int}>  $rows
     */
    protected function writeReportRows($handle, array $rows): void
    {
        $this->writeCsvRow($handle, ['reservation_id', 'class', 'name_owner_user_id']);
        foreach ($rows as $row) {
            $this->writeCsvRow($handle, [
                (string) $row['reservation_id'],
                $row['class'],
                $row['name_owner_user_id'] === null ? '' : (string) $row['name_owner_user_id'],
            ]);
        }
    }

    /**
     * @param  resource  $handle
     * @param  list<string>  $fields
     */
    private function writeCsvRow($handle, array $fields): void
    {
        if (fputcsv($handle, $fields) === false) {
            throw new RuntimeException('The report file could not be written.');
        }
    }

    private function yesNo(bool $passed): string
    {
        return $passed ? 'yes' : 'no';
    }

    private function reportPathAllowed(string $path): bool
    {
        if (is_file($path)) {
            $this->error('The report file already exists and was not overwritten.');

            return false;
        }

        $directory = dirname($path);
        $realDirectory = realpath($directory);
        $root = realpath(base_path());
        if ($realDirectory === false || $root === false || is_dir($path)) {
            $this->error('The report path must be a new file in an existing directory outside the repository.');

            return false;
        }

        $realDirectory = rtrim(str_replace('\\', '/', strtolower($realDirectory)), '/');
        $root = rtrim(str_replace('\\', '/', strtolower($root)), '/');
        if ($realDirectory === $root || str_starts_with($realDirectory, $root.'/')) {
            $this->error('The report path must be a new file in an existing directory outside the repository.');

            return false;
        }

        return true;
    }
}
