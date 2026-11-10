<?php

namespace App\Console\Commands;

use App\Models\Admin;
use App\Services\Reservation\HistoricalAttributionBatchWriter;
use Illuminate\Console\Command;
use RuntimeException;
use Throwable;

/**
 * Preflight or apply the approved V1 batch through HistoricalReservationAttributionService.
 * Not scheduled. A normal invocation does not write.
 */
class ApplyV1AttributionCommand extends Command
{
    protected $signature = 'reservations:apply-v1-attribution
        {csv? : Candidate CSV. Required unless --rollback is set.}
        {--report= : Report CSV written outside this repository}
        {--execute : Perform writes. Refused unless --confirm matches exactly.}
        {--confirm= : APPLY-V1-BATCH-1 to attribute, or ROLLBACK-V1-BATCH-1 to reverse this batch}
        {--admin-id= : admins.id with admin_access, stored as the actor}
        {--rollback : Reverse only active rows whose evidence is this batch}
        {--limit= : Maximum attribution writes for this invocation. The full CSV is still validated.}';

    protected $description = 'Preflight or apply approved V1 batch 1 attribution. Does not write unless --execute and the exact confirm value are both present.';

    public function handle(HistoricalAttributionBatchWriter $writer): int
    {
        $report = $this->option('report');
        if (! is_string($report) || trim($report) === '') {
            $this->error('Pass --report with a file path outside the repository.');

            return self::FAILURE;
        }
        if ($this->reportIsInsideRepository($report)) {
            $this->error('The report path must be an existing directory outside the repository.');

            return self::FAILURE;
        }

        $execute = (bool) $this->option('execute');
        $rollback = (bool) $this->option('rollback');

        $csv = $this->argument('csv');
        if (! $rollback) {
            if (! is_string($csv) || $csv === '' || ! is_file($csv)) {
                $this->error('The candidate CSV was not found.');

                return self::FAILURE;
            }
            if ($this->sameFile($csv, $report)) {
                $this->error('The report path must not be the candidate CSV.');

                return self::FAILURE;
            }
        }

        if ($rollback) {
            return $this->runRollback($writer, $report, $execute);
        }

        try {
            $limit = $this->writeLimit();
        } catch (RuntimeException $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }

        return $this->runApply($writer, (string) $csv, $report, $execute, $limit);
    }

    private function runApply(HistoricalAttributionBatchWriter $writer, string $csv, string $report, bool $execute, ?int $limit): int
    {
        if ($execute && ! $this->executionIsAuthorized(HistoricalAttributionBatchWriter::CONFIRM_APPLY)) {
            return self::FAILURE;
        }

        try {
            $result = $execute
                ? $writer->execute($csv, $this->actor(), $limit)
                : $writer->preflight($csv);
            $this->writeApplyReport($report, $result);
        } catch (RuntimeException $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        } catch (Throwable $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }

        $this->line($execute ? 'Mode: execute' : 'Mode: preflight');
        $this->line('Candidates: '.$result['total']);
        $this->line('Ready: '.count($result['ready']));
        $this->line('Already done for this batch: '.count($result['already_done']));
        $this->line('Blocking: '.count($result['blocking']));
        $this->line('Wrote: '.$result['wrote']);
        $this->line('Remaining: '.$result['remaining']);
        $this->line('Complete: '.($result['complete'] ? 'yes' : 'no'));
        if ($result['limited']) {
            $this->line('Limit reached. Ready rows remain for a later invocation.');
        }
        if ($result['stopped']) {
            $this->error('Stopped at reservation '.$result['stop_reservation_id'].' ('.$result['stop_reason'].').');
            $this->line('No reservation or audit row was written after the stop.');

            return self::FAILURE;
        }
        if (! $result['can_write']) {
            $this->error('Preflight found a difference from the approved batch. No attribution was written.');

            return self::FAILURE;
        }
        if (! $execute) {
            $this->line('No reservation or audit row was written.');
        }
        $this->line('Report: '.$report);

        return self::SUCCESS;
    }

    private function runRollback(HistoricalAttributionBatchWriter $writer, string $report, bool $execute): int
    {
        if (! $execute) {
            $this->line('Mode: rollback-preflight');
            $this->line('Rollback was not executed. Pass --execute and --confirm='.HistoricalAttributionBatchWriter::CONFIRM_ROLLBACK.' to reverse this batch only.');

            return self::SUCCESS;
        }
        if (! $this->executionIsAuthorized(HistoricalAttributionBatchWriter::CONFIRM_ROLLBACK)) {
            return self::FAILURE;
        }

        try {
            $result = $writer->rollback($this->actor());
            $this->writeRollbackReport($report, $result);
        } catch (Throwable $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }

        $this->line('Mode: rollback');
        $this->line('Reversed: '.$result['reversed']);
        $this->line('Report: '.$report);
        if ($result['stopped']) {
            $this->error('Rollback stopped at reservation '.$result['stop_reservation_id'].' ('.$result['stop_reason'].').');

            return self::FAILURE;
        }

        return self::SUCCESS;
    }

    private function executionIsAuthorized(string $expectedConfirm): bool
    {
        $confirm = $this->option('confirm');
        if (! is_string($confirm) || $confirm !== $expectedConfirm) {
            $this->error('Refusing to write. Pass --confirm='.$expectedConfirm.' exactly.');

            return false;
        }
        if ($this->actorOrNull() === null) {
            $this->error('Refusing to write. Pass --admin-id for an existing admin with admin_access.');

            return false;
        }

        return true;
    }

    private function writeLimit(): ?int
    {
        $limit = $this->option('limit');
        if ($limit === null || $limit === false || $limit === '') {
            return null;
        }
        if (! is_string($limit) && ! is_int($limit)) {
            throw new RuntimeException('Pass --limit as a positive integer.');
        }
        if (preg_match('/^[1-9][0-9]*$/', (string) $limit) !== 1) {
            throw new RuntimeException('Pass --limit as a positive integer.');
        }

        return (int) $limit;
    }

    private function actor(): Admin
    {
        $actor = $this->actorOrNull();
        if ($actor === null) {
            throw new RuntimeException('Refusing to write. Pass --admin-id for an existing admin with admin_access.');
        }

        return $actor;
    }

    private function actorOrNull(): ?Admin
    {
        $adminId = $this->option('admin-id');
        if (! is_string($adminId) && ! is_int($adminId)) {
            return null;
        }
        if (preg_match('/^[1-9][0-9]*$/', (string) $adminId) !== 1) {
            return null;
        }

        return Admin::query()
            ->whereKey((int) $adminId)
            ->where('admin_access', true)
            ->first();
    }

    /**
     * @param  array{
     *     ready: list<array{reservation_id: string, proposed_user_id: string}>,
     *     already_done: list<array{reservation_id: string, proposed_user_id: string, reason: string}>,
     *     blocking: list<array{reservation_id: string, proposed_user_id: string, reason: string}>,
     *     wrote: int,
     *     stopped: bool,
     *     stop_reservation_id: ?string,
     *     stop_reason: ?string
     * }  $result
     */
    private function writeApplyReport(string $path, array $result): void
    {
        $handle = $this->openReport($path);
        try {
            fputcsv($handle, ['reservation_id', 'proposed_user_id', 'outcome', 'reason']);
            foreach ($result['blocking'] as $row) {
                fputcsv($handle, [$row['reservation_id'], $row['proposed_user_id'], 'blocking', $row['reason']]);
            }
            foreach ($result['already_done'] as $row) {
                fputcsv($handle, [$row['reservation_id'], $row['proposed_user_id'], 'already_done', HistoricalAttributionBatchWriter::EVIDENCE]);
            }
            if ($result['stopped']) {
                fputcsv($handle, [$result['stop_reservation_id'], '', 'stopped', (string) $result['stop_reason']]);
            }
            if ($result['limited']) {
                fputcsv($handle, ['', '', 'limit', 'ready rows remain']);
            }
            fputcsv($handle, ['wrote', (string) $result['wrote'], $result['complete'] ? 'complete' : 'incomplete', (string) $result['remaining']]);
        } finally {
            fclose($handle);
        }
    }

    /**
     * @param  array{reversed: int, stopped: bool, stop_reservation_id: ?string, stop_reason: ?string}  $result
     */
    private function writeRollbackReport(string $path, array $result): void
    {
        $handle = $this->openReport($path);
        try {
            fputcsv($handle, ['reservation_id', 'outcome', 'reason']);
            fputcsv($handle, ['', 'reversed', (string) $result['reversed']]);
            if ($result['stopped']) {
                fputcsv($handle, [$result['stop_reservation_id'], 'stopped', (string) $result['stop_reason']]);
            }
        } finally {
            fclose($handle);
        }
    }

    /**
     * @return resource
     */
    private function openReport(string $path)
    {
        $handle = fopen($path, 'wb');
        if ($handle === false) {
            throw new RuntimeException('The report could not be written.');
        }

        return $handle;
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
