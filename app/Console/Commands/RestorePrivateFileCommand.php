<?php

namespace App\Console\Commands;

use App\Models\ExternalFileArchive;
use App\Services\ExternalArchive\ExternalFileArchiveService;
use Illuminate\Console\Command;
use Throwable;

class RestorePrivateFileCommand extends Command
{
    protected $signature = 'files:restore-private {archive_id : external_file_archives.id}';

    protected $description = 'Download an archived file from MEGA back to its original private path';

    public function handle(ExternalFileArchiveService $archiveService): int
    {
        $id = (int) $this->argument('archive_id');
        /** @var ExternalFileArchive|null $archive */
        $archive = ExternalFileArchive::query()->find($id);
        if ($archive === null) {
            $this->error('Archive row not found.');

            return self::INVALID;
        }

        try {
            $archiveService->restoreFromMega($archive);
        } catch (Throwable $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }

        $this->info('Restored archive #'.$archive->id.' to '.$archive->original_local_path);

        return self::SUCCESS;
    }
}
