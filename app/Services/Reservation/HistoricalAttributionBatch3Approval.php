<?php

namespace App\Services\Reservation;

use App\Models\Admin;
use RuntimeException;

/**
 * Promotes a frozen Batch 3 CSV into an approved file and sidecar.
 *
 * This writes files only. It does not change reservations or audits.
 * The sidecar hash is what later execution trusts.
 */
final class HistoricalAttributionBatch3Approval
{
    /**
     * @param  list<int>  $reviewReservationIds
     * @return array{group_id: string, reservations: int, evidence: string, approved: string, sha256: string}
     */
    public function approve(string $manifestPath, string $csvPath, string $outPath, string $confirm, Admin $actor, array $reviewReservationIds): array
    {
        if (! is_file($manifestPath)) {
            throw new RuntimeException('The frozen manifest was not found.');
        }
        if (! HistoricalAttributionBatch3Catalog::fileOutsideRepository($outPath)) {
            throw new RuntimeException('The approved CSV must be a new file in an existing directory outside the repository.');
        }
        $approvalPath = HistoricalAttributionBatch3Catalog::approvalPath($outPath);
        if (is_file($approvalPath)) {
            throw new RuntimeException('The approval record already exists and was not overwritten.');
        }
        if ($confirm !== HistoricalAttributionBatch3Catalog::CONFIRM_APPROVE && $confirm !== HistoricalAttributionBatch3Catalog::CONFIRM_APPROVE_REVIEW) {
            throw new RuntimeException('Pass --confirm='.HistoricalAttributionBatch3Catalog::CONFIRM_APPROVE.' or --confirm='.HistoricalAttributionBatch3Catalog::CONFIRM_APPROVE_REVIEW.' exactly.');
        }

        $manifest = HistoricalAttributionBatch3Catalog::readJson($manifestPath);
        $manifestDirectory = dirname(realpath($manifestPath) ?: $manifestPath);
        $csvReal = realpath($csvPath);
        if ($csvReal === false || ! $this->isInside($csvReal, $manifestDirectory)) {
            throw new RuntimeException('The source CSV must be a file inside the frozen manifest directory.');
        }
        $sourceRows = HistoricalAttributionBatch3Catalog::readCsv($csvPath);
        $sourceHash = HistoricalAttributionBatch3Catalog::sha256($csvPath);
        $entry = $this->matchingEntry($manifest, $sourceHash, $sourceRows);

        if ($confirm === HistoricalAttributionBatch3Catalog::CONFIRM_APPROVE) {
            if ($reviewReservationIds !== []) {
                throw new RuntimeException('Ordinary approval does not take a reservation list.');
            }
            if (($entry['group_id'] ?? '') === HistoricalAttributionBatch3Catalog::GROUP_HOLD) {
                throw new RuntimeException('Ordinary approval cannot promote the manual-hold file.');
            }
            foreach ($sourceRows as $row) {
                if ($row['disposition'] !== HistoricalAttributionBatch3Catalog::DISPOSITION_ELIGIBLE) {
                    throw new RuntimeException('Ordinary approval requires an eligible file.');
                }
                if (HistoricalAttributionBatch3Catalog::isHoldAgency($row['proposed_user_id'])) {
                    throw new RuntimeException('Ordinary approval cannot include a held agency.');
                }
            }
            $approvedRows = $this->retarget($sourceRows, HistoricalAttributionBatch3Catalog::DISPOSITION_APPROVED, (string) $entry['group_id'], '');
            $groupId = (string) $entry['group_id'];
            $holdReason = '';
            $evidence = HistoricalAttributionBatch3Catalog::EVIDENCE;
        } else {
            if ($reviewReservationIds === []) {
                throw new RuntimeException('Reviewed approval requires the reservation ids to promote.');
            }
            if (($entry['group_id'] ?? '') !== HistoricalAttributionBatch3Catalog::GROUP_HOLD) {
                throw new RuntimeException('Reviewed approval reads only the frozen hold file.');
            }
            $selected = $this->selectHold($sourceRows, $reviewReservationIds);
            $approvedRows = $this->retarget($selected, HistoricalAttributionBatch3Catalog::DISPOSITION_APPROVED_REVIEW, HistoricalAttributionBatch3Catalog::GROUP_REVIEW, HistoricalAttributionBatch3Catalog::HOLD_REASON);
            $groupId = HistoricalAttributionBatch3Catalog::GROUP_REVIEW;
            $holdReason = HistoricalAttributionBatch3Catalog::HOLD_REASON;
            $evidence = HistoricalAttributionBatch3Catalog::EVIDENCE_REVIEWED;
        }
        $this->assertNoOverlap($outPath, array_map(static fn (array $row): int => $row['reservation_id'], $approvedRows));

        $created = [];
        try {
            $created[] = $outPath;
            $approvedHash = HistoricalAttributionBatch3Catalog::writeCsv($outPath, $approvedRows);
            $root = rtrim(str_replace('\\', '/', $manifestDirectory), '/');
            $full = str_replace('\\', '/', $csvReal);
            $relativeSource = substr($full, strlen($root) + 1);
            $record = [
                'source_file' => $relativeSource,
                'source_sha256' => $sourceHash,
                'approved_file' => basename($outPath),
                'approved_sha256' => $approvedHash,
                'group_id' => $groupId,
                'disposition' => $approvedRows[0]['disposition'],
                'hold_reason' => $holdReason,
                'evidence' => $evidence,
                'confirm' => $confirm,
                'admin_id' => (int) $actor->id,
                'approved_at' => now()->utc()->format('Y-m-d\TH:i:s\Z'),
                'rows' => array_map(static fn (array $row): array => [
                    'reservation_id' => $row['reservation_id'],
                    'proposed_user_id' => $row['proposed_user_id'],
                ], $approvedRows),
            ];
            $created[] = $approvalPath;
            HistoricalAttributionBatch3Catalog::writeJson($approvalPath, $record);
            $verified = $this->verify($outPath);
            if ($verified['record']['approved_sha256'] !== $approvedHash || count($verified['rows']) !== count($approvedRows)) {
                throw new RuntimeException('The approval record did not verify after it was written.');
            }
        } catch (\Throwable $e) {
            foreach (array_reverse($created) as $path) {
                if (is_file($path)) {
                    unlink($path);
                }
            }
            throw $e;
        }

        return [
            'group_id' => $groupId,
            'reservations' => count($approvedRows),
            'evidence' => $evidence,
            'approved' => $outPath,
            'sha256' => $approvedHash,
        ];
    }

    /**
     * File integrity gate. Call this before any per-row database transaction.
     *
     * @return array{record: array<string, mixed>, rows: list<array{reservation_id: int, proposed_user_id: int, disposition: string, source_class: string, group_id: string, hold_reason: string}>}
     */
    public function verify(string $csvPath): array
    {
        $rows = HistoricalAttributionBatch3Catalog::readCsv($csvPath);
        $record = HistoricalAttributionBatch3Catalog::readJson(HistoricalAttributionBatch3Catalog::approvalPath($csvPath));
        $hash = HistoricalAttributionBatch3Catalog::sha256($csvPath);
        $recordedHash = $record['approved_sha256'] ?? null;
        if (! is_string($recordedHash) || ! hash_equals($recordedHash, $hash)) {
            throw new RuntimeException('The approved CSV no longer matches its approval record.');
        }
        $expectedPairs = $record['rows'] ?? null;
        if (! is_array($expectedPairs) || count($expectedPairs) !== count($rows)) {
            throw new RuntimeException('The approved CSV no longer matches its approval record.');
        }
        $groupId = (string) ($record['group_id'] ?? '');
        $disposition = (string) ($record['disposition'] ?? '');
        $holdReason = (string) ($record['hold_reason'] ?? '');
        $evidence = (string) ($record['evidence'] ?? '');
        if ($evidence !== HistoricalAttributionBatch3Catalog::evidence($record)) {
            throw new RuntimeException('The approval record evidence does not match its confirm value.');
        }
        foreach ($rows as $index => $row) {
            $expected = $expectedPairs[$index] ?? null;
            if (! is_array($expected)
                || (int) ($expected['reservation_id'] ?? 0) !== $row['reservation_id']
                || (int) ($expected['proposed_user_id'] ?? 0) !== $row['proposed_user_id']
                || $row['group_id'] !== $groupId
                || $row['disposition'] !== $disposition
                || $row['hold_reason'] !== $holdReason) {
                throw new RuntimeException('The approved CSV no longer matches its approval record.');
            }
        }
        if ($disposition !== HistoricalAttributionBatch3Catalog::DISPOSITION_APPROVED
            && $disposition !== HistoricalAttributionBatch3Catalog::DISPOSITION_APPROVED_REVIEW) {
            throw new RuntimeException('The approval record is not an approved disposition.');
        }

        return [
            'record' => $record,
            'rows' => $rows,
        ];
    }

    /**
     * @param  array<string, mixed>  $manifest
     * @param  list<array{reservation_id: int, proposed_user_id: int, disposition: string, source_class: string, group_id: string, hold_reason: string}>  $sourceRows
     * @return array<string, mixed>
     */
    private function matchingEntry(array $manifest, string $sourceHash, array $sourceRows): array
    {
        $entries = [];
        if (isset($manifest['hold']) && is_array($manifest['hold'])) {
            $entries[] = $manifest['hold'];
        }
        foreach ($manifest['groups'] ?? [] as $group) {
            if (is_array($group)) {
                $entries[] = $group;
            }
        }
        $pairs = array_map(static fn (array $row): array => [
            'reservation_id' => $row['reservation_id'],
            'proposed_user_id' => $row['proposed_user_id'],
        ], $sourceRows);
        foreach ($entries as $entry) {
            if (($entry['sha256'] ?? null) === $sourceHash && ($entry['rows'] ?? null) === $pairs) {
                return $entry;
            }
        }

        throw new RuntimeException('The source CSV does not match the frozen manifest.');
    }

    /**
     * @param  list<int>  $incoming
     */
    private function assertNoOverlap(string $outPath, array $incoming): void
    {
        $directory = dirname($outPath);
        $existing = glob($directory.DIRECTORY_SEPARATOR.'*.approval.json');
        if ($existing === false) {
            return;
        }
        foreach ($existing as $path) {
            $record = HistoricalAttributionBatch3Catalog::readJson($path);
            foreach ($record['rows'] ?? [] as $row) {
                if (is_array($row) && in_array((int) ($row['reservation_id'] ?? 0), $incoming, true)) {
                    throw new RuntimeException('A reservation id in this approval is already present in another approval record.');
                }
            }
        }
    }

    /**
     * @param  list<array{reservation_id: int, proposed_user_id: int, disposition: string, source_class: string, group_id: string, hold_reason: string}>  $sourceRows
     * @param  list<int>  $reviewReservationIds
     * @return list<array{reservation_id: int, proposed_user_id: int, disposition: string, source_class: string, group_id: string, hold_reason: string}>
     */
    private function selectHold(array $sourceRows, array $reviewReservationIds): array
    {
        $byId = [];
        foreach ($sourceRows as $row) {
            $byId[$row['reservation_id']] = $row;
        }
        $seen = [];
        $selected = [];
        foreach ($reviewReservationIds as $reservationId) {
            if (isset($seen[$reservationId])) {
                throw new RuntimeException('The review list contains a duplicate reservation id.');
            }
            $seen[$reservationId] = true;
            if (! isset($byId[$reservationId])) {
                throw new RuntimeException('A review reservation id is not in the frozen hold file.');
            }
            if (! HistoricalAttributionBatch3Catalog::isHoldAgency($byId[$reservationId]['proposed_user_id'])) {
                throw new RuntimeException('Reviewed approval cannot include an ordinary candidate.');
            }
            $selected[] = $byId[$reservationId];
        }
        usort($selected, static fn (array $left, array $right): int => $left['reservation_id'] <=> $right['reservation_id']);

        return $selected;
    }

    /**
     * @param  list<array{reservation_id: int, proposed_user_id: int, disposition: string, source_class: string, group_id: string, hold_reason: string}>  $rows
     * @return list<array{reservation_id: int, proposed_user_id: int, disposition: string, source_class: string, group_id: string, hold_reason: string}>
     */
    private function retarget(array $rows, string $disposition, string $groupId, string $holdReason): array
    {
        $copy = [];
        foreach ($rows as $row) {
            $row['disposition'] = $disposition;
            $row['group_id'] = $groupId;
            $row['hold_reason'] = $holdReason;
            $copy[] = $row;
        }

        return $copy;
    }

    private function isInside(string $path, string $directory): bool
    {
        $path = rtrim(str_replace('\\', '/', strtolower($path)), '/');
        $directory = rtrim(str_replace('\\', '/', strtolower($directory)), '/');

        return $path === $directory || str_starts_with($path, $directory.'/');
    }
}
