<?php

namespace App\Services\Reservation;

use RuntimeException;
use Throwable;

/**
 * Read-only freeze of one Batch 3 class-8 population.
 *
 * The live classifier is the source. A later classification changes as rows
 * are attributed and is not a replacement for the manifest written here.
 */
final class HistoricalAttributionBatch3Preparer
{
    public function __construct(private readonly HistoricalAttributionBatch3DryRun $dryRun) {}

    /**
     * @return array{class8: int, hold: int, eligible: int, groups: int, manifest: string}
     */
    public function prepare(string $directory, int $expectedClass8, int $expectedHold): array
    {
        if (! HistoricalAttributionBatch3Catalog::directoryOutsideRepository($directory)) {
            throw new RuntimeException('The destination directory must already exist outside the repository.');
        }
        if (! $this->directoryIsEmpty($directory)) {
            throw new RuntimeException('The destination directory is not empty. Preparation will not replace a frozen manifest.');
        }

        $classification = $this->dryRun->classify();
        if ($classification['reconciliation_passed'] !== true) {
            throw new RuntimeException('Classification reconciliation failed. No candidate files were written.');
        }

        $candidates = [];
        foreach ($classification['rows'] as $row) {
            if ($row['class'] !== HistoricalAttributionBatch3DryRun::CANDIDATE) {
                continue;
            }
            if ($row['name_owner_user_id'] === null) {
                throw new RuntimeException('A class-8 row has no name owner. No candidate files were written.');
            }
            $candidates[] = [
                'reservation_id' => (int) $row['reservation_id'],
                'proposed_user_id' => (int) $row['name_owner_user_id'],
            ];
        }
        usort($candidates, static fn (array $left, array $right): int => $left['reservation_id'] <=> $right['reservation_id']);
        $this->assertUnique($candidates);

        if (count($candidates) !== $expectedClass8 || $classification['counts'][HistoricalAttributionBatch3DryRun::CANDIDATE] !== $expectedClass8) {
            throw new RuntimeException('Class-8 count is '.count($candidates).'; the frozen baseline requires '.$expectedClass8.'. No candidate files were written.');
        }

        $hold = [];
        $eligible = [];
        foreach ($candidates as $row) {
            if (HistoricalAttributionBatch3Catalog::isHoldAgency($row['proposed_user_id'])) {
                $hold[] = $row;
            } else {
                $eligible[] = $row;
            }
        }
        if (count($hold) !== $expectedHold) {
            throw new RuntimeException('Held reservations are '.count($hold).'; the frozen baseline requires '.$expectedHold.'. No candidate files were written.');
        }

        $created = [];
        try {
            $holdDir = $directory.DIRECTORY_SEPARATOR.'hold';
            $eligibleDir = $directory.DIRECTORY_SEPARATOR.'eligible';
            if (! mkdir($holdDir) || ! mkdir($eligibleDir)) {
                throw new RuntimeException('The candidate directories could not be created.');
            }
            $holdRelative = 'hold/v1-batch3-hold.csv';
            $holdPath = $holdDir.DIRECTORY_SEPARATOR.'v1-batch3-hold.csv';
            $holdRows = $this->candidateRows($hold, HistoricalAttributionBatch3Catalog::DISPOSITION_HOLD, HistoricalAttributionBatch3Catalog::GROUP_HOLD, HistoricalAttributionBatch3Catalog::HOLD_REASON);
            $created[] = $holdPath;
            $holdHash = HistoricalAttributionBatch3Catalog::writeCsv($holdPath, $holdRows);

            $groups = [];
            foreach (array_chunk($eligible, HistoricalAttributionBatch3Catalog::GROUP_SIZE) as $index => $chunk) {
                $number = str_pad((string) ($index + 1), 3, '0', STR_PAD_LEFT);
                $groupId = 'b3-'.$number;
                $relative = 'eligible/v1-batch3-eligible-'.$number.'.csv';
                $path = $eligibleDir.DIRECTORY_SEPARATOR.'v1-batch3-eligible-'.$number.'.csv';
                $rows = $this->candidateRows($chunk, HistoricalAttributionBatch3Catalog::DISPOSITION_ELIGIBLE, $groupId, '');
                $created[] = $path;
                $groups[] = [
                    'group_id' => $groupId,
                    'file' => $relative,
                    'sha256' => HistoricalAttributionBatch3Catalog::writeCsv($path, $rows),
                    'rows' => $this->pairs($chunk),
                ];
            }

            $manifest = [
                'frozen' => true,
                'prepared_at' => now()->utc()->format('Y-m-d\TH:i:s\Z'),
                'baseline' => [
                    'class8' => $expectedClass8,
                    'hold' => $expectedHold,
                    'eligible' => count($eligible),
                ],
                'hold_agency_ids' => HistoricalAttributionBatch3Catalog::HOLD_AGENCY_IDS,
                'hold' => [
                    'group_id' => HistoricalAttributionBatch3Catalog::GROUP_HOLD,
                    'file' => $holdRelative,
                    'sha256' => $holdHash,
                    'rows' => $this->pairs($hold),
                ],
                'groups' => $groups,
            ];
            $manifestPath = $directory.DIRECTORY_SEPARATOR.'manifest.json';
            $created[] = $manifestPath;
            HistoricalAttributionBatch3Catalog::writeJson($manifestPath, $manifest);
        } catch (Throwable $e) {
            foreach (array_reverse($created) as $path) {
                if (is_file($path)) {
                    unlink($path);
                }
            }
            foreach (['eligible', 'hold'] as $child) {
                $childPath = $directory.DIRECTORY_SEPARATOR.$child;
                if (is_dir($childPath)) {
                    @rmdir($childPath);
                }
            }
            throw $e;
        }

        return [
            'class8' => count($candidates),
            'hold' => count($hold),
            'eligible' => count($eligible),
            'groups' => count($groups),
            'manifest' => $directory.DIRECTORY_SEPARATOR.'manifest.json',
        ];
    }

    /**
     * @param  list<array{reservation_id: int, proposed_user_id: int}>  $rows
     */
    private function assertUnique(array $rows): void
    {
        $seen = [];
        foreach ($rows as $row) {
            if (isset($seen[$row['reservation_id']])) {
                throw new RuntimeException('Classification produced a duplicate reservation id. No candidate files were written.');
            }
            $seen[$row['reservation_id']] = true;
        }
    }

    /**
     * @param  list<array{reservation_id: int, proposed_user_id: int}>  $rows
     * @return list<array{reservation_id: int, proposed_user_id: int, disposition: string, source_class: string, group_id: string, hold_reason: string}>
     */
    private function candidateRows(array $rows, string $disposition, string $groupId, string $holdReason): array
    {
        $written = [];
        foreach ($rows as $row) {
            $written[] = [
                'reservation_id' => $row['reservation_id'],
                'proposed_user_id' => $row['proposed_user_id'],
                'disposition' => $disposition,
                'source_class' => HistoricalAttributionBatch3DryRun::CANDIDATE,
                'group_id' => $groupId,
                'hold_reason' => $holdReason,
            ];
        }

        return $written;
    }

    /**
     * @param  list<array{reservation_id: int, proposed_user_id: int}>  $rows
     * @return list<array{reservation_id: int, proposed_user_id: int}>
     */
    private function pairs(array $rows): array
    {
        $pairs = [];
        foreach ($rows as $row) {
            $pairs[] = [
                'reservation_id' => $row['reservation_id'],
                'proposed_user_id' => $row['proposed_user_id'],
            ];
        }

        return $pairs;
    }

    private function directoryIsEmpty(string $directory): bool
    {
        $entries = scandir($directory);
        if ($entries === false) {
            return false;
        }
        foreach ($entries as $entry) {
            if ($entry !== '.' && $entry !== '..') {
                return false;
            }
        }

        return true;
    }
}
