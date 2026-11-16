<?php

namespace App\Console\Commands;

use App\Services\Reservation\HistoricalAttributionBatch3Diagnostics;
use Illuminate\Console\Command;
use RuntimeException;
use Throwable;

/**
 * Read-only review of Batch 3 class 8 rows. Not scheduled.
 * Observations do not change membership and this command has no write mode.
 */
class DiagnoseV1Batch3Command extends Command
{
    protected $signature = 'reservations:diagnose-v1-batch3
        {--report= : Optional private CSV outside this repository}
        {--agency=* : Class 8 agency ids for the email breakdown. Defaults to 6 and 8}';

    protected $description = 'Read-only Batch 3 candidate diagnostics. Writes nothing to the database.';

    public function handle(HistoricalAttributionBatch3Diagnostics $diagnostics): int
    {
        $reportPath = $this->option('report');
        $reportPath = is_string($reportPath) ? trim($reportPath) : '';
        if ($reportPath !== '' && ! $this->reportPathAllowed($reportPath)) {
            return self::FAILURE;
        }

        try {
            $agencyIds = $this->agencyIds();
            $result = $this->diagnostics($diagnostics, $agencyIds);
        } catch (RuntimeException $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        } catch (Throwable $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }

        $this->line('V1 Batch 3 candidate diagnostics, read-only.');
        $this->line('No reservation or audit row was written.');
        $this->line('Observations do not remove class 8 reservations.');
        $this->line('Current class 8: '.$result['class8']);
        $this->line('Class 8 rows loaded: '.$result['loaded']);
        foreach (HistoricalAttributionBatch3Diagnostics::CATEGORY_E_NOTE as $line) {
            $this->line($line);
        }
        $this->line('Shared unregistered emails: '.count($result['shared_emails']));
        foreach ($result['shared_emails'] as $email) {
            $agencies = [];
            foreach ($email['agencies'] as $agency) {
                $agencies[] = 'agency '.$agency['agency_id'].' reservations '.$agency['reservations'];
            }
            $this->line($email['label'].': '.implode(', ', $agencies).', reservations '.$email['reservations']);
        }
        $this->line('Country mismatches: '.count($result['country_mismatches']));
        foreach ($result['country_mismatches'] as $mismatch) {
            $this->line(
                'Country mismatch reservation '.$mismatch['reservation_id']
                .' agency '.$mismatch['agency_id']
                .': reservation '.$mismatch['reservation_country']
                .', agency '.$mismatch['agency_country']
            );
        }
        foreach ($result['agencies'] as $agency) {
            $range = $agency['date_min'] === '' ? 'none' : $agency['date_min'].' to '.$agency['date_max'];
            $this->line(
                'Agency '.$agency['agency_id']
                .': reservations '.$agency['reservations']
                .', distinct emails '.$agency['distinct_emails']
                .', largest email group '.$agency['largest_email_group']
                .', reservation_date '.$range
            );
        }
        $this->line('Name observations: '.count($result['name_observations']));
        $this->line('short_name means the normalized agency name is 1 to 4 characters. single_token means it contains no space. no_letters means it has no letters. These flags do not change eligibility.');
        $this->line('A name with no flag is not certified as specific. A name of more than one word is not flagged.');
        foreach ($result['name_observations'] as $observation) {
            $this->line(
                'Name observation agency '.$observation['agency_id']
                .': '.$observation['observation']
                .', reservations '.$observation['reservations']
            );
        }
        $this->line('Per-email addresses and normalized names are omitted here.');
        $this->line('Reconciliation: '.($result['reconciliation_passed'] ? 'passed' : 'failed'));
        $this->line('Diagnostic recount agrees with the classifier: '.($result['recount_agrees'] ? 'yes' : 'no'));

        if (! $result['reconciliation_passed'] || ! $result['recount_agrees']) {
            if ($reportPath !== '') {
                $this->error('Diagnostics did not agree. No report file was written.');
            }

            return self::FAILURE;
        }

        if ($reportPath !== '') {
            try {
                $this->writeReport($reportPath, $result);
            } catch (RuntimeException $e) {
                $this->error($e->getMessage());

                return self::FAILURE;
            }
            $this->line('Report file: '.$reportPath);
            $this->line('The report is a diagnostic file, not an attribution file.');
        }

        return self::SUCCESS;
    }

    /**
     * @param  list<int>  $agencyIds
     * @return array<string, mixed>
     */
    protected function diagnostics(HistoricalAttributionBatch3Diagnostics $diagnostics, array $agencyIds): array
    {
        return $diagnostics->collect($agencyIds);
    }

    /**
     * @return list<int>
     */
    private function agencyIds(): array
    {
        $option = $this->option('agency');
        $values = is_array($option) && $option !== [] ? $option : ['6', '8'];
        $ids = [];
        foreach ($values as $value) {
            if (preg_match('/^[1-9][0-9]*$/', (string) $value) !== 1) {
                throw new RuntimeException('Each agency id must be a positive integer.');
            }
            $ids[] = (int) $value;
        }

        return array_values(array_unique($ids));
    }

    /**
     * @param  array<string, mixed>  $result
     */
    private function writeReport(string $path, array $result): void
    {
        $handle = fopen($path, 'xb');
        if ($handle === false) {
            throw new RuntimeException('The report file could not be created.');
        }

        $failed = null;
        try {
            $this->writeReportRows($handle, $result);
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
     * @param  array<string, mixed>  $result
     */
    protected function writeReportRows($handle, array $result): void
    {
        $this->writeCsvRow($handle, [
            'record_type',
            'label',
            'email',
            'agency_id',
            'reservation_id',
            'reservations',
            'reservation_country',
            'agency_country',
            'reservation_date_min',
            'reservation_date_max',
            'normalized_name',
            'observation',
        ]);
        foreach ($result['shared_emails'] as $email) {
            foreach ($email['agencies'] as $agency) {
                $this->writeCsvRow($handle, [
                    'shared_email',
                    $email['label'],
                    $email['email'],
                    (string) $agency['agency_id'],
                    '',
                    (string) $agency['reservations'],
                    '',
                    '',
                    '',
                    '',
                    '',
                    '',
                ]);
            }
        }
        foreach ($result['country_mismatches'] as $mismatch) {
            $this->writeCsvRow($handle, [
                'country_mismatch',
                '',
                '',
                (string) $mismatch['agency_id'],
                (string) $mismatch['reservation_id'],
                '',
                $mismatch['reservation_country'],
                $mismatch['agency_country'],
                '',
                '',
                '',
                '',
            ]);
        }
        foreach ($result['agencies'] as $agency) {
            $this->writeCsvRow($handle, [
                'agency_summary',
                '',
                '',
                (string) $agency['agency_id'],
                '',
                (string) $agency['reservations'],
                '',
                '',
                $agency['date_min'],
                $agency['date_max'],
                $agency['normalized_name'],
                '',
            ]);
            foreach ($agency['emails'] as $email) {
                $this->writeCsvRow($handle, [
                    'agency_email',
                    '',
                    $email['email'],
                    (string) $agency['agency_id'],
                    '',
                    (string) $email['reservations'],
                    '',
                    '',
                    $email['date_min'],
                    $email['date_max'],
                    '',
                    '',
                ]);
            }
        }
        foreach ($result['name_observations'] as $observation) {
            $this->writeCsvRow($handle, [
                'name_observation',
                '',
                '',
                (string) $observation['agency_id'],
                '',
                (string) $observation['reservations'],
                '',
                '',
                '',
                '',
                $observation['normalized_name'],
                $observation['observation'],
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
