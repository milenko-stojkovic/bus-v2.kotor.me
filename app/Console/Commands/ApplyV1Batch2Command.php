<?php

namespace App\Console\Commands;

use App\Models\Admin;
use App\Services\Reservation\HistoricalAttributionBatch2Preflight;
use App\Services\Reservation\HistoricalAttributionBatch2Writer;
use Illuminate\Console\Command;
use RuntimeException;
use Throwable;

/**
 * Preflight or apply Batch 2 attribution. A normal invocation does not write.
 * Not scheduled. Each write rebuilds identity maps inside its own transaction.
 * That is a repeatable-read snapshot, not a serializable lock on user accounts.
 * Rollback reverses only CSV reservations in the selected category.
 */
class ApplyV1Batch2Command extends Command
{
    protected $signature = 'reservations:apply-v1-batch2
        {csv : Candidate CSV with reservation_id,proposed_user_id,plate_category}
        {--report= : Outcome CSV written outside this repository}
        {--execute : Attribute or reverse. Refused unless --confirm matches the scope.}
        {--confirm= : APPLY-V1-BATCH-2, ROLLBACK-V1-BATCH-2, or a single-category rollback confirm}
        {--admin-id= : admins.id with admin_access, stored as the actor}
        {--category= : R, A1, A2, B, C, or all. Required for a write.}
        {--limit= : Maximum new attributions or reversals for this invocation, from 1 to 50.}
        {--attempts= : Maximum identity-map reads for a write, from 1 to 50. Defaults to --limit. Already-done and closed Batch 2 rows do not use an attempt.}
        {--rollback : Reverse active Batch 2 audits whose reservation ids are in this CSV. Audits absent from the CSV are not reversed.}';

    protected $description = 'Preflight or apply V1 batch 2 attribution. Does not write unless --execute and the exact confirm value are both present.';

    public function handle(HistoricalAttributionBatch2Preflight $preflight, HistoricalAttributionBatch2Writer $writer): int
    {
        $report = $this->option('report');
        if (! is_string($report) || trim($report) === '') {
            $this->error('Pass --report with a file path outside the repository.');

            return self::FAILURE;
        }
        $report = trim($report);

        $csv = $this->argument('csv');
        if (! is_string($csv) || $csv === '' || ! is_file($csv)) {
            $this->error('The candidate CSV was not found.');

            return self::FAILURE;
        }
        if (! $this->pathIsOutsideRepository($report) || is_file($report)) {
            $this->error('The report path must be a new file in an existing directory outside the repository.');

            return self::FAILURE;
        }
        if ($this->sameFile($csv, $report)) {
            $this->error('The report path must not be the candidate CSV.');

            return self::FAILURE;
        }

        try {
            $category = $this->categoryOption();
            $limit = $this->limitOption();
            $attempts = $this->attemptsOption();
        } catch (RuntimeException $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }

        if ((bool) $this->option('rollback')) {
            return $this->runRollback($writer, (string) $csv, $report, $category, $limit);
        }
        if ((bool) $this->option('execute')) {
            return $this->runExecute($writer, (string) $csv, $report, $category, $limit, $attempts);
        }

        return $this->runPreflight($preflight, (string) $csv, $report, $category, $limit);
    }

    private function runPreflight(HistoricalAttributionBatch2Preflight $preflight, string $csv, string $report, ?string $category, ?int $limit): int
    {
        try {
            $result = $preflight->review($csv);
            $this->writeOutcomeReport($report, $result['rows']);
        } catch (RuntimeException $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        } catch (Throwable $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }

        $this->line('Mode: preflight');
        $this->line('Category: '.($category ?? 'all'));
        $this->line('Limit: '.($limit === null ? 'none' : (string) $limit));
        $this->printReview($result);
        $this->line('No reservation or audit row was written.');
        $this->line('Report: '.$report);
        if (! $result['input_valid']) {
            $this->error('The candidate CSV contains a malformed or duplicate row.');

            return self::FAILURE;
        }

        return self::SUCCESS;
    }

    private function runExecute(HistoricalAttributionBatch2Writer $writer, string $csv, string $report, ?string $category, ?int $limit, ?int $attempts): int
    {
        if ($category === null || $limit === null) {
            $this->error('Refusing to write. Pass --category and --limit. The limit must be from 1 to '.HistoricalAttributionBatch2Writer::MAX_LIMIT.'.');

            return self::FAILURE;
        }
        $attemptLimit = $attempts ?? $limit;
        if (! $this->executionIsAuthorized(HistoricalAttributionBatch2Preflight::CONFIRM_APPLY)) {
            return self::FAILURE;
        }

        try {
            $result = $writer->execute($csv, $this->actor(), $category, $limit, $attemptLimit);
            $this->writeOutcomeReport($report, $result['rows']);
        } catch (Throwable $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }

        $this->line('Mode: execute');
        $this->line('Category: '.$category);
        $this->line('Limit: '.$limit);
        $this->line('Attempt limit: '.$result['attempt_limit']);
        $this->line('Attempts: '.$result['attempts']);
        $this->line('Wrote: '.$result['wrote']);
        $this->line('Skipped: '.$result['skipped']);
        $this->line('Rejected: '.$result['rejected']);
        $this->line('Already done: '.$result['already_done']);
        $this->line('Manual review: '.$result['manual_review']);
        $this->line('Not selected: '.$result['not_selected']);
        $this->line('Beyond limit: '.$result['beyond_limit']);
        $this->line('Beyond attempts: '.$result['beyond_attempts']);
        $this->line('Input valid: '.($result['input_valid'] ? 'yes' : 'no'));
        if ($result['stopped']) {
            $this->error('Stopped ('.$result['stop_reason'].'). Earlier rows in this invocation stay written. This row was not written.');
        }
        $this->line('Report: '.$report);
        if (! $result['input_valid'] || $result['stopped']) {
            return self::FAILURE;
        }

        return self::SUCCESS;
    }

    private function runRollback(HistoricalAttributionBatch2Writer $writer, string $csv, string $report, ?string $category, ?int $limit): int
    {
        $rollbackCategory = ($category === null || $category === 'all') ? null : $category;
        $scope = $rollbackCategory ?? 'all';
        if (! (bool) $this->option('execute')) {
            try {
                $preview = $writer->previewRollback($csv, $rollbackCategory);
            } catch (Throwable $e) {
                $this->error($e->getMessage());

                return self::FAILURE;
            }
            $this->line('Mode: rollback-preflight');
            $this->line('Scope: csv');
            $this->line('Category: '.$scope);
            $this->line('Reversible: '.$preview['reversible']);
            $this->line('Reversed: 0');
            $this->line('Audits for reservations absent from this CSV are not reversed.');
            $this->line('No reservation or audit row was written.');
            if (! $preview['input_valid']) {
                $this->error('The candidate CSV contains a malformed or duplicate row.');

                return self::FAILURE;
            }

            return self::SUCCESS;
        }

        $expected = $rollbackCategory === null
            ? HistoricalAttributionBatch2Preflight::CONFIRM_ROLLBACK
            : HistoricalAttributionBatch2Preflight::CONFIRM_ROLLBACK_BY_CATEGORY[$rollbackCategory];
        if (! $this->executionIsAuthorized($expected)) {
            return self::FAILURE;
        }
        if ($limit === null) {
            $this->error('Refusing to write. Pass --limit from 1 to '.HistoricalAttributionBatch2Writer::MAX_LIMIT.'.');

            return self::FAILURE;
        }

        try {
            $result = $writer->rollback($this->actor(), $csv, $rollbackCategory, $limit);
            $this->writeRollbackReport($report, $result['rows']);
        } catch (Throwable $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }

        $this->line('Mode: rollback');
        $this->line('Scope: csv');
        $this->line('Category: '.$scope);
        $this->line('Reversible: '.$result['reversible']);
        $this->line('Reversed: '.$result['reversed']);
        $this->line('Audits for reservations absent from this CSV are not reversed.');
        $this->line('Report: '.$report);
        if (! $result['input_valid']) {
            $this->error('The candidate CSV contains a malformed or duplicate row.');

            return self::FAILURE;
        }
        if ($result['stopped']) {
            $this->error('Rollback stopped ('.$result['stop_reason'].').');

            return self::FAILURE;
        }

        return self::SUCCESS;
    }

    /**
     * @param  array{total: int, accepted: int, skipped: int, rejected: int, wrote: int, input_valid: bool, by_reason: array<string, int>}  $result
     */
    private function printReview(array $result): void
    {
        $this->line('Candidates: '.$result['total']);
        $this->line('Accepted: '.$result['accepted']);
        $this->line('Skipped: '.$result['skipped']);
        $this->line('Rejected: '.$result['rejected']);
        $this->line('Wrote: '.$result['wrote']);
        $this->line('Input valid: '.($result['input_valid'] ? 'yes' : 'no'));
        $this->line('By reason:');
        if ($result['by_reason'] === []) {
            $this->line('none');
        }
        foreach ($result['by_reason'] as $reason => $count) {
            $this->line($reason.': '.$count);
        }
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

    private function categoryOption(): ?string
    {
        $category = $this->option('category');
        if ($category === null || $category === false || $category === '') {
            return null;
        }
        if (! is_string($category)) {
            throw new RuntimeException('Pass --category as R, A1, A2, B, C, or all.');
        }
        if ($category === 'all' || in_array($category, array_keys(HistoricalAttributionBatch2Preflight::EVIDENCE_BY_CATEGORY), true)) {
            return $category === 'all' ? 'all' : $category;
        }

        throw new RuntimeException('Pass --category as R, A1, A2, B, C, or all.');
    }

    private function limitOption(): ?int
    {
        $limit = $this->option('limit');
        if ($limit === null || $limit === false || $limit === '') {
            return null;
        }
        if ((! is_string($limit) && ! is_int($limit)) || preg_match('/^[1-9][0-9]*$/', (string) $limit) !== 1) {
            throw new RuntimeException('Pass --limit as an integer from 1 to '.HistoricalAttributionBatch2Writer::MAX_LIMIT.'.');
        }
        $value = (int) $limit;
        if ($value > HistoricalAttributionBatch2Writer::MAX_LIMIT) {
            throw new RuntimeException('Pass --limit as an integer from 1 to '.HistoricalAttributionBatch2Writer::MAX_LIMIT.'.');
        }

        return $value;
    }

    private function attemptsOption(): ?int
    {
        $attempts = $this->option('attempts');
        if ($attempts === null || $attempts === false || $attempts === '') {
            return null;
        }
        if ((! is_string($attempts) && ! is_int($attempts)) || preg_match('/^[1-9][0-9]*$/', (string) $attempts) !== 1) {
            throw new RuntimeException('Pass --attempts as an integer from 1 to '.HistoricalAttributionBatch2Writer::MAX_LIMIT.'.');
        }
        $value = (int) $attempts;
        if ($value > HistoricalAttributionBatch2Writer::MAX_LIMIT) {
            throw new RuntimeException('Pass --attempts as an integer from 1 to '.HistoricalAttributionBatch2Writer::MAX_LIMIT.'.');
        }

        return $value;
    }

    /**
     * @param  list<array{reservation_id: string, proposed_user_id: string, proposed_plate_category: string, outcome: string, reason: string, current_plate_category: string}>  $rows
     */
    private function writeOutcomeReport(string $path, array $rows): void
    {
        $handle = $this->openReport($path);
        try {
            fputcsv($handle, ['reservation_id', 'proposed_user_id', 'proposed_plate_category', 'outcome', 'reason', 'current_plate_category']);
            foreach ($rows as $row) {
                fputcsv($handle, [
                    $row['reservation_id'],
                    $row['proposed_user_id'],
                    $row['proposed_plate_category'],
                    $row['outcome'],
                    $row['reason'],
                    $row['current_plate_category'],
                ]);
            }
        } finally {
            fclose($handle);
        }
    }

    /**
     * @param  list<array{reservation_id: string, outcome: string, reason: string}>  $rows
     */
    private function writeRollbackReport(string $path, array $rows): void
    {
        $handle = $this->openReport($path);
        try {
            fputcsv($handle, ['reservation_id', 'outcome', 'reason']);
            foreach ($rows as $row) {
                fputcsv($handle, [$row['reservation_id'], $row['outcome'], $row['reason']]);
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
        $handle = fopen($path, 'xb');
        if ($handle === false) {
            throw new RuntimeException('The report file could not be created.');
        }

        return $handle;
    }

    private function pathIsOutsideRepository(string $path): bool
    {
        $directory = dirname($path);
        $realDirectory = realpath($directory);
        $root = realpath(base_path());
        if ($realDirectory === false || $root === false || is_dir($path)) {
            return false;
        }

        $realDirectory = rtrim(str_replace('\\', '/', strtolower($realDirectory)), '/');
        $root = rtrim(str_replace('\\', '/', strtolower($root)), '/');

        return $realDirectory !== $root && ! str_starts_with($realDirectory, $root.'/');
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
