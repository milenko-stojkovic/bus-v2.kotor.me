<?php

namespace App\Services\ExternalArchive;

use App\Contracts\MegaArchiveClient;
use App\Models\ExternalFileArchive;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Throwable;

final class ExternalFileArchiveService
{
    public function __construct(
        private readonly MegaArchiveClient $megaClient,
    ) {}

    /**
     * Archive one private disk file to MEGA. Never deletes local file unless upload succeeds and DB row is updated.
     */
    public function archiveLocalPrivateFile(
        string $sourceTable,
        int $sourceId,
        ?string $sourceColumn,
        string $localPath,
        ?string $contextType = null,
    ): ExternalFileArchive {
        $disk = Storage::disk('local');

        $existingUploaded = ExternalFileArchive::query()
            ->where('source_table', $sourceTable)
            ->where('source_id', $sourceId)
            ->where('source_column', $sourceColumn)
            ->where('status', ExternalFileArchive::STATUS_UPLOADED)
            ->orderByDesc('id')
            ->first();
        if ($existingUploaded instanceof ExternalFileArchive) {
            return $existingUploaded;
        }

        if (! $disk->exists($localPath)) {
            throw new \InvalidArgumentException('Local file does not exist on private disk: '.$localPath);
        }

        $generated = ArchiveFilenameGenerator::generate(
            $contextType,
            $sourceTable,
            $sourceId,
            $sourceColumn,
            $localPath,
        );

        $absolute = $disk->path($localPath);

        Log::channel('payments')->info('external_archive_upload_started', [
            'source_table' => $sourceTable,
            'source_id' => $sourceId,
            'source_column' => $sourceColumn,
            'context_type' => $contextType,
            'generated_file_name' => $generated,
            'original_local_path' => $localPath,
        ]);

        /** @var ExternalFileArchive $row */
        $row = ExternalFileArchive::query()->create([
            'source_table' => $sourceTable,
            'source_id' => $sourceId,
            'source_column' => $sourceColumn,
            'context_type' => $contextType,
            'archive_provider' => ExternalFileArchive::PROVIDER_MEGA,
            'generated_file_name' => $generated,
            'mega_node_id' => null,
            'mega_path' => null,
            'original_local_path' => $localPath,
            'local_deleted_at' => null,
            'archived_at' => null,
            'status' => ExternalFileArchive::STATUS_PENDING,
            'error_message' => null,
        ]);

        $upload = $this->megaClient->uploadLocalFile($absolute, $generated);

        if (! $upload->ok) {
            $row->update([
                'status' => ExternalFileArchive::STATUS_FAILED,
                'error_message' => $upload->error ?? 'upload_failed',
            ]);
            Log::channel('payments')->warning('external_archive_upload_failed', [
                'external_file_archive_id' => $row->id,
                'source_table' => $sourceTable,
                'source_id' => $sourceId,
                'error' => $upload->error,
            ]);

            return $row->refresh();
        }

        try {
            DB::transaction(function () use ($row, $upload): void {
                $row->update([
                    'status' => ExternalFileArchive::STATUS_UPLOADED,
                    'mega_node_id' => $upload->megaNodeId,
                    'mega_path' => $upload->megaPath,
                    'archived_at' => now(),
                    'error_message' => null,
                ]);
            });
        } catch (Throwable $e) {
            $row->update([
                'status' => ExternalFileArchive::STATUS_FAILED,
                'error_message' => 'db_update_failed: '.$e->getMessage(),
            ]);
            Log::channel('payments')->error('external_archive_upload_db_failed', [
                'external_file_archive_id' => $row->id,
                'error' => $e->getMessage(),
            ]);

            return $row->refresh();
        }

        $deleted = $disk->delete($localPath);
        if ($deleted) {
            $row->update(['local_deleted_at' => now()]);
            Log::channel('payments')->info('external_archive_local_deleted', [
                'external_file_archive_id' => $row->id,
                'original_local_path' => $localPath,
            ]);
        } else {
            Log::channel('payments')->warning('external_archive_local_delete_failed', [
                'external_file_archive_id' => $row->id,
                'original_local_path' => $localPath,
            ]);
        }

        Log::channel('payments')->info('external_archive_upload_succeeded', [
            'external_file_archive_id' => $row->id,
            'mega_path' => $upload->megaPath,
        ]);

        return $row->refresh();
    }

    /**
     * Restore a previously uploaded archive from MEGA back to the original private path.
     */
    public function restoreFromMega(ExternalFileArchive $archive): void
    {
        if ($archive->status !== ExternalFileArchive::STATUS_UPLOADED) {
            throw new \InvalidArgumentException('Archive is not in uploaded state.');
        }
        $megaPath = $archive->mega_path;
        $generated = $archive->generated_file_name;
        if (($megaPath === null || $megaPath === '') && ($generated === null || $generated === '')) {
            throw new \InvalidArgumentException('Missing mega_path and generated_file_name on archive row.');
        }

        $disk = Storage::disk('local');
        $dest = $disk->path($archive->original_local_path);
        $dir = dirname($dest);
        if (! is_dir($dir)) {
            mkdir($dir, 0750, true);
        }

        $result = $this->megaClient->downloadToAbsolutePath(
            (string) ($megaPath ?? ''),
            $dest,
            $generated,
        );
        if (! $result->ok) {
            throw new \RuntimeException($result->error ?? 'MEGA download failed');
        }

        $archive->update(['local_deleted_at' => null]);
    }
}
