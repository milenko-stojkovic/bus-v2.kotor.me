<?php

namespace App\Services\Reservation;

use App\Support\ReservationOrigin;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use RuntimeException;

/**
 * Read-only profile of the remaining historical V1 reservations.
 *
 * Discovery labels are not attribution rules. Nothing in this class writes,
 * approves, or calls an attribution writer. A Batch 3 name heuristic is not
 * used as evidence for another reservation. A reservation linked to a user
 * without an attribution audit is uncertain provenance, not proof of native
 * account creation.
 *
 * The reads share one transaction. This repository does not set an isolation
 * level. MariaDB's default REPEATABLE READ is the assumed snapshot.
 * SQLite tests run on one connection and do not prove MariaDB isolation.
 */
final class HistoricalAttributionBatch4Diagnostics
{
    public const SAMPLE_LIMIT = 5;

    private const CHUNK = 500;

    /** Last owner-verified production snapshot. Not a live query. */
    public const VERIFIED_HISTORICAL = 21342;

    public const VERIFIED_ATTRIBUTED = 9361;

    public const VERIFIED_UNATTRIBUTED = 11981;

    public const VERIFIED_BATCH1_ACTIVE = 4609;

    public const VERIFIED_BATCH2_ACTIVE = 2696;

    public const VERIFIED_BATCH3_ACTIVE = 2056;

    public const VERIFIED_BATCH3_REGULAR = 2052;

    public const VERIFIED_BATCH3_REVIEWED = 4;

    /**
     * @return array<string, mixed>
     */
    public function collect(): array
    {
        if (! Schema::hasTable('reservation_attributions')) {
            throw new RuntimeException('The reservation_attributions table is missing. This diagnostic does not migrate.');
        }

        return DB::transaction(function (): array {
            return $this->collectFromSnapshot();
        });
    }

    /**
     * @return array<string, mixed>
     */
    private function collectFromSnapshot(): array
    {
        $users = $this->loadUsers();
        $registryPlates = $this->loadRegistryPlates();
        $vehicleUsers = $this->loadVehicleUsers();
        $audits = $this->loadAudits();
        $rows = $this->loadReservations();

        $trustedEmail = [];
        $trustedPlate = [];
        $batch3Email = [];
        $reviewedEmail = [];
        $batch3Plate = [];

        $live = [
            'historical' => 0,
            'attributed' => 0,
            'unattributed' => 0,
            'batch1_active' => 0,
            'batch2_active' => 0,
            'batch3_active' => 0,
            'batch3_regular_active' => 0,
            'batch3_reviewed_active' => 0,
            'other_active' => 0,
        ];
        $reference = [
            'post_cutoff' => 0,
            'post_cutoff_user_linked' => 0,
            'post_cutoff_guest' => 0,
            'created_at_null' => 0,
        ];
        $inconsistent = [
            'unattributed_with_active_audit' => [],
            'unattributed_with_closed_audit' => [],
            'attributed_without_active_audit' => [],
            'user_id_disagrees_with_active_audit' => [],
            'active_audit_outside_cohort' => [],
        ];

        foreach ($audits as $reservationId => $auditRows) {
            if (! isset($rows[$reservationId])) {
                foreach ($auditRows as $audit) {
                    if ($audit['active']) {
                        $inconsistent['active_audit_outside_cohort'][] = $reservationId;
                        break;
                    }
                }
            }
        }

        foreach ($rows as $id => $row) {
            if (! $row['historical']) {
                if ($row['created_at'] === null) {
                    $reference['created_at_null']++;
                } else {
                    $reference['post_cutoff']++;
                    if ($row['user_id'] === null) {
                        $reference['post_cutoff_guest']++;
                    } else {
                        $reference['post_cutoff_user_linked']++;
                    }
                }
                if ($this->activeAudit($audits[$id] ?? []) !== null) {
                    $inconsistent['active_audit_outside_cohort'][] = $id;
                }

                continue;
            }

            $live['historical']++;
            $active = $this->activeAudit($audits[$id] ?? []);
            if ($row['user_id'] === null) {
                $live['unattributed']++;
            } else {
                $live['attributed']++;
            }

            if ($active === null) {
                if ($row['user_id'] !== null) {
                    $inconsistent['attributed_without_active_audit'][] = $id;
                } elseif (($audits[$id] ?? []) !== []) {
                    $inconsistent['unattributed_with_closed_audit'][] = $id;
                }

                continue;
            }

            if ($active['family'] === 'batch1') {
                $live['batch1_active']++;
            } elseif ($active['family'] === 'batch2') {
                $live['batch2_active']++;
            } elseif ($active['family'] === 'batch3' || $active['family'] === 'batch3_reviewed') {
                $live['batch3_active']++;
                if ($active['family'] === 'batch3_reviewed') {
                    $live['batch3_reviewed_active']++;
                } else {
                    $live['batch3_regular_active']++;
                }
            } else {
                $live['other_active']++;
            }

            if ($row['user_id'] === null) {
                $inconsistent['unattributed_with_active_audit'][] = $id;

                continue;
            }
            if ($row['user_id'] !== $active['agency']) {
                $inconsistent['user_id_disagrees_with_active_audit'][] = $id;

                continue;
            }

            if ($row['email'] !== '') {
                if ($active['family'] === 'batch1' || $active['family'] === 'batch2') {
                    $trustedEmail[$row['email']][$active['agency']][$id] = $active['family'];
                } elseif ($active['family'] === 'batch3') {
                    $batch3Email[$row['email']][$active['agency']][$id] = true;
                } elseif ($active['family'] === 'batch3_reviewed') {
                    $reviewedEmail[$row['email']][$active['agency']][$id] = true;
                }
            }
            if ($row['plate'] !== '') {
                if ($active['family'] === 'batch1' || $active['family'] === 'batch2') {
                    $trustedPlate[$row['plate']][$active['agency']][$id] = $active['family'];
                } elseif ($active['family'] === 'batch3' || $active['family'] === 'batch3_reviewed') {
                    $batch3Plate[$row['plate']][$active['agency']][$id] = true;
                }
            }
        }

        $quality = $this->emptyQuality();
        $categories = [
            'b4a' => [],
            'b4b' => [],
            'b4c' => [],
            'b4d' => [],
            'b4e' => [],
        ];
        $agencies = [
            'b4a' => [],
            'b4b' => [],
            'b4c' => [],
            'b4d' => [],
        ];
        $pairs = [
            'b4a_b4b' => 0,
            'b4a_b4c' => 0,
            'b4a_b4d' => 0,
            'b4b_b4c' => 0,
            'b4b_b4d' => 0,
            'b4c_b4d' => 0,
        ];
        $any = [];
        $multiple = 0;
        $contradictory = 0;
        $threeOrMore = 0;
        $weak = [
            'batch3_email_only' => 0,
            'reviewed_email_only' => 0,
            'uncertain_post_cutoff_email_only' => 0,
            'alias_without_support' => 0,
        ];
        $samples = [
            'b4a' => [],
            'b4b' => [],
            'b4c' => [],
            'b4d' => [],
            'b4e' => [],
        ];
        $open = 0;
        $excluded = 0;

        $uncertainEmail = $this->uncertainEmailCounts($rows);

        foreach ($rows as $id => $row) {
            if (! $row['historical'] || $row['user_id'] !== null) {
                continue;
            }

            $this->accumulateQuality($quality, $row, $users, $registryPlates, $trustedPlate);
            $auditRows = $audits[$id] ?? [];
            if ($auditRows !== []) {
                $excluded++;

                continue;
            }

            $open++;
            $decision = $this->decide(
                $row,
                $users,
                $registryPlates,
                $vehicleUsers,
                $trustedEmail,
                $trustedPlate,
                $batch3Email,
                $reviewedEmail,
                $uncertainEmail
            );
            foreach (['b4a', 'b4b', 'b4c', 'b4d'] as $signal) {
                if (! isset($decision['proposals'][$signal])) {
                    continue;
                }
                $categories[$signal][$id] = true;
                $agencies[$signal][$decision['proposals'][$signal]] = true;
                if (count($samples[$signal]) < self::SAMPLE_LIMIT) {
                    $samples[$signal][] = $decision['sample'];
                }
            }
            $signalCount = count($decision['proposals']);
            if ($signalCount === 0) {
                $categories['b4e'][$id] = true;
                if (count($samples['b4e']) < self::SAMPLE_LIMIT) {
                    $samples['b4e'][] = $decision['sample'];
                }
            } else {
                $any[$id] = true;
            }
            if ($signalCount >= 2) {
                $threeOrMore += $signalCount >= 3 ? 1 : 0;
                foreach (array_keys($pairs) as $pair) {
                    [$left, $right] = explode('_', $pair);
                    if (isset($decision['proposals'][$left], $decision['proposals'][$right])) {
                        $pairs[$pair]++;
                    }
                }
            }
            $proposedIds = array_values(array_unique(array_map('intval', $decision['proposals'])));
            if (count($proposedIds) > 1) {
                $multiple++;
            }
            if ($decision['contradictory']) {
                $contradictory++;
            }
            foreach ($weak as $key => $unused) {
                if (in_array($key, $decision['weak'], true)) {
                    $weak[$key]++;
                }
            }
        }

        ksort($quality['status']);
        $quality = $this->finishQuality($quality);

        return [
            'read_only' => true,
            'baseline' => $this->baseline($live),
            'live' => $live,
            'reference' => $reference,
            'inconsistencies' => $this->packInconsistencies($inconsistent),
            'discovery_population' => $open,
            'excluded_from_discovery' => $excluded,
            'quality' => $quality,
            'categories' => [
                'b4a' => ['reservations' => count($categories['b4a']), 'distinct_agencies' => count($agencies['b4a'])],
                'b4b' => ['reservations' => count($categories['b4b']), 'distinct_agencies' => count($agencies['b4b'])],
                'b4c' => ['reservations' => count($categories['b4c']), 'distinct_agencies' => count($agencies['b4c'])],
                'b4d' => ['reservations' => count($categories['b4d']), 'distinct_agencies' => count($agencies['b4d'])],
                'b4e' => ['reservations' => count($categories['b4e']), 'distinct_agencies' => 0],
            ],
            'overlap' => [
                'pairs' => $pairs,
                'three_or_more' => $threeOrMore,
                'any_of_abcd' => count($any),
                'multiple_proposed_agencies' => $multiple,
                'contradictory' => $contradictory,
                'no_credible_candidate' => count($categories['b4e']),
            ],
            'weak' => $weak,
            'samples' => $samples,
            'batch3_plate_groups' => count($batch3Plate),
        ];
    }

    /**
     * @param  array<string, int>  $live
     * @return array{matches: bool, deviations: list<string>}
     */
    private function baseline(array $live): array
    {
        $expected = [
            'historical' => self::VERIFIED_HISTORICAL,
            'attributed' => self::VERIFIED_ATTRIBUTED,
            'unattributed' => self::VERIFIED_UNATTRIBUTED,
            'batch1_active' => self::VERIFIED_BATCH1_ACTIVE,
            'batch2_active' => self::VERIFIED_BATCH2_ACTIVE,
            'batch3_active' => self::VERIFIED_BATCH3_ACTIVE,
            'batch3_regular_active' => self::VERIFIED_BATCH3_REGULAR,
            'batch3_reviewed_active' => self::VERIFIED_BATCH3_REVIEWED,
        ];
        $deviations = [];
        foreach ($expected as $key => $verified) {
            if ($live[$key] !== $verified) {
                $deviations[] = $key.': live '.$live[$key].', verified '.$verified;
            }
        }

        return [
            'matches' => $deviations === [],
            'deviations' => $deviations,
        ];
    }

    /**
     * @param  array<string, list<int>>  $groups
     * @return array<string, mixed>
     */
    private function packInconsistencies(array $groups): array
    {
        $packed = ['total' => 0];
        foreach ($groups as $key => $ids) {
            $ids = array_values(array_unique($ids));
            sort($ids);
            $packed[$key] = count($ids);
            $packed['ids'][$key] = array_slice($ids, 0, 20);
            $packed['total'] += count($ids);
        }

        return $packed;
    }

    /**
     * @param  array<int, array{email: string, name: string, alias: string}>  $users
     * @param  array<string, list<int>>  $registryPlates
     * @param  array<int, int>  $vehicleUsers
     * @param  array<string, array<int, array<int, string>>>  $trustedEmail
     * @param  array<string, array<int, array<int, string>>>  $trustedPlate
     * @param  array<string, array<int, array<int, true>>>  $batch3Email
     * @param  array<string, array<int, array<int, true>>>  $reviewedEmail
     * @param  array<string, int>  $uncertainEmail
     * @param  array<string, mixed>  $row
     * @return array<string, mixed>
     */
    private function decide(
        array $row,
        array $users,
        array $registryPlates,
        array $vehicleUsers,
        array $trustedEmail,
        array $trustedPlate,
        array $batch3Email,
        array $reviewedEmail,
        array $uncertainEmail
    ): array {
        $proposals = [];
        $supporting = [];
        $conflicting = [];
        $provenance = [];
        $ambiguity = [];
        $weak = [];
        $anchorIds = [];

        $emailOwners = $row['email'] === '' ? [] : ($users['emails'][$row['email']] ?? []);
        $registryOwners = $row['plate'] === '' ? [] : ($registryPlates[$row['plate']] ?? []);
        $trustedEmailAgencies = array_map('intval', array_keys($trustedEmail[$row['email']] ?? []));
        $trustedPlateAgencies = array_map('intval', array_keys($trustedPlate[$row['plate']] ?? []));
        $batch3Agencies = array_map('intval', array_keys($batch3Email[$row['email']] ?? []));
        $reviewedAgencies = array_map('intval', array_keys($reviewedEmail[$row['email']] ?? []));

        if ($row['email'] === '') {
            $ambiguity[] = 'email_missing';
        } elseif ($this->emailShapeIsInvalid($row['email'])) {
            $ambiguity[] = 'email_invalid_shape';
        }
        if ($row['name'] === '') {
            $ambiguity[] = 'name_missing';
        }
        if ($row['plate'] === '') {
            $ambiguity[] = 'plate_missing';
        }
        if (count($emailOwners) > 1) {
            $conflicting[] = 'email_multi_user';
        }
        if (count($trustedEmailAgencies) > 1) {
            $conflicting[] = 'trusted_email_multi_agency';
        }
        if (count($trustedPlateAgencies) > 1) {
            $conflicting[] = 'trusted_plate_multi_agency';
        }

        if (count($emailOwners) === 1) {
            $owner = $emailOwners[0];
            $ownerName = $users['by_id'][$owner]['name'] ?? '';
            if ($row['name'] !== $ownerName) {
                $proposals['b4a'] = $owner;
                $supporting[] = 'users.email';
                $provenance[] = 'users.email';
            } else {
                $ambiguity[] = 'registered_email_exact_name';
            }
        }

        if ($emailOwners === [] && $row['email'] !== '' && count($trustedEmailAgencies) === 1) {
            $agency = $trustedEmailAgencies[0];
            $proposals['b4b'] = $agency;
            foreach ($trustedEmail[$row['email']][$agency] as $anchorId => $family) {
                $anchorIds[$anchorId] = true;
                $supporting[] = $family === 'batch1' ? 'batch1_email' : 'batch2_email';
                $provenance[] = $family === 'batch1' ? 'batch1' : 'batch2';
            }
        }

        if ($emailOwners === [] && $row['email'] !== '' && $trustedEmailAgencies === []) {
            if (count($batch3Agencies) === 1) {
                $weak[] = 'batch3_email_only';
                $ambiguity[] = 'batch3_email_seen';
            } elseif ($reviewedAgencies !== []) {
                $weak[] = 'reviewed_email_only';
                $ambiguity[] = 'reviewed_name_email_seen';
            } elseif (($uncertainEmail[$row['email']] ?? 0) > 0) {
                $weak[] = 'uncertain_post_cutoff_email_only';
                $ambiguity[] = 'uncertain_post_cutoff_seen';
            }
        }
        if ($batch3Agencies !== [] && ! in_array('batch3_email_seen', $ambiguity, true)) {
            $ambiguity[] = 'batch3_email_seen';
        }

        $aliasUser = $this->uniqueAliasUser($row['name'], $row['alias'], $users);
        if ($aliasUser === -1) {
            $conflicting[] = 'alias_ambiguous';
        } elseif ($aliasUser > 0 && $this->aliasHasIndependentSupport($aliasUser, $row, $emailOwners, $trustedEmailAgencies, $registryOwners)) {
            $proposals['b4c'] = $aliasUser;
            $supporting[] = 'users.name_variation';
            $provenance[] = 'exploratory_alias';
            if (count($emailOwners) === 1 && $emailOwners[0] === $aliasUser) {
                $supporting[] = 'users.email';
            }
            if ($emailOwners === [] && $trustedEmailAgencies === [$aliasUser]) {
                $supporting[] = 'trusted_email_history';
            }
            if ($registryOwners === [$aliasUser]) {
                $supporting[] = 'vehicle_registry_plate';
                $provenance[] = 'vehicles';
            }
        } elseif ($aliasUser > 0) {
            $weak[] = 'alias_without_support';
            $ambiguity[] = 'alias_without_support';
        }

        if ($row['plate'] !== '' && count($trustedPlateAgencies) === 1) {
            $agency = $trustedPlateAgencies[0];
            $agencyName = $users['by_id'][$agency]['name'] ?? '';
            $agencyEmail = $users['by_id'][$agency]['email'] ?? '';
            $compatible = $row['name'] !== '' && $row['name'] === $agencyName;
            if (! $compatible && $aliasUser === $agency) {
                $compatible = true;
            }
            if ($compatible && $row['email'] !== $agencyEmail) {
                $proposals['b4d'] = $agency;
                $batch1Plate = 0;
                $batch2Plate = 0;
                foreach ($trustedPlate[$row['plate']][$agency] as $anchorId => $family) {
                    $anchorIds[$anchorId] = true;
                    if ($family === 'batch1') {
                        $batch1Plate++;
                        $supporting[] = 'batch1_plate';
                        $provenance[] = 'batch1';
                    } else {
                        $batch2Plate++;
                        $supporting[] = 'batch2_plate_label';
                        $provenance[] = 'batch2_plate_label';
                    }
                }
                if ($batch1Plate === 0 && $batch2Plate > 0) {
                    $ambiguity[] = 'batch2_plate_label_only';
                }
            }
        }

        $proposedIds = array_values(array_unique(array_map('intval', $proposals)));
        $vehicleUser = $row['vehicle_id'] !== null ? ($vehicleUsers[$row['vehicle_id']] ?? null) : null;
        if ($vehicleUser !== null && $vehicleUser > 0 && count($proposedIds) === 1 && $vehicleUser !== $proposedIds[0]) {
            $conflicting[] = 'vehicle_fk_other_user';
        } elseif ($vehicleUser !== null && $vehicleUser > 0 && count($proposedIds) === 1 && $vehicleUser === $proposedIds[0]) {
            $supporting[] = 'vehicle_fk';
        }
        if (count($registryOwners) === 1 && count($proposedIds) === 1 && $registryOwners[0] !== $proposedIds[0]) {
            $conflicting[] = 'registry_plate_other_agency';
        }
        if (count($proposedIds) > 1) {
            $conflicting[] = 'proposals_disagree';
        }

        $contradictory = $proposals !== [] && $conflicting !== [];
        if ($proposals === []) {
            $ambiguity[] = 'no_signal';
        }

        $references = count($anchorIds);
        if (count($proposedIds) === 1) {
            $proposed = $proposedIds[0];
            if (count($emailOwners) === 1 && $emailOwners[0] === $proposed) {
                $references++;
            }
            if ($registryOwners === [$proposed]) {
                $references++;
            }
            if ($vehicleUser !== null && $vehicleUser === $proposed) {
                $references++;
            }
        }

        $sample = [
            'reservation_id' => $row['id'],
            'proposed_user_id' => count($proposedIds) === 1 ? $proposedIds[0] : null,
            'proposed_user_ids' => $proposedIds,
            'signals' => array_keys($proposals) === [] ? ['b4e'] : array_keys($proposals),
            'supporting' => array_values(array_unique($supporting)),
            'conflicting' => array_values(array_unique($conflicting)),
            'anchor_provenance' => array_values(array_unique($provenance)),
            'independent_references' => $references,
            'ambiguity' => array_values(array_unique($ambiguity)),
            'masked_email' => self::maskEmail($row['email']),
        ];

        return [
            'proposals' => $proposals,
            'sample' => $sample,
            'contradictory' => $contradictory,
            'weak' => $weak,
        ];
    }

    /**
     * @param  array<int, array{email: string, name: string, alias: string}>  $byId
     * @param  array{by_id: array<int, array{email: string, name: string, alias: string}>, emails: array<string, list<int>>, aliases: array<string, list<int>>}  $users
     */
    private function uniqueAliasUser(string $canonical, string $alias, array $users): int
    {
        if ($alias === '') {
            return 0;
        }
        $owners = $users['aliases'][$alias] ?? [];
        $variations = [];
        foreach ($owners as $owner) {
            if (($users['by_id'][$owner]['name'] ?? '') !== $canonical) {
                $variations[] = $owner;
            }
        }
        if ($variations === []) {
            return 0;
        }
        if (count($variations) > 1) {
            return -1;
        }

        return $variations[0];
    }

    /**
     * @param  list<int>  $emailOwners
     * @param  list<int>  $trustedEmailAgencies
     * @param  list<int>  $registryOwners
     * @param  array<string, mixed>  $row
     */
    private function aliasHasIndependentSupport(int $userId, array $row, array $emailOwners, array $trustedEmailAgencies, array $registryOwners): bool
    {
        if ($emailOwners === [$userId]) {
            return true;
        }
        if ($row['email'] !== '' && $emailOwners === [] && $trustedEmailAgencies === [$userId]) {
            return true;
        }

        return $row['plate'] !== '' && $registryOwners === [$userId];
    }

    /**
     * @param  array<string, mixed>  $quality
     * @param  array<string, mixed>  $row
     * @param  array{by_id: array<int, array{email: string, name: string, alias: string}>, emails: array<string, list<int>>, names: array<string, list<int>>, aliases: array<string, list<int>>}  $users
     * @param  array<string, list<int>>  $registryPlates
     * @param  array<string, array<int, array<int, string>>>  $trustedPlate
     */
    private function accumulateQuality(array &$quality, array $row, array $users, array $registryPlates, array $trustedPlate): void
    {
        $status = $row['status'] === '' ? '(empty)' : $row['status'];
        $quality['status'][$status] = ($quality['status'][$status] ?? 0) + 1;
        if ($row['email'] === '') {
            $quality['email_empty']++;
        } else {
            $quality['email_nonempty']++;
            $quality['email_counts'][$row['email']] = ($quality['email_counts'][$row['email']] ?? 0) + 1;
            if ($this->emailShapeIsInvalid($row['email'])) {
                $quality['email_invalid_shape']++;
            }
            $owners = $users['emails'][$row['email']] ?? [];
            if (count($owners) > 1) {
                $quality['email_multi_user']++;
            }
        }
        if ($row['name'] === '') {
            $quality['name_empty']++;
        } else {
            $quality['name_nonempty']++;
            $quality['name_counts'][$row['name']] = ($quality['name_counts'][$row['name']] ?? 0) + 1;
            if (count($users['names'][$row['name']] ?? []) > 1) {
                $quality['name_multi_user']++;
            }
        }
        if ($row['alias'] !== '' && count($users['aliases'][$row['alias']] ?? []) > 1) {
            $quality['alias_multi_user']++;
        }
        if ($row['plate'] === '') {
            $quality['plate_empty']++;
        } else {
            $quality['plate_nonempty']++;
            $quality['plate_counts'][$row['plate']] = ($quality['plate_counts'][$row['plate']] ?? 0) + 1;
            if (count($registryPlates[$row['plate']] ?? []) > 1) {
                $quality['plate_multi_registry_user']++;
            }
            if (count($trustedPlate[$row['plate']] ?? []) > 1) {
                $quality['plate_multi_trusted_agency']++;
            }
        }
        if ($row['vehicle_id'] !== null) {
            $quality['vehicle_id_set']++;
        }
        if ($row['free_reservation_request_id'] !== null) {
            $quality['free_request_id_set']++;
        }
    }

    /**
     * @param  array<string, mixed>  $quality
     * @return array<string, mixed>
     */
    private function finishQuality(array $quality): array
    {
        $quality['distinct_emails'] = count($quality['email_counts']);
        $quality['distinct_names'] = count($quality['name_counts']);
        $quality['distinct_plates'] = count($quality['plate_counts']);
        $quality['repeated_emails'] = $this->repeated($quality['email_counts']);
        $quality['repeated_names'] = $this->repeated($quality['name_counts']);
        $quality['repeated_plates'] = $this->repeated($quality['plate_counts']);
        unset($quality['email_counts'], $quality['name_counts'], $quality['plate_counts']);

        return $quality;
    }

    /**
     * @param  array<string, int>  $counts
     * @return array{values: int, reservations: int, largest: int}
     */
    private function repeated(array $counts): array
    {
        $values = 0;
        $reservations = 0;
        $largest = 0;
        foreach ($counts as $count) {
            if ($count < 2) {
                continue;
            }
            $values++;
            $reservations += $count;
            if ($count > $largest) {
                $largest = $count;
            }
        }

        return [
            'values' => $values,
            'reservations' => $reservations,
            'largest' => $largest,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function emptyQuality(): array
    {
        return [
            'status' => [],
            'email_nonempty' => 0,
            'email_empty' => 0,
            'email_invalid_shape' => 0,
            'name_nonempty' => 0,
            'name_empty' => 0,
            'plate_nonempty' => 0,
            'plate_empty' => 0,
            'email_multi_user' => 0,
            'plate_multi_registry_user' => 0,
            'plate_multi_trusted_agency' => 0,
            'name_multi_user' => 0,
            'alias_multi_user' => 0,
            'vehicle_id_set' => 0,
            'free_request_id_set' => 0,
            'email_counts' => [],
            'name_counts' => [],
            'plate_counts' => [],
        ];
    }

    /**
     * @param  array<int, array<string, mixed>>  $rows
     * @return array<string, int>
     */
    private function uncertainEmailCounts(array $rows): array
    {
        $counts = [];
        foreach ($rows as $row) {
            if ($row['historical'] || $row['created_at'] === null || $row['user_id'] === null || $row['email'] === '') {
                continue;
            }
            $counts[$row['email']] = ($counts[$row['email']] ?? 0) + 1;
        }

        return $counts;
    }

    /**
     * @param  list<array{active: bool, agency: int, family: string}>  $auditRows
     * @return array{active: bool, agency: int, family: string}|null
     */
    private function activeAudit(array $auditRows): ?array
    {
        foreach ($auditRows as $audit) {
            if ($audit['active']) {
                return $audit;
            }
        }

        return null;
    }

    /**
     * @return array{by_id: array<int, array{email: string, name: string, alias: string}>, emails: array<string, list<int>>, names: array<string, list<int>>, aliases: array<string, list<int>>}
     */
    private function loadUsers(): array
    {
        $byId = [];
        $emails = [];
        $names = [];
        $aliases = [];
        DB::table('users')->select(['id', 'name', 'email'])->orderBy('id')->chunkById(self::CHUNK, function ($chunk) use (&$byId, &$emails, &$names, &$aliases): void {
            foreach ($chunk as $user) {
                $id = (int) $user->id;
                $email = HistoricalAttributionBatchDryRun::normalizeEmail((string) $user->email);
                $name = HistoricalAttributionBatchDryRun::normalizeAgencyName((string) $user->name);
                $alias = $this->exploratoryAlias($name);
                $byId[$id] = ['email' => $email, 'name' => $name, 'alias' => $alias];
                if ($email !== '') {
                    $emails[$email][] = $id;
                }
                if ($name !== '') {
                    $names[$name][] = $id;
                }
                if ($alias !== '') {
                    $aliases[$alias][] = $id;
                }
            }
        });

        return [
            'by_id' => $byId,
            'emails' => $emails,
            'names' => $names,
            'aliases' => $aliases,
        ];
    }

    /**
     * @return array<string, list<int>>
     */
    private function loadRegistryPlates(): array
    {
        $sets = [];
        DB::table('vehicles')
            ->select(['id', 'user_id', 'license_plate'])
            ->whereIn('status', ['active', 'removed'])
            ->orderBy('id')
            ->chunkById(self::CHUNK, function ($chunk) use (&$sets): void {
                foreach ($chunk as $vehicle) {
                    $plate = DuplicateReservationAttemptService::normalizeLicensePlate((string) $vehicle->license_plate);
                    $owner = (int) ($vehicle->user_id ?? 0);
                    if ($plate === '' || $owner <= 0) {
                        continue;
                    }
                    $sets[$plate][$owner] = true;
                }
            });
        $plates = [];
        foreach ($sets as $plate => $owners) {
            $ids = array_map('intval', array_keys($owners));
            sort($ids);
            $plates[$plate] = $ids;
        }

        return $plates;
    }

    /**
     * @return array<int, int>
     */
    private function loadVehicleUsers(): array
    {
        $map = [];
        DB::table('vehicles')->select(['id', 'user_id'])->orderBy('id')->chunkById(self::CHUNK, function ($chunk) use (&$map): void {
            foreach ($chunk as $vehicle) {
                $map[(int) $vehicle->id] = (int) ($vehicle->user_id ?? 0);
            }
        });

        return $map;
    }

    /**
     * @return array<int, list<array{active: bool, agency: int, family: string}>>
     */
    private function loadAudits(): array
    {
        $audits = [];
        DB::table('reservation_attributions')
            ->select(['id', 'reservation_id', 'active_reservation_id', 'agency_user_id', 'evidence'])
            ->orderBy('id')
            ->chunkById(self::CHUNK, function ($chunk) use (&$audits): void {
                foreach ($chunk as $audit) {
                    $audits[(int) $audit->reservation_id][] = [
                        'active' => $audit->active_reservation_id !== null,
                        'agency' => (int) $audit->agency_user_id,
                        'family' => $this->family((string) $audit->evidence),
                    ];
                }
            });

        return $audits;
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function loadReservations(): array
    {
        $rows = [];
        DB::table('reservations')
            ->select([
                'id',
                'user_id',
                'vehicle_id',
                'free_reservation_request_id',
                'email',
                'user_name',
                'license_plate',
                'status',
                'created_at',
            ])
            ->orderBy('id')
            ->chunkById(self::CHUNK, function ($chunk) use (&$rows): void {
                foreach ($chunk as $row) {
                    $createdAt = $row->created_at === null || $row->created_at === '' ? null : (string) $row->created_at;
                    $name = HistoricalAttributionBatchDryRun::normalizeAgencyName((string) $row->user_name);
                    $rows[(int) $row->id] = [
                        'id' => (int) $row->id,
                        'user_id' => $row->user_id === null ? null : (int) $row->user_id,
                        'vehicle_id' => $row->vehicle_id === null ? null : (int) $row->vehicle_id,
                        'free_reservation_request_id' => $row->free_reservation_request_id === null ? null : (int) $row->free_reservation_request_id,
                        'email' => HistoricalAttributionBatchDryRun::normalizeEmail((string) $row->email),
                        'name' => $name,
                        'alias' => $this->exploratoryAlias($name),
                        'plate' => DuplicateReservationAttemptService::normalizeLicensePlate((string) $row->license_plate),
                        'status' => trim((string) $row->status),
                        'created_at' => $createdAt,
                        'historical' => ReservationOrigin::isImportedV1($createdAt),
                    ];
                }
            });

        return $rows;
    }

    private function family(string $evidence): string
    {
        if ($evidence === HistoricalAttributionBatchWriter::EVIDENCE) {
            return 'batch1';
        }
        if (in_array($evidence, array_values(HistoricalAttributionBatch2Preflight::EVIDENCE_BY_CATEGORY), true)) {
            return 'batch2';
        }
        if ($evidence === HistoricalAttributionBatch3Catalog::EVIDENCE) {
            return 'batch3';
        }
        if ($evidence === HistoricalAttributionBatch3Catalog::EVIDENCE_REVIEWED) {
            return 'batch3_reviewed';
        }

        return 'other';
    }

    /**
     * Exploratory comparison only. Not a canonical normalizer and not an
     * attribution rule. Dots are removed so "d.o.o." becomes the token "doo".
     * Diacritics are kept. A key of fewer than two tokens is not an alias.
     */
    private function exploratoryAlias(string $canonical): string
    {
        if ($canonical === '') {
            return '';
        }
        $work = str_replace(['.', '\'', '’'], '', $canonical);
        $work = preg_replace('/[^\p{L}\p{N}]+/u', ' ', $work) ?? $work;
        $work = trim(preg_replace('/\s+/u', ' ', $work) ?? $work);
        if ($work === '') {
            return '';
        }
        $drop = ['doo' => true, 'ltd' => true, 'llc' => true, 'inc' => true, 'gmbh' => true];
        $kept = [];
        foreach (explode(' ', $work) as $token) {
            if ($token === '' || isset($drop[$token])) {
                continue;
            }
            $kept[] = $token;
        }
        if (count($kept) < 2) {
            return '';
        }
        sort($kept, SORT_STRING);

        return implode(' ', $kept);
    }

    private function emailShapeIsInvalid(string $normalized): bool
    {
        return preg_match('/^[^\s@]+@[^\s@]+\.[^\s@]+$/', $normalized) !== 1;
    }

    public static function maskEmail(string $normalized): string
    {
        if ($normalized === '') {
            return '';
        }
        if (! str_contains($normalized, '@')) {
            return '***';
        }
        [$local, $domain] = explode('@', $normalized, 2);
        $first = $local === '' ? '' : mb_substr($local, 0, 1);

        return $first.'***@'.$domain;
    }
}
