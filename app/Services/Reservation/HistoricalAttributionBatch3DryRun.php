<?php

namespace App\Services\Reservation;

use App\Support\ReservationOrigin;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use RuntimeException;

/**
 * Read-only name heuristic for imported V1 reservations.
 *
 * Class name_unique_email_unregistered is the candidate: one normalized
 * agency name, a non-empty reservation email that belongs to no user, and
 * no disqualifying attribution state. Plate ownership is not read.
 * This class does not attribute reservations.
 */
final class HistoricalAttributionBatch3DryRun
{
    /** @var list<string> */
    public const CLASSES = [
        'already_attributed',
        'active_audit_without_user',
        'closed_batch2_audit',
        'closed_account_deleted',
        'closed_batch1_audit',
        'other_closed_audit',
        'identity_email_and_name',
        'name_unique_email_unregistered',
        'name_unique_email_other',
        'name_unique_email_missing',
        'name_ambiguous',
        'name_absent',
        'name_unmatched',
    ];

    /** @var list<string> */
    public const EMAIL_SUBCOUNT_CLASSES = [
        'name_ambiguous',
        'name_absent',
        'name_unmatched',
    ];

    /** @var list<string> */
    public const EMAIL_SHAPES = [
        'missing',
        'unregistered',
        'one_owner',
        'several_owners',
    ];

    /** @var list<string> */
    public const CLASS9_PARTS = [
        'email_unique_other_user',
        'email_includes_name_owner',
        'email_multiple_other_users',
    ];

    private const CHUNK = 500;

    private const CANDIDATE = 'name_unique_email_unregistered';

    /**
     * @return array{
     *     cohort: int,
     *     user_id_set: int,
     *     user_id_null: int,
     *     counts: array<string, int>,
     *     class_sum: int,
     *     class8_agencies: int,
     *     class8_by_agency: list<array{user_id: int, reservations: int, distinct_emails: int}>,
     *     class9_parts: array<string, int>,
     *     email_subcounts: array<string, array<string, int>>,
     *     class8_country_comparable: int,
     *     class8_country_mismatch: int,
     *     class8_country_not_comparable: int,
     *     class8_gmail_dot_alias: int,
     *     class8_plus_alias: int,
     *     class8_shared_emails: int,
     *     class8_shared_email_reservations: int,
     *     reconciliation_passed: bool,
     *     rows: list<array{reservation_id: int, class: string, name_owner_user_id: ?int}>
     * }
     */
    public function classify(): array
    {
        if (! Schema::hasTable('reservation_attributions')) {
            throw new RuntimeException('Table reservation_attributions is missing. The approved Phase 1 migration must already be applied. This dry-run does not migrate.');
        }

        $snapshot = app(HistoricalAttributionBatch2DryRun::class)->currentIdentitySnapshot();
        $countries = $this->agencyCountries();
        $audits = $this->auditsByReservation();

        $counts = array_fill_keys(self::CLASSES, 0);
        $class9Parts = array_fill_keys(self::CLASS9_PARTS, 0);
        $emailSubcounts = [];
        foreach (self::EMAIL_SUBCOUNT_CLASSES as $class) {
            $emailSubcounts[$class] = array_fill_keys(self::EMAIL_SHAPES, 0);
        }

        /** @var array<int, array{reservations: int, emails: array<string, true>}> $agencyStats */
        $agencyStats = [];
        /** @var array<string, array<int, true>> $emailAgencies */
        $emailAgencies = [];
        /** @var array<string, int> $emailReservations */
        $emailReservations = [];
        $rows = [];
        $cohort = 0;
        $userIdSet = 0;
        $countryComparable = 0;
        $countryMismatch = 0;
        $countryNotComparable = 0;
        $gmailDot = 0;
        $plusAlias = 0;

        DB::table('reservations')
            ->select(['id', 'user_id', 'created_at', 'email', 'user_name', 'country'])
            ->where('created_at', '<', ReservationOrigin::IMPORTED_BEFORE)
            ->chunkById(self::CHUNK, function ($chunk) use (
                $snapshot,
                $countries,
                $audits,
                &$counts,
                &$class9Parts,
                &$emailSubcounts,
                &$agencyStats,
                &$emailAgencies,
                &$emailReservations,
                &$rows,
                &$cohort,
                &$userIdSet,
                &$countryComparable,
                &$countryMismatch,
                &$countryNotComparable,
                &$gmailDot,
                &$plusAlias,
            ): void {
                foreach ($chunk as $row) {
                    if (! ReservationOrigin::isImportedV1($row->created_at)) {
                        continue;
                    }

                    $reservationId = (int) $row->id;
                    $userId = $this->userId($row->user_id);
                    $cohort++;
                    if ($userId !== null) {
                        $userIdSet++;
                    }

                    $normalizedEmail = HistoricalAttributionBatchDryRun::normalizeEmail(is_string($row->email) ? $row->email : (string) $row->email);
                    $normalizedName = HistoricalAttributionBatchDryRun::normalizeAgencyName(is_string($row->user_name) ? $row->user_name : (string) $row->user_name);
                    $emailOwners = $normalizedEmail === '' ? [] : ($snapshot['emails'][$normalizedEmail] ?? []);
                    $nameOwners = $normalizedName === '' ? [] : ($snapshot['names'][$normalizedName] ?? []);
                    $decision = $this->decide($userId, $audits[$reservationId] ?? [], $normalizedEmail, $normalizedName, $emailOwners, $nameOwners);

                    $counts[$decision['class']]++;
                    if ($decision['class9_part'] !== null) {
                        $class9Parts[$decision['class9_part']]++;
                    }
                    if (isset($emailSubcounts[$decision['class']])) {
                        $emailSubcounts[$decision['class']][$decision['email_shape']]++;
                    }

                    $nameOwner = $decision['name_owner_user_id'];
                    if ($decision['class'] === self::CANDIDATE && $nameOwner !== null) {
                        $agencyStats[$nameOwner]['reservations'] = ($agencyStats[$nameOwner]['reservations'] ?? 0) + 1;
                        $agencyStats[$nameOwner]['emails'][$normalizedEmail] = true;
                        $emailAgencies[$normalizedEmail][$nameOwner] = true;
                        $emailReservations[$normalizedEmail] = ($emailReservations[$normalizedEmail] ?? 0) + 1;
                        $this->countCountry($countries[$nameOwner] ?? null, $row->country, $countryComparable, $countryMismatch, $countryNotComparable);
                        $alias = $this->aliasHits($normalizedEmail, $snapshot['emails']);
                        if ($alias['gmail_dot']) {
                            $gmailDot++;
                        }
                        if ($alias['plus']) {
                            $plusAlias++;
                        }
                    }

                    $rows[] = [
                        'reservation_id' => $reservationId,
                        'class' => $decision['class'],
                        'name_owner_user_id' => $nameOwner,
                    ];
                }
            });

        usort($rows, static fn (array $left, array $right): int => $left['reservation_id'] <=> $right['reservation_id']);

        $byAgency = [];
        foreach ($agencyStats as $agencyId => $stats) {
            $byAgency[] = [
                'user_id' => (int) $agencyId,
                'reservations' => $stats['reservations'],
                'distinct_emails' => count($stats['emails']),
            ];
        }
        usort($byAgency, static fn (array $left, array $right): int => $left['user_id'] <=> $right['user_id']);

        $sharedEmails = 0;
        $sharedReservations = 0;
        foreach ($emailAgencies as $email => $owners) {
            if (count($owners) < 2) {
                continue;
            }
            $sharedEmails++;
            $sharedReservations += $emailReservations[$email];
        }

        $classSum = array_sum($counts);
        $userIdNull = $cohort - $userIdSet;
        $reconciliationPassed = $classSum === $cohort
            && $counts['already_attributed'] === $userIdSet
            && ($classSum - $counts['already_attributed']) === $userIdNull
            && array_sum($class9Parts) === $counts['name_unique_email_other']
            && $this->subcountsMatch($emailSubcounts, $counts)
            && ($countryComparable + $countryNotComparable) === $counts[self::CANDIDATE]
            && $countryMismatch <= $countryComparable;

        return [
            'cohort' => $cohort,
            'user_id_set' => $userIdSet,
            'user_id_null' => $userIdNull,
            'counts' => $counts,
            'class_sum' => $classSum,
            'class8_agencies' => count($byAgency),
            'class8_by_agency' => $byAgency,
            'class9_parts' => $class9Parts,
            'email_subcounts' => $emailSubcounts,
            'class8_country_comparable' => $countryComparable,
            'class8_country_mismatch' => $countryMismatch,
            'class8_country_not_comparable' => $countryNotComparable,
            'class8_gmail_dot_alias' => $gmailDot,
            'class8_plus_alias' => $plusAlias,
            'class8_shared_emails' => $sharedEmails,
            'class8_shared_email_reservations' => $sharedReservations,
            'reconciliation_passed' => $reconciliationPassed,
            'rows' => $rows,
        ];
    }

    /**
     * Closed-audit precedence: Batch 2 evidence, then account deletion,
     * then Batch 1 evidence, then any other closed audit. An active audit
     * wins over every closed row. A set user_id wins over every audit.
     *
     * @param  list<object>  $audits
     * @param  list<int>  $emailOwners
     * @param  list<int>  $nameOwners
     * @return array{class: string, class9_part: ?string, email_shape: string, name_owner_user_id: ?int}
     */
    private function decide(?int $userId, array $audits, string $normalizedEmail, string $normalizedName, array $emailOwners, array $nameOwners): array
    {
        $emailShape = $this->emailShape($normalizedEmail, $emailOwners);
        $nameOwner = count($nameOwners) === 1 ? $nameOwners[0] : null;
        $state = $this->stateClass($userId, $audits);
        if ($state !== null) {
            return [
                'class' => $state,
                'class9_part' => null,
                'email_shape' => $emailShape,
                'name_owner_user_id' => null,
            ];
        }

        if (count($emailOwners) === 1 && $nameOwner !== null && $emailOwners[0] === $nameOwner) {
            return $this->nameDecision('identity_email_and_name', null, $emailShape, $nameOwner);
        }

        if ($nameOwner !== null && $normalizedEmail === '') {
            return $this->nameDecision('name_unique_email_missing', null, $emailShape, $nameOwner);
        }

        if ($nameOwner !== null && $emailOwners === []) {
            return $this->nameDecision(self::CANDIDATE, null, $emailShape, $nameOwner);
        }

        if ($nameOwner !== null) {
            return $this->nameDecision('name_unique_email_other', $this->class9Part($nameOwner, $emailOwners), $emailShape, $nameOwner);
        }

        if (count($nameOwners) >= 2) {
            return $this->nameDecision('name_ambiguous', null, $emailShape, null);
        }

        if ($normalizedName === '') {
            return $this->nameDecision('name_absent', null, $emailShape, null);
        }

        return $this->nameDecision('name_unmatched', null, $emailShape, null);
    }

    /**
     * @return array{class: string, class9_part: ?string, email_shape: string, name_owner_user_id: ?int}
     */
    private function nameDecision(string $class, ?string $class9Part, string $emailShape, ?int $nameOwner): array
    {
        return [
            'class' => $class,
            'class9_part' => $class9Part,
            'email_shape' => $emailShape,
            'name_owner_user_id' => $nameOwner,
        ];
    }

    /**
     * @param  list<object>  $audits
     */
    private function stateClass(?int $userId, array $audits): ?string
    {
        if ($userId !== null) {
            return 'already_attributed';
        }

        foreach ($audits as $audit) {
            if ($audit->active_reservation_id !== null) {
                return 'active_audit_without_user';
            }
        }

        if ($this->closedHasEvidence($audits, array_values(HistoricalAttributionBatch2Preflight::EVIDENCE_BY_CATEGORY))) {
            return 'closed_batch2_audit';
        }

        if ($this->closedHasReversal($audits, HistoricalReservationAttributionService::ACCOUNT_DELETED_EVIDENCE)) {
            return 'closed_account_deleted';
        }

        if ($this->closedHasEvidence($audits, [HistoricalAttributionBatchWriter::EVIDENCE])) {
            return 'closed_batch1_audit';
        }

        if ($audits !== []) {
            return 'other_closed_audit';
        }

        return null;
    }

    /**
     * @param  list<object>  $audits
     * @param  list<string>  $evidence
     */
    private function closedHasEvidence(array $audits, array $evidence): bool
    {
        foreach ($audits as $audit) {
            if ($audit->active_reservation_id === null && in_array((string) $audit->evidence, $evidence, true)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param  list<object>  $audits
     */
    private function closedHasReversal(array $audits, string $reversal): bool
    {
        foreach ($audits as $audit) {
            if ($audit->active_reservation_id === null && (string) $audit->reversal_evidence === $reversal) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param  list<int>  $emailOwners
     */
    private function class9Part(int $nameOwner, array $emailOwners): string
    {
        if (count($emailOwners) === 1 && $emailOwners[0] !== $nameOwner) {
            return 'email_unique_other_user';
        }

        if (in_array($nameOwner, $emailOwners, true)) {
            return 'email_includes_name_owner';
        }

        return 'email_multiple_other_users';
    }

    /**
     * @param  list<int>  $emailOwners
     */
    private function emailShape(string $normalizedEmail, array $emailOwners): string
    {
        if ($normalizedEmail === '') {
            return 'missing';
        }
        if ($emailOwners === []) {
            return 'unregistered';
        }
        if (count($emailOwners) === 1) {
            return 'one_owner';
        }

        return 'several_owners';
    }

    private function countCountry(mixed $agencyCountry, mixed $reservationCountry, int &$comparable, int &$mismatch, int &$notComparable): void
    {
        $agency = $this->comparableCountry($agencyCountry);
        $reservation = $this->comparableCountry($reservationCountry);
        if ($agency === null || $reservation === null) {
            $notComparable++;

            return;
        }

        $comparable++;
        if ($agency !== $reservation) {
            $mismatch++;
        }
    }

    private function comparableCountry(mixed $value): ?string
    {
        $country = trim((string) $value);
        if ($country === '') {
            return null;
        }

        return mb_strtoupper($country);
    }

    /**
     * Diagnostics only. Dot-folding and plus-tags are not the email normalizer,
     * so a hit does not move the row out of the candidate class.
     *
     * @param  array<string, list<int>>  $registeredEmails
     * @return array{gmail_dot: bool, plus: bool}
     */
    private function aliasHits(string $normalizedEmail, array $registeredEmails): array
    {
        $hit = ['gmail_dot' => false, 'plus' => false];
        if (! str_contains($normalizedEmail, '@')) {
            return $hit;
        }

        [$local, $domain] = explode('@', $normalizedEmail, 2);
        if ($local === '' || $domain === '') {
            return $hit;
        }

        $plusLocal = $local;
        $plusCut = strstr($local, '+', true);
        if ($plusCut !== false && $plusCut !== '') {
            $plusLocal = $plusCut;
            $plusVariant = $plusLocal.'@'.$domain;
            if ($plusVariant !== $normalizedEmail && isset($registeredEmails[$plusVariant])) {
                $hit['plus'] = true;
            }
        }

        if ($domain !== 'gmail.com' && $domain !== 'googlemail.com') {
            return $hit;
        }

        $dotLocal = str_replace('.', '', $local);
        if ($dotLocal !== '' && $dotLocal !== $local && isset($registeredEmails[$dotLocal.'@'.$domain])) {
            $hit['gmail_dot'] = true;
        }

        if ($plusLocal !== $local) {
            $folded = str_replace('.', '', $plusLocal);
            $foldedVariant = $folded.'@'.$domain;
            if ($folded !== '' && $foldedVariant !== $normalizedEmail && isset($registeredEmails[$foldedVariant])) {
                $hit['gmail_dot'] = true;
                $hit['plus'] = true;
            }
        }

        return $hit;
    }

    /**
     * @param  array<string, array<string, int>>  $emailSubcounts
     * @param  array<string, int>  $counts
     */
    private function subcountsMatch(array $emailSubcounts, array $counts): bool
    {
        foreach (self::EMAIL_SUBCOUNT_CLASSES as $class) {
            if (array_sum($emailSubcounts[$class]) !== $counts[$class]) {
                return false;
            }
        }

        return true;
    }

    private function userId(mixed $value): ?int
    {
        if ($value === null || $value === '') {
            return null;
        }

        return (int) $value;
    }

    /**
     * @return array<int, ?string>
     */
    private function agencyCountries(): array
    {
        $countries = [];
        DB::table('users')->select(['id', 'country'])->orderBy('id')->chunkById(self::CHUNK, function ($rows) use (&$countries): void {
            foreach ($rows as $row) {
                $countries[(int) $row->id] = $row->country === null ? null : (string) $row->country;
            }
        });

        return $countries;
    }

    /**
     * @return array<int, list<object>>
     */
    private function auditsByReservation(): array
    {
        $grouped = [];
        DB::table('reservation_attributions')
            ->select(['id', 'reservation_id', 'evidence', 'active_reservation_id', 'reversal_evidence'])
            ->orderBy('id')
            ->chunkById(self::CHUNK, function ($rows) use (&$grouped): void {
                foreach ($rows as $row) {
                    $grouped[(int) $row->reservation_id][] = $row;
                }
            });

        return $grouped;
    }
}
