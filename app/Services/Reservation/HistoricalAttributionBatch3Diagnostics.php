<?php

namespace App\Services\Reservation;

use Illuminate\Support\Facades\DB;

/**
 * Read-only review of Batch 3 class 8 rows.
 *
 * Membership comes from HistoricalAttributionBatch3DryRun. This class does
 * not reclassify reservations and does not remove a flagged row.
 */
final class HistoricalAttributionBatch3Diagnostics
{
    /** @var list<string> */
    public const CATEGORY_E_NOTE = [
        'The earlier category E count of 2054 is not recomputed by this command.',
        'Category E came from the Batch 2 dry-run: a unique normalized name, and an email that matched no registered user. A blank email was treated as no match. That run excluded active audits only.',
        'It did not apply the closed Batch 2, account-deletion, closed Batch 1, or other closed-audit holds, and it was not repeated after Batch 2.',
        'Class 8 keeps a unique name only when the email is non-empty, matches no user, user_id is null, and those audit holds are absent. The registry is the one read on this run.',
        'The reported class 8 count of 2060 and the earlier 2054 are not the same set. A difference of six does not identify six rows added to category E.',
    ];

    private const CHUNK = 500;

    /**
     * @param  list<int>  $agencyIds
     * @return array<string, mixed>
     */
    public function collect(array $agencyIds): array
    {
        // One snapshot for the classifier and the later reservation read.
        // A commit of this read-only transaction does not change rows.
        return DB::transaction(function () use ($agencyIds): array {
            return $this->collectFromSnapshot($agencyIds);
        });
    }

    /**
     * @param  list<int>  $agencyIds
     * @return array<string, mixed>
     */
    private function collectFromSnapshot(array $agencyIds): array
    {
        $classification = app(HistoricalAttributionBatch3DryRun::class)->classify();
        $assignments = [];
        foreach ($classification['rows'] as $row) {
            if ($row['class'] !== 'name_unique_email_unregistered' || $row['name_owner_user_id'] === null) {
                continue;
            }
            $assignments[(int) $row['reservation_id']] = (int) $row['name_owner_user_id'];
        }

        $loaded = $this->loadReservations(array_keys($assignments));
        $agencies = $this->loadAgencies(array_values(array_unique(array_merge($agencyIds, array_values($assignments)))));
        $shared = $this->sharedEmails($assignments, $loaded);
        $mismatches = $this->countryMismatches($assignments, $loaded, $agencies);
        $focused = $this->focusedAgencies($agencyIds, $assignments, $loaded, $agencies);
        $names = $this->nameObservations($assignments, $agencies);

        $sharedReservations = 0;
        foreach ($shared as $email) {
            $sharedReservations += $email['reservations'];
        }

        $recountAgrees = count($loaded) === count($assignments)
            && count($shared) === $classification['class8_shared_emails']
            && $sharedReservations === $classification['class8_shared_email_reservations']
            && count($mismatches) === $classification['class8_country_mismatch']
            && $this->agencyTotalsAgree($classification['class8_by_agency'], $focused);

        return [
            'reconciliation_passed' => $classification['reconciliation_passed'] === true,
            'recount_agrees' => $recountAgrees,
            'class8' => count($assignments),
            'loaded' => count($loaded),
            'shared_emails' => $shared,
            'country_mismatches' => $mismatches,
            'agencies' => $focused,
            'name_observations' => $names,
        ];
    }

    /**
     * @param  list<int>  $ids
     * @return array<int, object>
     */
    private function loadReservations(array $ids): array
    {
        $loaded = [];
        foreach (array_chunk($ids, self::CHUNK) as $chunk) {
            $rows = DB::table('reservations')
                ->whereIn('id', $chunk)
                ->get(['id', 'email', 'country', 'reservation_date']);
            foreach ($rows as $row) {
                $loaded[(int) $row->id] = $row;
            }
        }

        return $loaded;
    }

    /**
     * @param  list<int>  $ids
     * @return array<int, object>
     */
    private function loadAgencies(array $ids): array
    {
        $loaded = [];
        foreach (array_chunk($ids, self::CHUNK) as $chunk) {
            if ($chunk === []) {
                continue;
            }
            $rows = DB::table('users')->whereIn('id', $chunk)->get(['id', 'name', 'country']);
            foreach ($rows as $row) {
                $loaded[(int) $row->id] = $row;
            }
        }

        return $loaded;
    }

    /**
     * @param  array<int, int>  $assignments
     * @param  array<int, object>  $loaded
     * @return list<array{label: string, email: string, reservations: int, agencies: list<array{agency_id: int, reservations: int}>}>
     */
    private function sharedEmails(array $assignments, array $loaded): array
    {
        $counts = [];
        foreach ($assignments as $reservationId => $agencyId) {
            if (! isset($loaded[$reservationId])) {
                continue;
            }
            $email = HistoricalAttributionBatchDryRun::normalizeEmail((string) $loaded[$reservationId]->email);
            if ($email === '') {
                continue;
            }
            $counts[$email][$agencyId] = ($counts[$email][$agencyId] ?? 0) + 1;
        }

        $shared = [];
        foreach ($counts as $email => $agencies) {
            if (count($agencies) < 2) {
                continue;
            }
            $agencyRows = [];
            $reservations = 0;
            foreach ($agencies as $agencyId => $count) {
                $agencyRows[] = ['agency_id' => (int) $agencyId, 'reservations' => $count];
                $reservations += $count;
            }
            usort($agencyRows, static fn (array $left, array $right): int => $left['agency_id'] <=> $right['agency_id']);
            $shared[] = [
                'email' => $email,
                'reservations' => $reservations,
                'agencies' => $agencyRows,
            ];
        }

        usort($shared, static function (array $left, array $right): int {
            $byAgency = $left['agencies'][0]['agency_id'] <=> $right['agencies'][0]['agency_id'];
            if ($byAgency !== 0) {
                return $byAgency;
            }
            $byCount = $right['reservations'] <=> $left['reservations'];
            if ($byCount !== 0) {
                return $byCount;
            }

            return $left['email'] <=> $right['email'];
        });

        $labeled = [];
        foreach ($shared as $index => $email) {
            $labeled[] = [
                'label' => 'Shared email '.($index + 1),
                'email' => $email['email'],
                'reservations' => $email['reservations'],
                'agencies' => $email['agencies'],
            ];
        }

        return $labeled;
    }

    /**
     * Same comparable-country rule as the Batch 3 classifier diagnostic.
     * It does not decide class 8 membership.
     *
     * @param  array<int, int>  $assignments
     * @param  array<int, object>  $loaded
     * @param  array<int, object>  $agencies
     * @return list<array{reservation_id: int, agency_id: int, reservation_country: string, agency_country: string}>
     */
    private function countryMismatches(array $assignments, array $loaded, array $agencies): array
    {
        $mismatches = [];
        foreach ($assignments as $reservationId => $agencyId) {
            if (! isset($loaded[$reservationId], $agencies[$agencyId])) {
                continue;
            }
            $reservationCountry = $this->comparableCountry($loaded[$reservationId]->country);
            $agencyCountry = $this->comparableCountry($agencies[$agencyId]->country);
            if ($reservationCountry === null || $agencyCountry === null || $reservationCountry === $agencyCountry) {
                continue;
            }
            $mismatches[] = [
                'reservation_id' => $reservationId,
                'agency_id' => $agencyId,
                'reservation_country' => trim((string) $loaded[$reservationId]->country),
                'agency_country' => trim((string) $agencies[$agencyId]->country),
            ];
        }

        usort($mismatches, static fn (array $left, array $right): int => $left['reservation_id'] <=> $right['reservation_id']);

        return $mismatches;
    }

    /**
     * @param  list<int>  $agencyIds
     * @param  array<int, int>  $assignments
     * @param  array<int, object>  $loaded
     * @param  array<int, object>  $agencies
     * @return list<array<string, mixed>>
     */
    private function focusedAgencies(array $agencyIds, array $assignments, array $loaded, array $agencies): array
    {
        $groups = [];
        foreach ($agencyIds as $agencyId) {
            $groups[$agencyId] = [];
        }

        foreach ($assignments as $reservationId => $agencyId) {
            if (! isset($groups[$agencyId], $loaded[$reservationId])) {
                continue;
            }
            $email = HistoricalAttributionBatchDryRun::normalizeEmail((string) $loaded[$reservationId]->email);
            $date = $this->reservationDate($loaded[$reservationId]->reservation_date);
            $groups[$agencyId][$email]['reservations'] = ($groups[$agencyId][$email]['reservations'] ?? 0) + 1;
            if ($date !== null) {
                $currentMin = $groups[$agencyId][$email]['date_min'] ?? null;
                $currentMax = $groups[$agencyId][$email]['date_max'] ?? null;
                $groups[$agencyId][$email]['date_min'] = $currentMin === null || $date < $currentMin ? $date : $currentMin;
                $groups[$agencyId][$email]['date_max'] = $currentMax === null || $date > $currentMax ? $date : $currentMax;
            }
        }

        $focused = [];
        foreach ($agencyIds as $agencyId) {
            $emails = [];
            $reservations = 0;
            $dateMin = null;
            $dateMax = null;
            foreach ($groups[$agencyId] as $email => $stats) {
                $emails[] = [
                    'email' => $email,
                    'reservations' => $stats['reservations'],
                    'date_min' => $stats['date_min'] ?? '',
                    'date_max' => $stats['date_max'] ?? '',
                ];
                $reservations += $stats['reservations'];
                if (($stats['date_min'] ?? '') !== '') {
                    $dateMin = $dateMin === null || $stats['date_min'] < $dateMin ? $stats['date_min'] : $dateMin;
                }
                if (($stats['date_max'] ?? '') !== '') {
                    $dateMax = $dateMax === null || $stats['date_max'] > $dateMax ? $stats['date_max'] : $dateMax;
                }
            }
            usort($emails, static function (array $left, array $right): int {
                $byCount = $right['reservations'] <=> $left['reservations'];

                return $byCount !== 0 ? $byCount : ($left['email'] <=> $right['email']);
            });
            $largest = 0;
            foreach ($emails as $email) {
                $largest = max($largest, $email['reservations']);
            }
            $name = isset($agencies[$agencyId])
                ? HistoricalAttributionBatchDryRun::normalizeAgencyName((string) $agencies[$agencyId]->name)
                : '';
            $focused[] = [
                'agency_id' => $agencyId,
                'reservations' => $reservations,
                'distinct_emails' => count($emails),
                'largest_email_group' => $largest,
                'date_min' => $dateMin ?? '',
                'date_max' => $dateMax ?? '',
                'normalized_name' => $name,
                'emails' => $emails,
            ];
        }

        return $focused;
    }

    /**
     * Structural observations only. A flagged agency stays in class 8.
     *
     * @param  array<int, int>  $assignments
     * @param  array<int, object>  $agencies
     * @return list<array{agency_id: int, reservations: int, normalized_name: string, observation: string}>
     */
    private function nameObservations(array $assignments, array $agencies): array
    {
        $counts = [];
        foreach ($assignments as $agencyId) {
            $counts[$agencyId] = ($counts[$agencyId] ?? 0) + 1;
        }

        $observations = [];
        foreach ($counts as $agencyId => $reservations) {
            if (! isset($agencies[$agencyId])) {
                continue;
            }
            $name = HistoricalAttributionBatchDryRun::normalizeAgencyName((string) $agencies[$agencyId]->name);
            $flags = [];
            if ($name !== '' && mb_strlen($name) <= 4) {
                $flags[] = 'short_name';
            }
            if ($name !== '' && ! str_contains($name, ' ')) {
                $flags[] = 'single_token';
            }
            if ($name !== '' && preg_match('/\p{L}/u', $name) !== 1) {
                $flags[] = 'no_letters';
            }
            foreach ($flags as $flag) {
                $observations[] = [
                    'agency_id' => (int) $agencyId,
                    'reservations' => $reservations,
                    'normalized_name' => $name,
                    'observation' => $flag,
                ];
            }
        }

        usort($observations, static function (array $left, array $right): int {
            $byAgency = $left['agency_id'] <=> $right['agency_id'];

            return $byAgency !== 0 ? $byAgency : ($left['observation'] <=> $right['observation']);
        });

        return $observations;
    }

    /**
     * @param  list<array{user_id: int, reservations: int, distinct_emails: int}>  $classifierAgencies
     * @param  list<array<string, mixed>>  $focused
     */
    private function agencyTotalsAgree(array $classifierAgencies, array $focused): bool
    {
        $expected = [];
        foreach ($classifierAgencies as $agency) {
            $expected[(int) $agency['user_id']] = (int) $agency['reservations'];
        }

        foreach ($focused as $agency) {
            $grouped = 0;
            foreach ($agency['emails'] as $email) {
                $grouped += $email['reservations'];
            }
            $classifierTotal = $expected[$agency['agency_id']] ?? 0;
            if ($grouped !== $agency['reservations'] || $agency['reservations'] !== $classifierTotal) {
                return false;
            }
        }

        return true;
    }

    private function comparableCountry(mixed $value): ?string
    {
        $country = trim((string) $value);
        if ($country === '') {
            return null;
        }

        return mb_strtoupper($country);
    }

    private function reservationDate(mixed $value): ?string
    {
        $date = trim((string) $value);
        if (preg_match('/^\d{4}-\d{2}-\d{2}/', $date) !== 1) {
            return null;
        }

        return substr($date, 0, 10);
    }
}
