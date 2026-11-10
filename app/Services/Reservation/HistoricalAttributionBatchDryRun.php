<?php

namespace App\Services\Reservation;

use App\Models\Reservation;
use App\Models\Vehicle;
use App\Support\ReservationOrigin;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use RuntimeException;

/**
 * Read-only check of a proposed V1 attribution CSV.
 *
 * Approved batch criterion, all three required for the proposed agency:
 * - exact email: trim + lowercase, the same comparison as the V1 estimate's exact email
 * - exact agency name: trim, collapse whitespace, lowercase; the name must belong to one user
 * - exclusive plate: DuplicateReservationAttemptService::normalizeLicensePlate, and that plate
 *   is present only on that agency among active and removed vehicles
 *
 * This class does not insert audit rows and does not change reservations.
 */
final class HistoricalAttributionBatchDryRun
{
    public const CRITERION = 'exact_email_unique_exact_name_exclusive_plate';

    public const DECISION = 'REVIEW_ONLY';

    public const REASON_INVALID_ROW = 'invalid_row';

    public const REASON_UNEXPECTED_CRITERION = 'unexpected_criterion';

    public const REASON_UNEXPECTED_DECISION = 'unexpected_decision';

    public const REASON_DUPLICATE_CANDIDATE = 'duplicate_candidate';

    public const REASON_CONFLICTING_ASSIGNMENT = 'conflicting_candidate_assignment';

    public const REASON_RESERVATION_MISSING = 'reservation_missing';

    public const REASON_NOT_IMPORTED_V1 = 'not_imported_v1';

    public const REASON_USER_ID_NOT_NULL = 'user_id_not_null';

    public const REASON_ACTIVE_AUDIT = 'active_attribution_exists';

    public const REASON_AGENCY_MISSING = 'agency_missing';

    public const REASON_EMAIL_MISSING = 'email_missing';

    public const REASON_EMAIL_MISMATCH = 'email_mismatch';

    public const REASON_EMAIL_NOT_UNIQUE = 'email_not_unique';

    public const REASON_NAME_MISSING = 'name_missing';

    public const REASON_NAME_MISMATCH = 'name_mismatch';

    public const REASON_NAME_NOT_UNIQUE = 'name_not_unique';

    public const REASON_PLATE_MISSING = 'plate_missing';

    public const REASON_PLATE_NOT_IN_REGISTRY = 'plate_not_in_registry';

    public const REASON_PLATE_NOT_EXCLUSIVE = 'plate_not_exclusive';

    private const CHUNK = 500;

    /** @var list<string> */
    private const HEADER = ['reservation_id', 'proposed_user_id', 'criterion', 'decision'];

    /**
     * @return array{
     *     total: int,
     *     accepted: int,
     *     excluded: int,
     *     agencies: int,
     *     by_reason: array<string, int>,
     *     by_agency: list<array{user_id: int, name: string, count: int}>,
     *     exclusions: list<array{reservation_id: string, proposed_user_id: string, reason: string}>,
     *     accepted_rows: list<array{reservation_id: string, proposed_user_id: string}>
     * }
     */
    public function evaluate(string $csvPath): array
    {
        $rows = $this->readRows($csvPath);
        $structural = $this->structuralReasons($rows);
        $pending = [];
        foreach ($rows as $index => $row) {
            if (! isset($structural[$index])) {
                $pending[] = $row;
            }
        }

        $databaseReasons = $this->databaseReasons($pending);
        $exclusions = [];
        $acceptedByAgency = [];
        $acceptedRows = [];

        foreach ($rows as $index => $row) {
            $reason = $structural[$index] ?? $databaseReasons[$row['reservation_id']] ?? null;
            if ($reason === null) {
                $agencyId = (int) $row['proposed_user_id'];
                $acceptedByAgency[$agencyId] = ($acceptedByAgency[$agencyId] ?? 0) + 1;
                $acceptedRows[] = [
                    'reservation_id' => $row['reservation_id'],
                    'proposed_user_id' => $row['proposed_user_id'],
                ];
                continue;
            }

            $exclusions[] = [
                'reservation_id' => $row['reservation_id'],
                'proposed_user_id' => $row['proposed_user_id'],
                'reason' => $reason,
            ];
        }

        usort($exclusions, function (array $left, array $right): int {
            $byId = strnatcmp($left['reservation_id'], $right['reservation_id']);

            return $byId !== 0 ? $byId : strcmp($left['reason'], $right['reason']);
        });

        $byReason = [];
        foreach ($exclusions as $exclusion) {
            $byReason[$exclusion['reason']] = ($byReason[$exclusion['reason']] ?? 0) + 1;
        }
        ksort($byReason);

        $agencyNames = $this->agencyNames(array_keys($acceptedByAgency));
        $byAgency = [];
        ksort($acceptedByAgency);
        foreach ($acceptedByAgency as $userId => $count) {
            $byAgency[] = [
                'user_id' => (int) $userId,
                'name' => $agencyNames[(int) $userId] ?? '',
                'count' => $count,
            ];
        }

        return [
            'total' => count($rows),
            'accepted' => count($rows) - count($exclusions),
            'excluded' => count($exclusions),
            'agencies' => count($acceptedByAgency),
            'by_reason' => $byReason,
            'by_agency' => $byAgency,
            'exclusions' => $exclusions,
            'accepted_rows' => $acceptedRows,
        ];
    }

    /**
     * Re-check the approved three-part criterion against the current user and vehicle rows.
     * A passed snapshot is used only by the read-only preflight after it has just loaded one.
     * The writer omits it, so each attribution reads the tables again inside its transaction.
     * Does not treat a null user_id as a failure; the attribution service checks that under the same lock.
     *
     * @param  array{
     *     emails: array<string, list<int>>,
     *     names: array<string, list<int>>,
     *     plates: array<string, list<int>>,
     *     agencies: array<int, array{email: string, name: string}>
     * }|null  $snapshot
     */
    public function criterionMismatch(Reservation $reservation, int $agencyId, ?array $snapshot = null): ?string
    {
        $snapshot ??= $this->loadIdentitySnapshot();

        return $this->reasonFromSnapshot(
            $reservation->created_at,
            $reservation->email,
            $reservation->user_name,
            $reservation->license_plate,
            $agencyId,
            $snapshot,
        );
    }

    /**
     * Current normalized email owners, name owners, plate owners, and agency identity.
     * Reads users once and active/removed vehicles once. No timestamp watermark.
     *
     * @return array{
     *     emails: array<string, list<int>>,
     *     names: array<string, list<int>>,
     *     plates: array<string, list<int>>,
     *     agencies: array<int, array{email: string, name: string}>
     * }
     */
    public function loadIdentitySnapshot(): array
    {
        return $this->readIdentityMaps();
    }

    /**
     * @param  array{
     *     emails: array<string, list<int>>,
     *     names: array<string, list<int>>,
     *     plates: array<string, list<int>>,
     *     agencies: array<int, array{email: string, name: string}>
     * }  $snapshot
     */
    private function reasonFromSnapshot(mixed $createdAt, mixed $email, mixed $userName, mixed $plate, int $agencyId, array $snapshot): ?string
    {
        if (! ReservationOrigin::isImportedV1($createdAt)) {
            return self::REASON_NOT_IMPORTED_V1;
        }
        $agency = $snapshot['agencies'][$agencyId] ?? null;
        if ($agency === null) {
            return self::REASON_AGENCY_MISSING;
        }

        $normalizedEmail = self::normalizeEmail(is_string($email) ? $email : (string) $email);
        if ($normalizedEmail === '') {
            return self::REASON_EMAIL_MISSING;
        }
        $emailOwnerIds = $snapshot['emails'][$normalizedEmail] ?? [];
        if ($normalizedEmail !== $agency['email'] || ! in_array($agencyId, $emailOwnerIds, true)) {
            return self::REASON_EMAIL_MISMATCH;
        }
        if (count($emailOwnerIds) !== 1) {
            return self::REASON_EMAIL_NOT_UNIQUE;
        }

        $normalizedName = self::normalizeAgencyName(is_string($userName) ? $userName : (string) $userName);
        if ($normalizedName === '') {
            return self::REASON_NAME_MISSING;
        }
        $nameOwnerIds = $snapshot['names'][$normalizedName] ?? [];
        if ($normalizedName !== $agency['name'] || ! in_array($agencyId, $nameOwnerIds, true)) {
            return self::REASON_NAME_MISMATCH;
        }
        if (count($nameOwnerIds) !== 1) {
            return self::REASON_NAME_NOT_UNIQUE;
        }

        $normalizedPlate = DuplicateReservationAttemptService::normalizeLicensePlate(is_string($plate) ? $plate : (string) $plate);
        if ($normalizedPlate === '') {
            return self::REASON_PLATE_MISSING;
        }
        $owners = $snapshot['plates'][$normalizedPlate] ?? [];
        if ($owners === []) {
            return self::REASON_PLATE_NOT_IN_REGISTRY;
        }
        if ($owners !== [$agencyId]) {
            return self::REASON_PLATE_NOT_EXCLUSIVE;
        }

        return null;
    }

    public static function normalizeEmail(?string $email): string
    {
        return strtolower(trim((string) $email));
    }

    public static function normalizeAgencyName(?string $name): string
    {
        $name = trim((string) $name);
        $name = preg_replace('/\s+/u', ' ', $name) ?? $name;

        return mb_strtolower($name);
    }

    /**
     * @return list<array{reservation_id: string, proposed_user_id: string, criterion: string, decision: string}>
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
                throw new RuntimeException('The candidate CSV header must be reservation_id,proposed_user_id,criterion,decision.');
            }

            $rows = [];
            while (($cells = fgetcsv($handle)) !== false) {
                if ($cells === [null] || $cells === false) {
                    continue;
                }
                $cells = array_pad(array_map(static fn ($cell) => trim((string) $cell), $cells), 4, '');
                if ($cells === ['', '', '', '']) {
                    continue;
                }
                $rows[] = [
                    'reservation_id' => $cells[0],
                    'proposed_user_id' => $cells[1],
                    'criterion' => $cells[2],
                    'decision' => $cells[3],
                ];
            }

            return $rows;
        } finally {
            fclose($handle);
        }
    }

    /**
     * @param  list<array{reservation_id: string, proposed_user_id: string, criterion: string, decision: string}>  $rows
     * @return array<int, string>
     */
    private function structuralReasons(array $rows): array
    {
        $grouped = [];
        foreach ($rows as $index => $row) {
            if (! $this->isPositiveInteger($row['reservation_id']) || ! $this->isPositiveInteger($row['proposed_user_id'])) {
                continue;
            }
            $grouped[$row['reservation_id']][] = $index;
        }

        $reasons = [];
        foreach ($rows as $index => $row) {
            if (! $this->isPositiveInteger($row['reservation_id']) || ! $this->isPositiveInteger($row['proposed_user_id'])) {
                $reasons[$index] = self::REASON_INVALID_ROW;
                continue;
            }
            if ($row['criterion'] !== self::CRITERION) {
                $reasons[$index] = self::REASON_UNEXPECTED_CRITERION;
                continue;
            }
            if ($row['decision'] !== self::DECISION) {
                $reasons[$index] = self::REASON_UNEXPECTED_DECISION;
                continue;
            }

            $indexes = $grouped[$row['reservation_id']] ?? [];
            if (count($indexes) < 2) {
                continue;
            }
            $proposed = [];
            foreach ($indexes as $sibling) {
                $proposed[$rows[$sibling]['proposed_user_id']] = true;
            }
            $reasons[$index] = count($proposed) > 1
                ? self::REASON_CONFLICTING_ASSIGNMENT
                : self::REASON_DUPLICATE_CANDIDATE;
        }

        return $reasons;
    }

    /**
     * @param  list<array{reservation_id: string, proposed_user_id: string, criterion: string, decision: string}>  $rows
     * @return array<string, string>
     */
    private function databaseReasons(array $rows): array
    {
        if ($rows === []) {
            return [];
        }
        if (! Schema::hasTable('reservation_attributions')) {
            throw new RuntimeException('Table reservation_attributions is missing. The approved Phase 1 migration must already be applied. This dry-run does not migrate.');
        }

        $reservationIds = array_values(array_unique(array_map(static fn (array $row) => (int) $row['reservation_id'], $rows)));

        $reservations = $this->reservationsById($reservationIds);
        $snapshot = $this->loadIdentitySnapshot();
        $activeAudits = $this->activeAuditReservationIds($reservationIds);

        $reasons = [];
        foreach ($rows as $row) {
            $reservationId = (int) $row['reservation_id'];
            $agencyId = (int) $row['proposed_user_id'];
            $reservation = $reservations[$reservationId] ?? null;
            if ($reservation === null) {
                $reasons[$row['reservation_id']] = self::REASON_RESERVATION_MISSING;
                continue;
            }
            if (! ReservationOrigin::isImportedV1($reservation->created_at)) {
                $reasons[$row['reservation_id']] = self::REASON_NOT_IMPORTED_V1;
                continue;
            }
            if ($reservation->user_id !== null) {
                $reasons[$row['reservation_id']] = self::REASON_USER_ID_NOT_NULL;
                continue;
            }
            if (isset($activeAudits[$reservationId])) {
                $reasons[$row['reservation_id']] = self::REASON_ACTIVE_AUDIT;
                continue;
            }
            $reason = $this->reasonFromSnapshot(
                $reservation->created_at,
                $reservation->email,
                $reservation->user_name,
                $reservation->license_plate,
                $agencyId,
                $snapshot,
            );
            if ($reason !== null) {
                $reasons[$row['reservation_id']] = $reason;
            }
        }

        return $reasons;
    }

    private function isPositiveInteger(string $value): bool
    {
        return preg_match('/^[1-9][0-9]*$/', $value) === 1;
    }

    /**
     * @param  list<int>  $ids
     * @return array<int, object>
     */
    private function reservationsById(array $ids): array
    {
        $found = [];
        foreach (array_chunk($ids, self::CHUNK) as $chunk) {
            $rows = DB::table('reservations')
                ->whereIn('id', $chunk)
                ->get(['id', 'user_id', 'created_at', 'email', 'user_name', 'license_plate']);
            foreach ($rows as $row) {
                $row->user_id = $row->user_id === null ? null : (int) $row->user_id;
                $found[(int) $row->id] = $row;
            }
        }

        return $found;
    }

    /**
     * @param  list<int>  $ids
     * @return array<int, object>
     */
    private function usersById(array $ids): array
    {
        $found = [];
        foreach (array_chunk($ids, self::CHUNK) as $chunk) {
            $rows = DB::table('users')->whereIn('id', $chunk)->get(['id', 'name', 'email']);
            foreach ($rows as $row) {
                $found[(int) $row->id] = $row;
            }
        }

        return $found;
    }

    /**
     * @param  list<int>  $ids
     * @return array<int, string>
     */
    private function agencyNames(array $ids): array
    {
        $names = [];
        foreach ($this->usersById(array_map('intval', $ids)) as $id => $user) {
            $names[$id] = (string) $user->name;
        }

        return $names;
    }

    /**
     * @return array{
     *     emails: array<string, list<int>>,
     *     names: array<string, list<int>>,
     *     plates: array<string, list<int>>,
     *     agencies: array<int, array{email: string, name: string}>
     * }
     */
    private function readIdentityMaps(): array
    {
        $emails = [];
        $names = [];
        $agencies = [];
        DB::table('users')->select(['id', 'name', 'email'])->orderBy('id')->chunkById(self::CHUNK, function ($rows) use (&$emails, &$names, &$agencies): void {
            foreach ($rows as $row) {
                $id = (int) $row->id;
                $email = self::normalizeEmail($row->email);
                $name = self::normalizeAgencyName($row->name);
                $agencies[$id] = ['email' => $email, 'name' => $name];
                if ($email !== '') {
                    $emails[$email][] = $id;
                }
                if ($name !== '') {
                    $names[$name][] = $id;
                }
            }
        });

        $plateSets = [];
        DB::table('vehicles')
            ->select(['id', 'user_id', 'license_plate'])
            ->whereIn('status', [Vehicle::STATUS_ACTIVE, Vehicle::STATUS_REMOVED])
            ->orderBy('id')
            ->chunkById(self::CHUNK, function ($rows) use (&$plateSets): void {
                foreach ($rows as $row) {
                    $plate = DuplicateReservationAttemptService::normalizeLicensePlate($row->license_plate);
                    if ($plate === '') {
                        continue;
                    }
                    $plateSets[$plate][(int) ($row->user_id ?? 0)] = true;
                }
            });

        $plates = [];
        foreach ($plateSets as $plate => $userIds) {
            $ids = array_map('intval', array_keys($userIds));
            sort($ids);
            $plates[$plate] = $ids;
        }

        return [
            'emails' => $emails,
            'names' => $names,
            'plates' => $plates,
            'agencies' => $agencies,
        ];
    }

    /**
     * @param  list<int>  $reservationIds
     * @return array<int, true>
     */
    private function activeAuditReservationIds(array $reservationIds): array
    {
        $active = [];
        foreach (array_chunk($reservationIds, self::CHUNK) as $chunk) {
            $ids = DB::table('reservation_attributions')
                ->whereIn('active_reservation_id', $chunk)
                ->pluck('active_reservation_id');
            foreach ($ids as $id) {
                $active[(int) $id] = true;
            }
        }

        return $active;
    }
}
