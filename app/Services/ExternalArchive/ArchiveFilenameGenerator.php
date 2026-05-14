<?php

namespace App\Services\ExternalArchive;

use Illuminate\Support\Str;

/**
 * Collision-safe archive names (ASCII, no spaces, no user filenames).
 *
 * Format: {context_type}__{source_table}_{source_id}__{source_column}__{uuid}.{ext}
 */
final class ArchiveFilenameGenerator
{
    public static function generate(
        ?string $contextType,
        string $sourceTable,
        int|string $sourceId,
        ?string $sourceColumn,
        string $relativeLocalPath,
    ): string {
        $ext = strtolower((string) pathinfo($relativeLocalPath, PATHINFO_EXTENSION));
        $ext = preg_replace('/[^a-z0-9]+/', '', $ext) ?: 'bin';

        $ctx = self::safeSegment($contextType ?? 'file', 80);
        $tbl = self::safeSegment($sourceTable, 80);
        $col = self::safeSegment($sourceColumn ?? 'file', 60);
        $id = (int) $sourceId;
        $uuid = Str::uuid()->toString();

        return "{$ctx}__{$tbl}_{$id}__{$col}__{$uuid}.{$ext}";
    }

    private static function safeSegment(string $raw, int $maxLen): string
    {
        $s = strtolower((string) preg_replace('/[^A-Za-z0-9_]+/', '_', $raw));
        $s = trim($s, '_');
        if ($s === '') {
            $s = 'x';
        }

        return substr($s, 0, $maxLen);
    }
}
