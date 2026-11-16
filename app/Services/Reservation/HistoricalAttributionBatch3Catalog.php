<?php

namespace App\Services\Reservation;

use RuntimeException;

/**
 * File format and constants for the Batch 3 writer workflow.
 *
 * Classification stays in HistoricalAttributionBatch3DryRun. These values
 * name files, evidence, and the four held agency ids. They do not add a
 * matching rule.
 */
final class HistoricalAttributionBatch3Catalog
{
    public const EXPECTED_CLASS8 = 2060;

    public const EXPECTED_HOLD = 8;

    public const GROUP_SIZE = 50;

    public const MAX_LIMIT = 50;

    /** @var list<int> */
    public const HOLD_AGENCY_IDS = [148, 224, 464, 515];

    public const EVIDENCE = 'v1-phase2-batch3:name_unique_email_unregistered';

    public const EVIDENCE_REVIEWED = 'v1-phase2-batch3:name_unique_email_unregistered:reviewed_name';

    public const ROLLBACK_EVIDENCE = 'rollback:v1-phase2-batch3';

    public const ROLLBACK_EVIDENCE_REVIEWED = 'rollback:v1-phase2-batch3:reviewed_name';

    public const CONFIRM_APPROVE = 'APPROVE-V1-BATCH-3';

    public const CONFIRM_APPROVE_REVIEW = 'APPROVE-V1-BATCH-3-REVIEW';

    public const CONFIRM_APPLY = 'APPLY-V1-BATCH-3';

    public const CONFIRM_APPLY_REVIEW = 'APPLY-V1-BATCH-3-REVIEW';

    public const CONFIRM_ROLLBACK = 'ROLLBACK-V1-BATCH-3';

    public const CONFIRM_ROLLBACK_REVIEW = 'ROLLBACK-V1-BATCH-3-REVIEW';

    public const DISPOSITION_ELIGIBLE = 'eligible';

    public const DISPOSITION_HOLD = 'manual_hold';

    public const DISPOSITION_APPROVED = 'approved';

    public const DISPOSITION_APPROVED_REVIEW = 'approved_after_review';

    public const HOLD_REASON = 'ambiguous_agency_name';

    public const GROUP_HOLD = 'b3-hold';

    public const GROUP_REVIEW = 'b3-review';

    /** @var list<string> */
    public const CSV_HEADER = [
        'reservation_id',
        'proposed_user_id',
        'disposition',
        'source_class',
        'group_id',
        'hold_reason',
    ];

    /** @var list<string> */
    public const REPORT_HEADER = [
        'reservation_id',
        'proposed_user_id',
        'disposition',
        'group_id',
        'outcome',
        'reason',
        'evidence',
    ];

    public static function isHoldAgency(int $agencyId): bool
    {
        return in_array($agencyId, self::HOLD_AGENCY_IDS, true);
    }

    /**
     * @param  array<string, mixed>  $record
     */
    public static function executeConfirm(array $record): string
    {
        return self::kind($record) === 'review' ? self::CONFIRM_APPLY_REVIEW : self::CONFIRM_APPLY;
    }

    /**
     * @param  array<string, mixed>  $record
     */
    public static function rollbackConfirm(array $record): string
    {
        return self::kind($record) === 'review' ? self::CONFIRM_ROLLBACK_REVIEW : self::CONFIRM_ROLLBACK;
    }

    /**
     * @param  array<string, mixed>  $record
     */
    public static function evidence(array $record): string
    {
        return self::kind($record) === 'review' ? self::EVIDENCE_REVIEWED : self::EVIDENCE;
    }

    /**
     * @param  array<string, mixed>  $record
     */
    public static function rollbackEvidence(array $record): string
    {
        return self::kind($record) === 'review' ? self::ROLLBACK_EVIDENCE_REVIEWED : self::ROLLBACK_EVIDENCE;
    }

    /**
     * @param  array<string, mixed>  $record
     */
    public static function kind(array $record): string
    {
        $confirm = $record['confirm'] ?? null;
        if ($confirm === self::CONFIRM_APPROVE) {
            return 'ordinary';
        }
        if ($confirm === self::CONFIRM_APPROVE_REVIEW) {
            return 'review';
        }

        throw new RuntimeException('The approval record has an unknown confirm value.');
    }

    public static function approvalPath(string $csvPath): string
    {
        if (! str_ends_with(strtolower($csvPath), '.csv')) {
            throw new RuntimeException('The approved file must use a .csv name.');
        }

        return substr($csvPath, 0, -4).'.approval.json';
    }

    public static function sha256(string $path): string
    {
        $hash = hash_file('sha256', $path);
        if (! is_string($hash)) {
            throw new RuntimeException('The file hash could not be read.');
        }

        return $hash;
    }

    public static function directoryOutsideRepository(string $directory): bool
    {
        $realDirectory = realpath($directory);
        $root = realpath(base_path());
        if ($realDirectory === false || $root === false || ! is_dir($realDirectory)) {
            return false;
        }

        $realDirectory = rtrim(str_replace('\\', '/', strtolower($realDirectory)), '/');
        $root = rtrim(str_replace('\\', '/', strtolower($root)), '/');

        return $realDirectory !== $root && ! str_starts_with($realDirectory, $root.'/');
    }

    public static function fileOutsideRepository(string $path): bool
    {
        $directory = dirname($path);
        if (! self::directoryOutsideRepository($directory) || is_dir($path) || is_file($path)) {
            return false;
        }

        return true;
    }

    /**
     * @param  list<array{reservation_id: int, proposed_user_id: int, disposition: string, source_class: string, group_id: string, hold_reason: string}>  $rows
     */
    public static function writeCsv(string $path, array $rows): string
    {
        $handle = fopen($path, 'xb');
        if ($handle === false) {
            throw new RuntimeException('The CSV file could not be created.');
        }
        try {
            self::putRow($handle, self::CSV_HEADER);
            foreach ($rows as $row) {
                self::putRow($handle, [
                    (string) $row['reservation_id'],
                    (string) $row['proposed_user_id'],
                    $row['disposition'],
                    $row['source_class'],
                    $row['group_id'],
                    $row['hold_reason'],
                ]);
            }
        } catch (\Throwable $e) {
            fclose($handle);
            if (is_file($path)) {
                unlink($path);
            }
            throw $e;
        }
        fclose($handle);

        return self::sha256($path);
    }

    /**
     * @return list<array{reservation_id: int, proposed_user_id: int, disposition: string, source_class: string, group_id: string, hold_reason: string}>
     */
    public static function readCsv(string $path): array
    {
        if (! is_file($path)) {
            throw new RuntimeException('The candidate CSV was not found.');
        }
        $handle = fopen($path, 'rb');
        if ($handle === false) {
            throw new RuntimeException('The candidate CSV could not be read.');
        }
        $header = fgetcsv($handle);
        if ($header !== self::CSV_HEADER) {
            fclose($handle);
            throw new RuntimeException('The CSV header is not a Batch 3 writer file. A classification or diagnostic CSV cannot be executed.');
        }
        $rows = [];
        $seen = [];
        while (($data = fgetcsv($handle)) !== false) {
            if ($data === [null] || $data === false) {
                continue;
            }
            if (count($data) !== count(self::CSV_HEADER)) {
                fclose($handle);
                throw new RuntimeException('The CSV contains a malformed row.');
            }
            $reservationId = self::positiveInt($data[0], 'reservation_id');
            $proposedUserId = self::positiveInt($data[1], 'proposed_user_id');
            if (isset($seen[$reservationId])) {
                fclose($handle);
                throw new RuntimeException('The CSV contains a duplicate reservation id.');
            }
            $seen[$reservationId] = true;
            if ($data[3] !== HistoricalAttributionBatch3DryRun::CANDIDATE) {
                fclose($handle);
                throw new RuntimeException('The CSV source class is not the Batch 3 candidate class.');
            }
            $rows[] = [
                'reservation_id' => $reservationId,
                'proposed_user_id' => $proposedUserId,
                'disposition' => (string) $data[2],
                'source_class' => (string) $data[3],
                'group_id' => (string) $data[4],
                'hold_reason' => (string) $data[5],
            ];
        }
        fclose($handle);

        return $rows;
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public static function writeJson(string $path, array $data): void
    {
        $handle = fopen($path, 'xb');
        if ($handle === false) {
            throw new RuntimeException('The approval record could not be created.');
        }
        $encoded = json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
        $written = fwrite($handle, $encoded.PHP_EOL);
        fclose($handle);
        if ($written === false) {
            if (is_file($path)) {
                unlink($path);
            }
            throw new RuntimeException('The approval record could not be written.');
        }
    }

    /**
     * @return array<string, mixed>
     */
    public static function readJson(string $path): array
    {
        if (! is_file($path)) {
            throw new RuntimeException('The approval record was not found. An eligible or classification CSV cannot be executed.');
        }
        $decoded = json_decode((string) file_get_contents($path), true, 512, JSON_THROW_ON_ERROR);
        if (! is_array($decoded)) {
            throw new RuntimeException('The approval record is malformed.');
        }

        return $decoded;
    }

    /**
     * @param  resource  $handle
     * @param  list<string>  $values
     */
    private static function putRow($handle, array $values): void
    {
        if (fputcsv($handle, $values, ',', '"', '\\', "\n") === false) {
            throw new RuntimeException('The CSV file could not be written.');
        }
    }

    private static function positiveInt(string $value, string $label): int
    {
        if (preg_match('/^[1-9][0-9]*$/', $value) !== 1) {
            throw new RuntimeException('The CSV contains a malformed '.$label.'.');
        }

        return (int) $value;
    }
}
