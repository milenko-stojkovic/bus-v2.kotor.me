<?php

namespace App\Console\Commands;

use App\Services\ExternalArchive\ExternalFileArchiveService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

/**
 * One-time / maintenance: mark failed archive rows as superseded when an uploaded
 * sibling already exists for the same source_table + source_id + source_column.
 *
 * Never contacts MEGA, never deletes rows, never touches local files, never retries uploads.
 */
class ReconcileExternalArchivesCommand extends Command
{
    protected $signature = 'files:reconcile-external-archives
                            {--dry-run : Report how many failed rows would become superseded; no writes}';

    protected $description = 'Supersede failed external_file_archives that already have an uploaded sibling for the same source';

    public function handle(ExternalFileArchiveService $archiveService): int
    {
        $dryRun = (bool) $this->option('dry-run');

        $result = $archiveService->reconcileFailedWithUploadedSiblings($dryRun);

        if ($dryRun) {
            $this->info('DRY RUN: no rows updated.');
            $this->info('Identities with uploaded + failed sibling(s): '.$result['identities']);
            $this->info('Failed rows that would become superseded: '.$result['would_supersede']);
        } else {
            $this->info('Identities reconciled: '.$result['identities']);
            $this->info('Failed rows superseded: '.$result['superseded']);
        }

        Log::channel('payments')->info('files_reconcile_external_archives', [
            'dry_run' => $dryRun,
            'identities' => $result['identities'],
            'would_supersede' => $result['would_supersede'],
            'superseded' => $result['superseded'],
        ]);

        return self::SUCCESS;
    }
}
