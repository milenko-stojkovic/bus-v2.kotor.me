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
        {--report= : Optional private CSV outside this repository and the public document root}';

    protected $description = 'Read-only Batch 4 discovery profile. Writes nothing to the database.';

    public function handle(HistoricalAttributionBatch4Diagnostics $diagnostics): int
    {
        $reportPath = $this->option('report');
        $reportPath = is_string($reportPath) ? trim($reportPath) : '';
        if ($reportPath !== '' && ! $this->reportPathAllowed($reportPath)) {
            return self::FAILURE;
        }

        try {
            $result = $diagnostics->collect();
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
        $this->line('Samples are limited to '.HistoricalAttributionBatch4Diagnostics::SAMPLE_LIMIT.' rows in each category. Addresses and names are not printed in full.');
        foreach ($result['samples'] as $signal => $samples) {
            foreach ($samples as $sample) {
                $this->line($this->sampleLine((string) $signal, $sample));
            }
        }
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
