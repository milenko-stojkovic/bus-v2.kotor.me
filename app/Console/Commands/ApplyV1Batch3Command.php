<?php

namespace App\Console\Commands;

use App\Models\Admin;
use App\Services\Reservation\HistoricalAttributionBatch3Catalog;
use App\Services\Reservation\HistoricalAttributionBatch3Writer;
use Illuminate\Console\Command;
use RuntimeException;
use Throwable;

/**
 * Preflight or apply one approved Batch 3 file. A normal invocation does not write.
 * Not scheduled. Execution is limited to the approved CSV and its sidecar.
 */
class ApplyV1Batch3Command extends Command
{
    protected $signature = 'reservations:apply-v1-batch3
        {csv : Approved CSV with its .approval.json sidecar}
        {--report= : Outcome CSV written outside this repository}
        {--execute : Attribute or reverse. Refused unless --confirm matches the approval.}
        {--confirm= : APPLY-V1-BATCH-3, APPLY-V1-BATCH-3-REVIEW, ROLLBACK-V1-BATCH-3, or ROLLBACK-V1-BATCH-3-REVIEW}
        {--admin-id= : admins.id with admin_access, and the admin recorded on the approval}
        {--limit= : Maximum new attributions or reversals for this invocation, from 1 to 50}
        {--attempts= : Maximum identity-map reads for a write, from 1 to 50. Defaults to --limit}
        {--rollback : Reverse active Batch 3 audits whose reservation ids are in this approved CSV}';

    protected $description = 'Preflight or apply one approved V1 batch 3 file. Does not write unless --execute and the matching confirm value are both present.';

    public function handle(HistoricalAttributionBatch3Writer $writer): int
    {
        $report = $this->option('report');
        $csv = $this->argument('csv');
        if (! is_string($report) || trim($report) === '' || ! HistoricalAttributionBatch3Catalog::fileOutsideRepository(trim($report))) {
            $this->error('The report path must be a new file in an existing directory outside the repository.');

            return self::FAILURE;
        }
        $report = trim($report);
        if (! is_string($csv) || ! is_file($csv)) {
            $this->error('The candidate CSV was not found.');

            return self::FAILURE;
        }
        if ($this->sameFile($csv, $report)) {
            $this->error('The report path must not be the candidate CSV.');

            return self::FAILURE;
        }

        try {
            $limit = $this->boundedOption('limit');
            $attempts = $this->boundedOption('attempts');
        } catch (RuntimeException $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }

        if ((bool) $this->option('rollback')) {
            return $this->runRollback($writer, $csv, $report, $limit);
        }
        if ((bool) $this->option('execute')) {
            return $this->runExecute($writer, $csv, $report, $limit, $attempts ?? $limit);
        }

        return $this->runPreflight($writer, $csv, $report);
    }

    private function runPreflight(HistoricalAttributionBatch3Writer $writer, string $csv, string $report): int
    {
        try {
            $result = $writer->preflight($csv);
            $this->writeReport($report, HistoricalAttributionBatch3Catalog::REPORT_HEADER, $result['rows']);
        } catch (Throwable $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }

        $this->line('Mode: preflight');
        $this->line('Accepted: '.$result['accepted']);
        $this->line('Rejected: '.$result['rejected']);
        $this->line('Already done: '.$result['already_done']);
        $this->line('Manual review: '.$result['manual_review']);
        $this->line('No reservation or audit row was written.');
        $this->line('Report: '.$report);

        return self::SUCCESS;
    }

    private function runExecute(HistoricalAttributionBatch3Writer $writer, string $csv, string $report, ?int $limit, ?int $attempts): int
    {
        if ($limit === null || $attempts === null) {
            $this->error('Refusing to write. Pass --limit from 1 to '.HistoricalAttributionBatch3Catalog::MAX_LIMIT.'.');

            return self::FAILURE;
        }
        $actor = $this->actor();
        $confirm = $this->option('confirm');
        if ($actor === null || ! is_string($confirm)) {
            $this->error('Refusing to write. Pass --confirm and --admin-id for an existing admin with admin_access.');

            return self::FAILURE;
        }

        try {
            $result = $writer->execute($csv, $actor, $confirm, $limit, $attempts);
            $this->writeReport($report, HistoricalAttributionBatch3Catalog::REPORT_HEADER, $result['rows']);
        } catch (Throwable $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }

        $this->line('Mode: execute');
        $this->line('Group: '.$result['group_id']);
        $this->line('Evidence: '.$result['evidence']);
        $this->line('Limit: '.$limit);
        $this->line('Attempt limit: '.$result['attempt_limit']);
        $this->line('Attempts: '.$result['attempts']);
        $this->line('Wrote: '.$result['wrote']);
        $this->line('Rejected: '.$result['rejected']);
        $this->line('Already done: '.$result['already_done']);
        $this->line('Manual review: '.$result['manual_review']);
        $this->line('Beyond limit: '.$result['beyond_limit']);
        $this->line('Beyond attempts: '.$result['beyond_attempts']);
        if ($result['stopped']) {
            $this->error('Stopped ('.$result['stop_reason'].'). Earlier rows in this invocation stay written. This row was not written.');
        }
        $this->line('Report: '.$report);

        return $result['stopped'] ? self::FAILURE : self::SUCCESS;
    }

    private function runRollback(HistoricalAttributionBatch3Writer $writer, string $csv, string $report, ?int $limit): int
    {
        if (! (bool) $this->option('execute')) {
            try {
                $preview = $writer->previewRollback($csv);
                $this->writeRollbackReport($report, $preview['rows']);
            } catch (Throwable $e) {
                $this->error($e->getMessage());

                return self::FAILURE;
            }
            $this->line('Mode: rollback-preflight');
            $this->line('Reversible: '.$preview['reversible']);
            $this->line('Reversed: 0');
            $this->line('Audits for reservations absent from this CSV are not reversed.');
            $this->line('No reservation or audit row was written.');
            $this->line('Report: '.$report);

            return self::SUCCESS;
        }
        if ($limit === null) {
            $this->error('Refusing to write. Pass --limit from 1 to '.HistoricalAttributionBatch3Catalog::MAX_LIMIT.'.');

            return self::FAILURE;
        }
        $actor = $this->actor();
        $confirm = $this->option('confirm');
        if ($actor === null || ! is_string($confirm)) {
            $this->error('Refusing to write. Pass --confirm and --admin-id for an existing admin with admin_access.');

            return self::FAILURE;
        }

        try {
            $result = $writer->rollback($actor, $csv, $confirm, $limit);
            $this->writeRollbackReport($report, $result['rows']);
        } catch (Throwable $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }

        $this->line('Mode: rollback');
        $this->line('Reversible: '.$result['reversible']);
        $this->line('Reversed: '.$result['reversed']);
        $this->line('Audits for reservations absent from this CSV are not reversed.');
        if ($result['stopped']) {
            $this->error('Rollback stopped ('.$result['stop_reason'].').');
        }
        $this->line('Report: '.$report);

        return $result['stopped'] ? self::FAILURE : self::SUCCESS;
    }

    /**
     * @param  list<string>  $header
     * @param  list<array<string, string>>  $rows
     */
    protected function writeReport(string $path, array $header, array $rows): void
    {
        $handle = fopen($path, 'xb');
        if ($handle === false) {
            throw new RuntimeException('The report file could not be created.');
        }
        try {
            $this->writeReportRows($handle, $header, $rows);
        } catch (Throwable $e) {
            fclose($handle);
            if (is_file($path)) {
                unlink($path);
            }
            throw $e;
        }
        fclose($handle);
    }

    /**
     * @param  resource  $handle
     * @param  list<string>  $header
     * @param  list<array<string, string>>  $rows
     */
    protected function writeReportRows($handle, array $header, array $rows): void
    {
        if (fputcsv($handle, $header, ',', '"', '\\', "\n") === false) {
            throw new RuntimeException('The report file could not be written.');
        }
        foreach ($rows as $row) {
            $line = [];
            foreach ($header as $column) {
                $line[] = (string) ($row[$column] ?? '');
            }
            if (fputcsv($handle, $line, ',', '"', '\\', "\n") === false) {
                throw new RuntimeException('The report file could not be written.');
            }
        }
    }

    /**
     * @param  list<array{reservation_id: string, outcome: string, reason: string}>  $rows
     */
    private function writeRollbackReport(string $path, array $rows): void
    {
        $this->writeReport($path, ['reservation_id', 'outcome', 'reason'], $rows);
    }

    private function boundedOption(string $name): ?int
    {
        $value = $this->option($name);
        if ($value === null || $value === false || $value === '') {
            return null;
        }
        if ((! is_string($value) && ! is_int($value)) || preg_match('/^[1-9][0-9]*$/', (string) $value) !== 1) {
            throw new RuntimeException('Pass --'.$name.' as an integer from 1 to '.HistoricalAttributionBatch3Catalog::MAX_LIMIT.'.');
        }
        $number = (int) $value;
        if ($number > HistoricalAttributionBatch3Catalog::MAX_LIMIT) {
            throw new RuntimeException('Pass --'.$name.' as an integer from 1 to '.HistoricalAttributionBatch3Catalog::MAX_LIMIT.'.');
        }

        return $number;
    }

    private function actor(): ?Admin
    {
        $adminId = $this->option('admin-id');
        if ((! is_string($adminId) && ! is_int($adminId)) || preg_match('/^[1-9][0-9]*$/', (string) $adminId) !== 1) {
            return null;
        }

        return Admin::query()->whereKey((int) $adminId)->where('admin_access', true)->first();
    }

    private function sameFile(string $csv, string $report): bool
    {
        $csvReal = realpath($csv);
        $directory = realpath(dirname($report));
        if ($csvReal === false || $directory === false) {
            return false;
        }
        $left = rtrim(str_replace('\\', '/', strtolower($csvReal)), '/');
        $right = rtrim(str_replace('\\', '/', strtolower($directory.'/'.basename($report))), '/');

        return $left === $right;
    }
}
