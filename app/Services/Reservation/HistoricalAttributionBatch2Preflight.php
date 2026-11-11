<?php

namespace App\Services\Reservation;

use App\Support\ReservationOrigin;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use RuntimeException;

/**
 * Read-only review of a proposed Batch 2 candidate CSV.
 *
 * The CSV is a proposal. This class rebuilds the canonical PHP identity maps
 * once, then classifies each proposed row. It does not attribute reservations.
 * An execution path must not treat this snapshot as the final uniqueness check.
 */
final class HistoricalAttributionBatch2Preflight
{
    public const CONFIRM_APPLY = 'APPLY-V1-BATCH-2';

    public const CONFIRM_ROLLBACK = 'ROLLBACK-V1-BATCH-2';

    /** @var array<string, string> */
    public const EVIDENCE_BY_CATEGORY = [
        'R' => 'v1-phase2-batch2:exact_email_unique_exact_name:plate_R',
        'A1' => 'v1-phase2-batch2:exact_email_unique_exact_name:plate_A1',
        'A2' => 'v1-phase2-batch2:exact_email_unique_exact_name:plate_A2',
        'B' => 'v1-phase2-batch2:exact_email_unique_exact_name:plate_B',
        'C' => 'v1-phase2-batch2:exact_email_unique_exact_name:plate_C',
    ];

    /** @var array<string, string> */
    public const CONFIRM_ROLLBACK_BY_CATEGORY = [
        'R' => 'ROLLBACK-V1-BATCH-2-R',
        'A1' => 'ROLLBACK-V1-BATCH-2-A1',
        'A2' => 'ROLLBACK-V1-BATCH-2-A2',
        'B' => 'ROLLBACK-V1-BATCH-2-B',
        'C' => 'ROLLBACK-V1-BATCH-2-C',
    ];

    public const REASON_INVALID_ROW = 'invalid_row';

    public const REASON_DUPLICATE = 'duplicate_candidate';

    public const REASON_CONFLICT = 'conflicting_candidate_assignment';

    public const REASON_MISSING = 'reservation_missing';

    public const REASON_NOT_V1 = 'not_imported_v1';

    public const REASON_USER_SET = 'user_id_not_null';

    public const REASON_ACTIVE_AUDIT = 'active_attribution_exists';

    public const REASON_AGENCY_MISSING = 'agency_missing';

    public const REASON_NOT_IDENTITY = 'not_identity_candidate';

    public const REASON_USER_MISMATCH = 'proposed_user_mismatch';

    public const REASON_PLATE_CHANGED = 'plate_category_changed';

    public const REASON_CLOSED_BATCH2 = 'closed_batch2_audit_requires_review';

    private const CHUNK = 500;

    /** @var list<string> */
    private const HEADER = ['reservation_id', 'proposed_user_id', 'plate_category'];

    public function __construct(private readonly HistoricalAttributionBatch2DryRun $dryRun) {}

    /**
     * CSV rows in reservation-id order, without reading identity maps.
     * A structurally invalid file is not a safe execution or rollback scope.
     *
     * @return array{
     *     input_valid: bool,
     *     rows: list<array{reservation_id: string, proposed_user_id: string, proposed_plate_category: string, outcome: string, reason: string, current_plate_category: string}>
     * }
     */
    public function candidateRows(string $csvPath): array
    {
        if (! Schema::hasTable('reservation_attributions')) {
            throw new RuntimeException('Table reservation_attributions is missing. The approved Phase 1 migration must already be applied. This preflight does not migrate.');
        }

        $parsed = $this->readRows($csvPath);
        $structural = $this->structuralReasons($parsed);
        $rows = [];
        foreach ($parsed as $index => $row) {
            if (isset($structural[$index])) {
                $rows[] = $this->outcome($row, 'rejected', $structural[$index], '');
                continue;
            }
            $rows[] = $this->outcome($row, $structural === [] ? 'pending' : 'rejected', $structural === [] ? '' : 'invalid_csv', '');
        }
        usort($rows, function (array $left, array $right): int {
            $byId = strnatcmp($left['reservation_id'], $right['reservation_id']);

            return $byId !== 0 ? $byId : strcmp($left['reason'], $right['reason']);
        });

        return [
            'input_valid' => $structural === [],
            'rows' => $rows,
        ];
    }

    /**
     * @return array{
     *     total: int,
     *     accepted: int,
     *     skipped: int,
     *     rejected: int,
     *     wrote: int,
     *     input_valid: bool,
     *     by_reason: array<string, int>,
     *     rows: list<array{reservation_id: string, proposed_user_id: string, proposed_plate_category: string, outcome: string, reason: string, current_plate_category: string}>
     * }
     */
    public function review(string $csvPath): array
    {
        if (! Schema::hasTable('reservation_attributions')) {
            throw new RuntimeException('Table reservation_attributions is missing. The approved Phase 1 migration must already be applied. This preflight does not migrate.');
        }

        $parsed = $this->readRows($csvPath);
        $structural = $this->structuralReasons($parsed);
        $pending = [];
        foreach ($parsed as $index => $row) {
            if (! isset($structural[$index])) {
                $pending[] = $row;
            }
        }

        $database = $this->databaseOutcomes($pending);
        $rows = [];
        $accepted = 0;
        $skipped = 0;
        $rejected = 0;
        $byReason = [];

        foreach ($parsed as $index => $row) {
            if (isset($structural[$index])) {
                $outcome = $this->outcome($row, 'rejected', $structural[$index], '');
            } else {
                $outcome = $database[$row['reservation_id']];
            }

            $rows[] = $outcome;
            if ($outcome['outcome'] === 'accepted') {
                $accepted++;
                continue;
            }
            if ($outcome['outcome'] === 'skipped' || $outcome['outcome'] === 'manual_review') {
                $skipped++;
            } else {
                $rejected++;
            }
            $byReason[$outcome['reason']] = ($byReason[$outcome['reason']] ?? 0) + 1;
        }

        ksort($byReason);
        usort($rows, function (array $left, array $right): int {
            $byId = strnatcmp($left['reservation_id'], $right['reservation_id']);

            return $byId !== 0 ? $byId : strcmp($left['reason'], $right['reason']);
        });

        return [
            'total' => count($parsed),
            'accepted' => $accepted,
            'skipped' => $skipped,
            'rejected' => $rejected,
            'wrote' => 0,
            'input_valid' => $structural === [],
            'by_reason' => $byReason,
            'rows' => $rows,
        ];
    }

    /**
     * @param  list<array{reservation_id: string, proposed_user_id: string, plate_category: string}>  $rows
     * @return array<int, string>
     */
    private function structuralReasons(array $rows): array
    {
        $grouped = [];
        foreach ($rows as $index => $row) {
            if (! $this->rowIsWellFormed($row)) {
                continue;
            }
            $grouped[$row['reservation_id']][] = $index;
        }

        $reasons = [];
        foreach ($rows as $index => $row) {
            if (! $this->rowIsWellFormed($row)) {
                $reasons[$index] = self::REASON_INVALID_ROW;
                continue;
            }
            $indexes = $grouped[$row['reservation_id']] ?? [];
            if (count($indexes) < 2) {
                continue;
            }
            $signatures = [];
            foreach ($indexes as $sibling) {
                $signatures[$rows[$sibling]['proposed_user_id'].'|'.$rows[$sibling]['plate_category']] = true;
            }
            $reasons[$index] = count($signatures) > 1 ? self::REASON_CONFLICT : self::REASON_DUPLICATE;
        }

        return $reasons;
    }

    /**
     * @param  list<array{reservation_id: string, proposed_user_id: string, plate_category: string}>  $rows
     * @return array<string, array{reservation_id: string, proposed_user_id: string, proposed_plate_category: string, outcome: string, reason: string, current_plate_category: string}>
     */
    private function databaseOutcomes(array $rows): array
    {
        if ($rows === []) {
            return [];
        }

        $snapshot = $this->dryRun->currentIdentitySnapshot();
        $agencies = [];
        foreach (DB::table('users')->pluck('id') as $id) {
            $agencies[(int) $id] = true;
        }
        $activeAudits = [];
        foreach (DB::table('reservation_attributions')->whereNotNull('active_reservation_id')->pluck('active_reservation_id') as $id) {
            $activeAudits[(int) $id] = true;
        }

        $reservations = [];
        $ids = array_values(array_unique(array_map(static fn (array $row): int => (int) $row['reservation_id'], $rows)));
        $closedBatch2 = [];
        foreach (array_chunk($ids, self::CHUNK) as $chunk) {
            $found = DB::table('reservation_attributions')
                ->whereIn('reservation_id', $chunk)
                ->whereIn('evidence', array_values(self::EVIDENCE_BY_CATEGORY))
                ->whereNull('active_reservation_id')
                ->pluck('reservation_id');
            foreach ($found as $id) {
                $closedBatch2[(int) $id] = true;
            }
        }
        foreach (array_chunk($ids, self::CHUNK) as $chunk) {
            $found = DB::table('reservations')
                ->whereIn('id', $chunk)
                ->get(['id', 'user_id', 'created_at', 'email', 'user_name', 'license_plate']);
            foreach ($found as $reservation) {
                $reservations[(int) $reservation->id] = $reservation;
            }
        }

        $outcomes = [];
        foreach ($rows as $row) {
            $reservationId = (int) $row['reservation_id'];
            $proposedUserId = (int) $row['proposed_user_id'];
            $reservation = $reservations[$reservationId] ?? null;
            if ($reservation === null) {
                $outcomes[$row['reservation_id']] = $this->outcome($row, 'rejected', self::REASON_MISSING, '');
                continue;
            }
            if (! ReservationOrigin::isImportedV1($reservation->created_at)) {
                $outcomes[$row['reservation_id']] = $this->outcome($row, 'rejected', self::REASON_NOT_V1, '');
                continue;
            }
            if (isset($activeAudits[$reservationId])) {
                $outcomes[$row['reservation_id']] = $this->outcome($row, 'rejected', self::REASON_ACTIVE_AUDIT, '');
                continue;
            }
            if (isset($closedBatch2[$reservationId])) {
                $outcomes[$row['reservation_id']] = $this->outcome($row, 'manual_review', self::REASON_CLOSED_BATCH2, '');
                continue;
            }
            if ($reservation->user_id !== null) {
                $outcomes[$row['reservation_id']] = $this->outcome($row, 'rejected', self::REASON_USER_SET, '');
                continue;
            }
            if (! isset($agencies[$proposedUserId])) {
                $outcomes[$row['reservation_id']] = $this->outcome($row, 'rejected', self::REASON_AGENCY_MISSING, '');
                continue;
            }

            $classified = $this->dryRun->classifyIdentity(
                $reservation->email,
                $reservation->user_name,
                $reservation->license_plate,
                $snapshot,
            );
            $category = $classified['category'];
            if (! in_array($category, HistoricalAttributionBatch2DryRun::IDENTITY_CATEGORIES, true)) {
                $outcomes[$row['reservation_id']] = $this->outcome($row, 'rejected', self::REASON_NOT_IDENTITY, $category);
                continue;
            }
            if ((int) $classified['agency_id'] !== $proposedUserId) {
                $outcomes[$row['reservation_id']] = $this->outcome($row, 'rejected', self::REASON_USER_MISMATCH, $category);
                continue;
            }
            if ($category !== $row['plate_category']) {
                $outcomes[$row['reservation_id']] = $this->outcome($row, 'skipped', self::REASON_PLATE_CHANGED, $category);
                continue;
            }

            $outcomes[$row['reservation_id']] = $this->outcome($row, 'accepted', '', $category);
        }

        return $outcomes;
    }

    /**
     * @param  array{reservation_id: string, proposed_user_id: string, plate_category: string}  $row
     * @return array{reservation_id: string, proposed_user_id: string, proposed_plate_category: string, outcome: string, reason: string, current_plate_category: string}
     */
    private function outcome(array $row, string $outcome, string $reason, string $currentCategory): array
    {
        return [
            'reservation_id' => $row['reservation_id'],
            'proposed_user_id' => $row['proposed_user_id'],
            'proposed_plate_category' => $row['plate_category'],
            'outcome' => $outcome,
            'reason' => $reason,
            'current_plate_category' => $currentCategory,
        ];
    }

    /**
     * @param  array{reservation_id: string, proposed_user_id: string, plate_category: string}  $row
     */
    private function rowIsWellFormed(array $row): bool
    {
        return $this->isPositiveInteger($row['reservation_id'])
            && $this->isPositiveInteger($row['proposed_user_id'])
            && in_array($row['plate_category'], HistoricalAttributionBatch2DryRun::IDENTITY_CATEGORIES, true);
    }

    private function isPositiveInteger(string $value): bool
    {
        return preg_match('/^[1-9][0-9]*$/', $value) === 1;
    }

    /**
     * @return list<array{reservation_id: string, proposed_user_id: string, plate_category: string}>
     */
    private function readRows(string $csvPath): array
    {
        $handle = fopen($csvPath, 'rb');
        if ($handle === false) {
            throw new RuntimeException('The candidate CSV could not be opened.');
        }

        try {
            $header = fgetcsv($handle);
            if (! is_array($header)) {
                throw new RuntimeException('The candidate CSV has no header.');
            }
            $header[0] = preg_replace('/^\xEF\xBB\xBF/', '', (string) $header[0]) ?? (string) $header[0];
            $header = array_map(static fn ($cell) => trim((string) $cell), $header);
            if ($header !== self::HEADER) {
                throw new RuntimeException('The candidate CSV header must be reservation_id,proposed_user_id,plate_category.');
            }

            $rows = [];
            while (($cells = fgetcsv($handle)) !== false) {
                if ($cells === [null] || $cells === false) {
                    continue;
                }
                $cells = array_pad(array_map(static fn ($cell) => trim((string) $cell), $cells), 3, '');
                if ($cells === ['', '', '']) {
                    continue;
                }
                $rows[] = [
                    'reservation_id' => $cells[0],
                    'proposed_user_id' => $cells[1],
                    'plate_category' => $cells[2],
                ];
            }

            return $rows;
        } finally {
            fclose($handle);
        }
    }
}
