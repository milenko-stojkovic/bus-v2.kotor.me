<?php

namespace App\Console\Commands;

use App\Services\Reservation\HistoricalAttributionBatch4Diagnostics;
use Illuminate\Console\Command;
use RuntimeException;
use Throwable;

/**
 * Read-only Batch 4 discovery profile. Not scheduled. No write mode.
 */
class DiagnoseV1Batch4Command extends Command
{
    protected $signature = 'reservations:diagnose-v1-batch4
        {--report= : Optional private CSV outside this repository and the public document root}
        {--reservation= : Optional one reservation id. Prints a masked line and writes nothing to the database}';

    protected $description = 'Read-only Batch 4 discovery profile. Writes nothing to the database.';

    public function handle(HistoricalAttributionBatch4Diagnostics $diagnostics): int
    {
        $reportPath = $this->option('report');
        $reportPath = is_string($reportPath) ? trim($reportPath) : '';
        if ($reportPath !== '' && ! $this->reportPathAllowed($reportPath)) {
            return self::FAILURE;
        }

        try {
            $result = $diagnostics->collect($this->reservationId());
        } catch (RuntimeException $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        } catch (Throwable $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }

        $this->printResult($result);

        if ($reportPath === '') {
            return self::SUCCESS;
        }

        try {
            $this->writeReport($reportPath, $result);
        } catch (RuntimeException $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }
        $this->line('Report file: '.$reportPath);
        $this->line('The report is a discovery profile, not an attribution file.');

        return self::SUCCESS;
    }

    /**
     * @param  array<string, mixed>  $result
     */
    private function printResult(array $result): void
    {
        $live = $result['live'];
        $reference = $result['reference'];
        $quality = $result['quality'];
        $this->line('V1 Batch 4 discovery profile, read-only.');
        $this->line('No reservation, user, vehicle, or audit row was written.');
        $this->line('Discovery categories are not attribution rules and authorize no write.');
        $this->line('Category counts overlap. Do not add them together.');
        $this->line('Historical reservations: '.$live['historical']);
        $this->line('Attributed historical reservations: '.$live['attributed']);
        $this->line('Unattributed historical reservations: '.$live['unattributed']);
        $this->line('A null user_id is not by itself a discovery candidate.');
        $this->line('Active Batch 1: '.$live['batch1_active']);
        $this->line('Active Batch 2: '.$live['batch2_active']);
        $this->line('Active Batch 3: '.$live['batch3_active']);
        $this->line('Active Batch 3 regular: '.$live['batch3_regular_active']);
        $this->line('Active Batch 3 reviewed: '.$live['batch3_reviewed_active']);
        $this->line('Active other evidence: '.$live['other_active']);
        $this->line('Post-cutoff reservations: '.$reference['post_cutoff']);
        $this->line('Post-cutoff user-linked reservations: '.$reference['post_cutoff_user_linked']);
        $this->line('Post-cutoff guest reservations: '.$reference['post_cutoff_guest']);
        $this->line('Reservations with no created_at: '.$reference['created_at_null']);
        $this->line('Post-cutoff rows are reference evidence only. A user link without an attribution audit is uncertain provenance, not proof of native creation.');
        $this->line('Baseline: '.($result['baseline']['matches'] ? 'matches the last verified snapshot' : 'deviates from the last verified snapshot'));
        foreach ($result['baseline']['deviations'] as $deviation) {
            $this->line('Baseline deviation '.$deviation);
        }
        $inconsistencies = $result['inconsistencies'];
        $this->line('Inconsistencies: '.$inconsistencies['total']);
        $this->line('Unattributed with an active audit: '.$inconsistencies['unattributed_with_active_audit']);
        $this->line('Unattributed with a closed audit: '.$inconsistencies['unattributed_with_closed_audit']);
        $this->line('Attributed without an active audit: '.$inconsistencies['attributed_without_active_audit']);
        $this->line('user_id disagrees with the active audit: '.$inconsistencies['user_id_disagrees_with_active_audit']);
        $this->line('Active audit outside the historical cohort: '.$inconsistencies['active_audit_outside_cohort']);
        $this->line('Discovery population: '.$result['discovery_population']);
        $this->line('Excluded from discovery by an audit: '.$result['excluded_from_discovery']);
        $this->printStatus($quality['status']);
        $this->line('Nonempty email: '.$quality['email_nonempty']);
        $this->line('Empty email: '.$quality['email_empty']);
        $this->line('Invalid email shape: '.$quality['email_invalid_shape']);
        $this->line('Nonempty name: '.$quality['name_nonempty']);
        $this->line('Empty name: '.$quality['name_empty']);
        $this->line('Nonempty plate: '.$quality['plate_nonempty']);
        $this->line('Empty plate: '.$quality['plate_empty']);
        $this->line('Distinct emails: '.$quality['distinct_emails']);
        $this->line('Distinct names: '.$quality['distinct_names']);
        $this->line('Distinct plates: '.$quality['distinct_plates']);
        $this->printRepeated('emails', $quality['repeated_emails']);
        $this->printRepeated('names', $quality['repeated_names']);
        $this->printRepeated('plates', $quality['repeated_plates']);
        $this->line('Email owned by multiple users: '.$quality['email_multi_user']);
        $this->line('Plate registered to multiple agencies: '.$quality['plate_multi_registry_user']);
        $this->line('Plate on trusted attributions of multiple agencies: '.$quality['plate_multi_trusted_agency']);
        $this->line('Canonical name owned by multiple users: '.$quality['name_multi_user']);
        $this->line('Exploratory alias owned by multiple users: '.$quality['alias_multi_user']);
        $this->line('Unattributed rows with vehicle_id: '.$quality['vehicle_id_set']);
        $this->line('Unattributed rows with free_reservation_request_id: '.$quality['free_request_id_set']);
        $this->line('vehicle_id and free_reservation_request_id are counted. They are not discovery categories.');
        foreach (['b4a' => 'B4-A', 'b4b' => 'B4-B', 'b4c' => 'B4-C', 'b4d' => 'B4-D', 'b4e' => 'B4-E'] as $key => $label) {
            $category = $result['categories'][$key];
            $this->line($label.' reservations: '.$category['reservations'].', distinct agencies: '.$category['distinct_agencies']);
        }
        $this->line('B4-B batch 3 email only, not used: '.$result['weak']['batch3_email_only']);
        $this->line('B4-B reviewed-name email only, not used: '.$result['weak']['reviewed_email_only']);
        $this->line('Uncertain post-cutoff email only, not used: '.$result['weak']['uncertain_post_cutoff_email_only']);
        $this->line('Alias without independent support, not used: '.$result['weak']['alias_without_support']);
        $overlap = $result['overlap'];
        foreach ($overlap['pairs'] as $pair => $count) {
            $this->line('Overlap '.$pair.': '.$count);
        }
        $this->line('In three or more categories: '.$overlap['three_or_more']);
        $this->line('Reservations in at least one of B4-A through B4-D: '.$overlap['any_of_abcd']);
        $this->line('Reservations with multiple proposed agencies: '.$overlap['multiple_proposed_agencies']);
        $this->line('Reservations with contradictory evidence: '.$overlap['contradictory']);
        $this->line('Reservations with no credible candidate: '.$overlap['no_credible_candidate']);
        $this->line('Batch 3 plate groups were not used as plate evidence: '.$result['batch3_plate_groups']);
        $this->printEvidence($result);
        $this->line('Samples are limited to '.HistoricalAttributionBatch4Diagnostics::SAMPLE_LIMIT.' rows in each category. Addresses and names are not printed in full.');
        foreach ($result['samples'] as $signal => $samples) {
            foreach ($samples as $sample) {
                $this->line($this->sampleLine((string) $signal, $sample));
            }
        }
        $this->printReservation($result['reservation'] ?? null);
    }

    /**
     * @param  array<string, mixed>  $result
     */
    private function printEvidence(array $result): void
    {
        $evidence = $result['evidence'];
        $this->line('B4-A name buckets. Each B4-A reservation is in exactly one.');
        foreach ($evidence['b4a_buckets'] as $bucket => $count) {
            $this->line('B4-A bucket '.$bucket.': '.$count);
        }
        $ac = $evidence['ac'];
        $this->line('B4-A and B4-C same user with exclusive registry plate: '.$ac['same_user_exclusive_plate']);
        $this->line('B4-A and B4-C same user without exclusive registry plate: '.$ac['same_user_without_exclusive_plate']);
        $this->line('B4-A and B4-C different proposed users: '.$ac['different_user']);
        $this->line('B4-A and B4-C rows with conflict flags: '.$ac['with_conflict']);
        $this->line('B4-A and B4-C rows with ambiguity flags: '.$ac['with_ambiguity']);
        $this->line('The same-user and different-user lines partition B4-A with B4-C. Conflict and ambiguity lines are cross-cuts.');
        $without = $evidence['a_without_c_or_d'];
        $this->line('B4-A without B4-C or B4-D: '.$without['reservations']);
        foreach (['no_alias_key', 'alias_matches_no_user', 'ambiguous_alias', 'other_alias_without_support', 'alias_email_owner_blocked'] as $reason) {
            $this->line('B4-A alias failure '.$reason.': '.$without[$reason]);
        }
        $this->line('Conflict flags count contradictory rows. An ambiguity flag is not an identity conflict.');
        foreach ($evidence['conflict_flags'] as $flag => $count) {
            $this->line('Conflict flag '.$flag.': '.$count);
        }
        foreach ($evidence['conflict_combinations'] as $combo => $count) {
            $this->line('Conflict combination '.$combo.': '.$count);
        }
        foreach ($evidence['conflict_families'] as $family => $count) {
            $this->line('Conflict family '.$family.': '.$count);
        }
        $this->line('Overlap reconciliation. These cells are disjoint.');
        foreach ($evidence['overlap_cells'] as $cell => $count) {
            $this->line('Overlap cell '.$cell.': '.$count);
        }
        $this->line('Unique discovery candidates in B4-A through B4-D: '.$result['overlap']['any_of_abcd']);
        $this->line('Overlap reconciliation agrees: '.($evidence['reconciliation_agrees'] ? 'yes' : 'no'));
        $this->line('B4-A distinct agencies: '.$evidence['distinct_agencies']);
        $this->line('B4-A distinct names: '.$evidence['distinct_names']);
        if ($evidence['largest_agency_id'] !== null) {
            $this->line('Largest B4-A agency: '.$evidence['largest_agency_id'].' reservations '.$evidence['largest_agency_count']);
        }
        foreach ($this->topAgencies($evidence['by_agency']) as $agencyId => $count) {
            $this->line('B4-A agency '.$agencyId.': reservations '.$count);
        }
    }

    /**
     * @param  array<int|string, int>  $agencies
     * @return array<int|string, int>
     */
    private function topAgencies(array $agencies): array
    {
        uksort($agencies, static function ($left, $right) use ($agencies): int {
            $byCount = $agencies[$right] <=> $agencies[$left];

            return $byCount !== 0 ? $byCount : ((int) $left <=> (int) $right);
        });

        return array_slice($agencies, 0, 10, true);
    }

    /**
     * @param  array<string, mixed>|null  $reservation
     */
    private function printReservation(?array $reservation): void
    {
        if ($reservation === null) {
            return;
        }
        $id = $reservation['id'];
        if ($reservation['status'] === 'missing') {
            $this->line('Reservation '.$id.': not found.');

            return;
        }
        if ($reservation['status'] === 'outside_cohort') {
            $this->line('Reservation '.$id.': outside the historical cohort. Not a discovery candidate.');

            return;
        }
        if ($reservation['status'] === 'attributed') {
            $this->line('Reservation '.$id.': historical and linked to user '.$reservation['linked_user_id'].'. Not a discovery candidate.');

            return;
        }
        if ($reservation['status'] === 'excluded_by_audit') {
            $this->line('Reservation '.$id.': historical and unattributed, excluded from discovery because an attribution audit exists.');

            return;
        }
        if ($reservation['status'] === 'no_credible_candidate') {
            $this->line('Reservation '.$id.': in the historical discovery population with no credible candidate.');
        } else {
            $this->line('Reservation '.$id.': discovery candidate.');
        }
        $proposed = $reservation['proposed_user_ids'] === [] ? 'none' : implode('|', $reservation['proposed_user_ids']);
        $this->line('Signals: '.$this->joinList($reservation['signals']));
        $this->line('Proposed users: '.$proposed);
        $this->line('Provenance: '.$this->joinList($reservation['provenance']));
        $this->line('Conflicts: '.$this->joinList($reservation['conflicting']));
        $this->line('Ambiguity: '.$this->joinList($reservation['ambiguity']));
        $this->line('B4-A bucket: '.($reservation['b4a_bucket'] ?? 'none'));
        $this->line('Overlap cell: '.($reservation['overlap_cell'] ?? 'none'));
        $this->line('Masked email: '.$reservation['masked_email']);
        if ($reservation['weak_reviewed_name']) {
            $this->line('Weak reviewed-name email reference is not approved evidence.');
        }
        if ($reservation['weak_batch3_email']) {
            $this->line('Weak Batch 3 email reference is not approved evidence.');
        }
    }

    private function reservationId(): ?int
    {
        $value = $this->option('reservation');
        if ($value === null || $value === '') {
            return null;
        }
        if (is_int($value) && $value > 0) {
            return $value;
        }
        if (is_string($value) && preg_match('/^[1-9][0-9]*$/', $value) === 1) {
            return (int) $value;
        }

        throw new RuntimeException('The reservation id must be a positive integer.');
    }

    /**
     * @param  array<string, int>  $status
     */
    private function printStatus(array $status): void
    {
        if ($status === []) {
            $this->line('Status distribution: none');

            return;
        }
        foreach ($status as $name => $count) {
            $this->line('Status '.$name.': '.$count);
        }
    }

    /**
     * @param  array{values: int, reservations: int, largest: int}  $repeated
     */
    private function printRepeated(string $label, array $repeated): void
    {
        $this->line(
            'Repeated '.$label.': '.$repeated['values']
            .' values, '.$repeated['reservations']
            .' reservations, largest '.$repeated['largest']
        );
    }

    /**
     * @param  array<string, mixed>  $sample
     */
    private function sampleLine(string $signal, array $sample): string
    {
        $proposed = $sample['proposed_user_id'] === null ? 'none' : (string) $sample['proposed_user_id'];

        return 'Sample '.$signal
            .' reservation '.$sample['reservation_id']
            .' proposed '.$proposed
            .' signals '.$this->joinList($sample['signals'])
            .' supporting '.$this->joinList($sample['supporting'])
            .' conflicting '.$this->joinList($sample['conflicting'])
            .' provenance '.$this->joinList($sample['anchor_provenance'])
            .' references '.$sample['independent_references']
            .' ambiguity '.$this->joinList($sample['ambiguity'])
            .' masked '.$sample['masked_email'];
    }

    /**
     * @param  list<string>  $values
     */
    private function joinList(array $values): string
    {
        return $values === [] ? 'none' : implode('|', $values);
    }

    /**
     * @param  array<string, mixed>  $result
     */
    private function writeReport(string $path, array $result): void
    {
        $handle = fopen($path, 'xb');
        if ($handle === false) {
            throw new RuntimeException('The report file could not be created.');
        }

        $failed = null;
        try {
            $this->writeReportRows($handle, $result);
        } catch (Throwable $e) {
            $failed = $e;
        } finally {
            fclose($handle);
        }

        if ($failed === null) {
            return;
        }

        if (is_file($path)) {
            unlink($path);
        }

        if ($failed instanceof RuntimeException) {
            throw $failed;
        }

        throw new RuntimeException('The report file could not be written.', 0, $failed);
    }

    /**
     * @param  resource  $handle
     * @param  array<string, mixed>  $result
     */
    protected function writeReportRows($handle, array $result): void
    {
        $header = [
            'record_type',
            'label',
            'count',
            'reservation_id',
            'proposed_user_id',
            'signals',
            'supporting',
            'conflicting',
            'provenance',
            'references',
            'ambiguity',
            'masked_email',
        ];
        $this->writeCsvRow($handle, $header);
        foreach ($result['live'] as $label => $count) {
            $this->writeCount($handle, 'live', (string) $label, (int) $count);
        }
        foreach ($result['baseline']['deviations'] as $deviation) {
            $this->writeCount($handle, 'baseline_deviation', (string) $deviation, 1);
        }
        foreach ($result['inconsistencies'] as $label => $count) {
            if ($label === 'ids' || ! is_int($count)) {
                continue;
            }
            $this->writeCount($handle, 'inconsistency', (string) $label, $count);
        }
        foreach ($result['quality']['status'] as $label => $count) {
            $this->writeCount($handle, 'status', (string) $label, (int) $count);
        }
        foreach (['b4a', 'b4b', 'b4c', 'b4d', 'b4e'] as $signal) {
            $this->writeCount($handle, 'category', $signal, (int) $result['categories'][$signal]['reservations']);
        }
        foreach ($result['samples'] as $signal => $samples) {
            foreach ($samples as $sample) {
                $this->writeCsvRow($handle, [
                    'sample',
                    (string) $signal,
                    '',
                    (string) $sample['reservation_id'],
                    $sample['proposed_user_id'] === null ? '' : (string) $sample['proposed_user_id'],
                    implode('|', $sample['signals']),
                    implode('|', $sample['supporting']),
                    implode('|', $sample['conflicting']),
                    implode('|', $sample['anchor_provenance']),
                    (string) $sample['independent_references'],
                    implode('|', $sample['ambiguity']),
                    (string) $sample['masked_email'],
                ]);
            }
        }
        $this->writeEvidenceRows($handle, $result);
    }

    /**
     * @param  resource  $handle
     * @param  array<string, mixed>  $result
     */
    private function writeEvidenceRows($handle, array $result): void
    {
        $evidence = $result['evidence'];
        foreach ($evidence['b4a_buckets'] as $label => $count) {
            $this->writeAggregate($handle, 'b4a_bucket', (string) $label, (int) $count);
        }
        foreach ($evidence['ac'] as $label => $count) {
            $this->writeAggregate($handle, 'ac_split', (string) $label, (int) $count);
        }
        foreach ($evidence['a_without_c_or_d'] as $label => $count) {
            $this->writeAggregate($handle, 'a_without_c_or_d', (string) $label, (int) $count);
        }
        foreach ($evidence['conflict_flags'] as $label => $count) {
            $this->writeAggregate($handle, 'conflict_flag', (string) $label, (int) $count);
        }
        foreach ($evidence['conflict_combinations'] as $label => $count) {
            $this->writeAggregate($handle, 'conflict_combination', (string) $label, (int) $count);
        }
        foreach ($evidence['conflict_families'] as $label => $count) {
            $this->writeAggregate($handle, 'conflict_family', (string) $label, (int) $count);
        }
        foreach ($evidence['overlap_cells'] as $label => $count) {
            $this->writeAggregate($handle, 'overlap_cell', (string) $label, (int) $count);
        }
        foreach ($evidence['by_agency'] as $agencyId => $count) {
            $this->writeAggregate($handle, 'b4a_agency', '', (int) $count, (string) $agencyId);
        }
        foreach ($evidence['by_name'] as $name => $count) {
            $this->writeAggregate($handle, 'b4a_name', $name === '' ? '(empty)' : (string) $name, (int) $count);
        }
        foreach ($evidence['by_agency_name'] as $agencyId => $names) {
            ksort($names);
            foreach ($names as $name => $count) {
                $this->writeAggregate($handle, 'b4a_agency_name', $name === '' ? '(empty)' : (string) $name, (int) $count, (string) $agencyId);
            }
        }
        $reservation = $result['reservation'] ?? null;
        if (! is_array($reservation)) {
            return;
        }
        $this->writeCsvRow($handle, [
            'reservation_detail',
            (string) ($reservation['normalized_name'] ?? $reservation['status']),
            '',
            (string) $reservation['id'],
            isset($reservation['proposed_user_id']) && $reservation['proposed_user_id'] !== null ? (string) $reservation['proposed_user_id'] : '',
            implode('|', $reservation['signals'] ?? []),
            implode('|', $reservation['supporting'] ?? []),
            implode('|', $reservation['conflicting'] ?? []),
            implode('|', $reservation['provenance'] ?? []),
            '',
            implode('|', $reservation['ambiguity'] ?? []),
            (string) ($reservation['masked_email'] ?? ''),
        ]);
    }

    /**
     * @param  resource  $handle
     */
    private function writeAggregate($handle, string $type, string $label, int $count, string $userId = ''): void
    {
        $this->writeCsvRow($handle, [$type, $label, (string) $count, '', $userId, '', '', '', '', '', '', '']);
    }

    /**
     * @param  resource  $handle
     */
    private function writeCount($handle, string $type, string $label, int $count): void
    {
        $this->writeCsvRow($handle, [$type, $label, (string) $count, '', '', '', '', '', '', '', '', '']);
    }

    /**
     * @param  resource  $handle
     * @param  list<string>  $fields
     */
    private function writeCsvRow($handle, array $fields): void
    {
        if (fputcsv($handle, $fields, ',', '"', '\\', "\n") === false) {
            throw new RuntimeException('The report file could not be written.');
        }
    }

    private function reportPathAllowed(string $path): bool
    {
        if (is_file($path)) {
            $this->error('The report file already exists and was not overwritten.');

            return false;
        }

        $directory = dirname($path);
        $realDirectory = realpath($directory);
        $root = realpath(base_path());
        $public = realpath(public_path());
        if ($realDirectory === false || $root === false || is_dir($path)) {
            $this->error('The report path must be a new file in an existing directory outside the repository and the public document root.');

            return false;
        }

        $realDirectory = $this->normalizePath($realDirectory);
        $root = $this->normalizePath($root);
        $public = $public === false ? '' : $this->normalizePath($public);
        if ($this->inside($realDirectory, $root) || ($public !== '' && $this->inside($realDirectory, $public))) {
            $this->error('The report path must be a new file in an existing directory outside the repository and the public document root.');

            return false;
        }

        return true;
    }

    private function normalizePath(string $path): string
    {
        return rtrim(str_replace('\\', '/', strtolower($path)), '/');
    }

    private function inside(string $directory, string $root): bool
    {
        return $directory === $root || str_starts_with($directory, $root.'/');
    }
}
