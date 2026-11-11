<?php

namespace App\Services\Reservation;

use App\Models\Vehicle;
use App\Support\ReservationOrigin;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use RuntimeException;

/**
 * Read-only classification of unattributed imported V1 reservations.
 *
 * An identity candidate is a row whose normalized email and normalized
 * agency name each belong to the same single user. Plate ownership is a
 * label, not a requirement. This class does not decide that a row is safe
 * to attribute, and it does not write reservations or audit rows.
 */
final class HistoricalAttributionBatch2DryRun
{
    /** @var list<string> */
    public const CATEGORIES = ['R', 'A1', 'A2', 'B', 'C', 'D', 'E', 'F'];

    /** @var list<string> */
    public const IDENTITY_CATEGORIES = ['R', 'A1', 'A2', 'B', 'C'];

    private const CHUNK = 500;

    /**
     * @return array{
     *     scanned: int,
     *     excluded_active_audit: int,
     *     eligible: int,
     *     counts: array<string, int>,
     *     category_sum: int,
     *     identity_candidates: int,
     *     distinct_agencies: int,
     *     agencies_by_category: array<string, int>,
     *     a1_other_agency: int,
     *     a1_extra_null_owner: int,
     *     candidates: list<array{reservation_id: int, proposed_user_id: int, plate_category: string}>
     * }
     */
    public function classify(): array
    {
        if (! Schema::hasTable('reservation_attributions')) {
            throw new RuntimeException('Table reservation_attributions is missing. The approved Phase 1 migration must already be applied. This dry-run does not migrate.');
        }

        $snapshot = $this->readIdentityMaps();
        $activeAudits = $this->activeAuditReservationIds();

        $counts = array_fill_keys(self::CATEGORIES, 0);
        $agencySets = [];
        foreach (self::IDENTITY_CATEGORIES as $category) {
            $agencySets[$category] = [];
        }
        $allAgencies = [];
        $candidates = [];
        $scanned = 0;
        $excludedActiveAudit = 0;
        $a1OtherAgency = 0;
        $a1ExtraNullOwner = 0;

        DB::table('reservations')
            ->select(['id', 'user_id', 'created_at', 'email', 'user_name', 'license_plate'])
            ->where('created_at', '<', ReservationOrigin::IMPORTED_BEFORE)
            ->whereNull('user_id')
            ->chunkById(self::CHUNK, function ($rows) use (
                $snapshot,
                $activeAudits,
                &$counts,
                &$agencySets,
                &$allAgencies,
                &$candidates,
                &$scanned,
                &$excludedActiveAudit,
                &$a1OtherAgency,
                &$a1ExtraNullOwner,
            ): void {
                foreach ($rows as $row) {
                    if (! ReservationOrigin::isImportedV1($row->created_at)) {
                        continue;
                    }

                    $reservationId = (int) $row->id;
                    $scanned++;
                    if (isset($activeAudits[$reservationId])) {
                        $excludedActiveAudit++;
                        continue;
                    }

                    $classified = $this->classifyRow($row->email, $row->user_name, $row->license_plate, $snapshot);
                    $category = $classified['category'];
                    $counts[$category]++;

                    if (! in_array($category, self::IDENTITY_CATEGORIES, true)) {
                        continue;
                    }

                    $agencyId = $classified['agency_id'];
                    $agencySets[$category][$agencyId] = true;
                    $allAgencies[$agencyId] = true;
                    $candidates[] = [
                        'reservation_id' => $reservationId,
                        'proposed_user_id' => $agencyId,
                        'plate_category' => $category,
                    ];
                    if ($category === 'A1' && $classified['a1_split'] === 'other_agency') {
                        $a1OtherAgency++;
                    }
                    if ($category === 'A1' && $classified['a1_split'] === 'null_only') {
                        $a1ExtraNullOwner++;
                    }
                }
            });

        usort($candidates, static fn (array $left, array $right): int => $left['reservation_id'] <=> $right['reservation_id']);

        $agenciesByCategory = [];
        foreach (self::IDENTITY_CATEGORIES as $category) {
            $agenciesByCategory[$category] = count($agencySets[$category]);
        }

        $categorySum = array_sum($counts);
        $identityCandidates = 0;
        foreach (self::IDENTITY_CATEGORIES as $category) {
            $identityCandidates += $counts[$category];
        }

        return [
            'scanned' => $scanned,
            'excluded_active_audit' => $excludedActiveAudit,
            'eligible' => $scanned - $excludedActiveAudit,
            'counts' => $counts,
            'category_sum' => $categorySum,
            'identity_candidates' => $identityCandidates,
            'distinct_agencies' => count($allAgencies),
            'agencies_by_category' => $agenciesByCategory,
            'a1_other_agency' => $a1OtherAgency,
            'a1_extra_null_owner' => $a1ExtraNullOwner,
            'candidates' => $candidates,
        ];
    }

    /**
     * One read of the current user and vehicle identity maps.
     * The same maps classify() uses. Callers must not treat the result as
     * still unique after another account is created or renamed.
     *
     * @return array{
     *     emails: array<string, list<int>>,
     *     names: array<string, list<int>>,
     *     plates: array<string, list<int>>
     * }
     */
    public function currentIdentitySnapshot(): array
    {
        return $this->readIdentityMaps();
    }

    /**
     * @param  array{
     *     emails: array<string, list<int>>,
     *     names: array<string, list<int>>,
     *     plates: array<string, list<int>>
     * }  $snapshot
     * @return array{category: string, agency_id: int, a1_split: ?string}
     */
    public function classifyIdentity(mixed $email, mixed $userName, mixed $plate, array $snapshot): array
    {
        return $this->classifyRow($email, $userName, $plate, $snapshot);
    }

    /**
     * @param  array{
     *     emails: array<string, list<int>>,
     *     names: array<string, list<int>>,
     *     plates: array<string, list<int>>
     * }  $snapshot
     * @return array{category: string, agency_id: int, a1_split: ?string}
     */
    private function classifyRow(mixed $email, mixed $userName, mixed $plate, array $snapshot): array
    {
        $normalizedEmail = HistoricalAttributionBatchDryRun::normalizeEmail(is_string($email) ? $email : (string) $email);
        $normalizedName = HistoricalAttributionBatchDryRun::normalizeAgencyName(is_string($userName) ? $userName : (string) $userName);
        $emailOwners = $normalizedEmail === '' ? [] : ($snapshot['emails'][$normalizedEmail] ?? []);
        $nameOwners = $normalizedName === '' ? [] : ($snapshot['names'][$normalizedName] ?? []);

        if (count($emailOwners) === 1 && count($nameOwners) === 1 && $emailOwners[0] === $nameOwners[0]) {
            $agencyId = $emailOwners[0];
            $normalizedPlate = DuplicateReservationAttemptService::normalizeLicensePlate(is_string($plate) ? $plate : (string) $plate);
            $owners = $normalizedPlate === '' ? [] : ($snapshot['plates'][$normalizedPlate] ?? []);

            return $this->plateLabel($agencyId, $owners);
        }

        if (count($emailOwners) === 1) {
            return ['category' => 'D', 'agency_id' => 0, 'a1_split' => null];
        }

        if ($emailOwners === [] && count($nameOwners) === 1) {
            return ['category' => 'E', 'agency_id' => 0, 'a1_split' => null];
        }

        return ['category' => 'F', 'agency_id' => 0, 'a1_split' => null];
    }

    /**
     * @param  list<int>  $owners
     * @return array{category: string, agency_id: int, a1_split: ?string}
     */
    private function plateLabel(int $agencyId, array $owners): array
    {
        if ($owners === [$agencyId]) {
            return ['category' => 'R', 'agency_id' => $agencyId, 'a1_split' => null];
        }

        if (count($owners) >= 2 && in_array($agencyId, $owners, true)) {
            $otherAgency = false;
            foreach ($owners as $ownerId) {
                if ($ownerId > 0 && $ownerId !== $agencyId) {
                    $otherAgency = true;
                    break;
                }
            }

            return [
                'category' => 'A1',
                'agency_id' => $agencyId,
                'a1_split' => $otherAgency ? 'other_agency' : 'null_only',
            ];
        }

        if (count($owners) >= 2) {
            return ['category' => 'A2', 'agency_id' => $agencyId, 'a1_split' => null];
        }

        if ($owners === [] || $owners === [0]) {
            return ['category' => 'B', 'agency_id' => $agencyId, 'a1_split' => null];
        }

        return ['category' => 'C', 'agency_id' => $agencyId, 'a1_split' => null];
    }

    /**
     * @return array<int, true>
     */
    private function activeAuditReservationIds(): array
    {
        $active = [];
        foreach (DB::table('reservation_attributions')->whereNotNull('active_reservation_id')->pluck('active_reservation_id') as $id) {
            $active[(int) $id] = true;
        }

        return $active;
    }

    /**
     * Same snapshot rules as the Batch 1 dry-run: one read of users and one
     * read of active and removed vehicles. A null vehicle user id is owner 0.
     *
     * @return array{
     *     emails: array<string, list<int>>,
     *     names: array<string, list<int>>,
     *     plates: array<string, list<int>>
     * }
     */
    private function readIdentityMaps(): array
    {
        $emails = [];
        $names = [];
        DB::table('users')->select(['id', 'name', 'email'])->orderBy('id')->chunkById(self::CHUNK, function ($rows) use (&$emails, &$names): void {
            foreach ($rows as $row) {
                $id = (int) $row->id;
                $email = HistoricalAttributionBatchDryRun::normalizeEmail($row->email);
                $name = HistoricalAttributionBatchDryRun::normalizeAgencyName($row->name);
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
        ];
    }
}
