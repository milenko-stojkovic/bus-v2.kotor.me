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
    private const UPLOAD_MAX_ATTEMPTS = 3;

    /**
     * Milliseconds to wait after failed attempt N before attempt N+1.
     *
     * @var list<int>
     */
    private const UPLOAD_RETRY_BACKOFF_MS = [1000, 3000];

    private const PREVIEW_DOWNLOAD_MAX_ATTEMPTS = 3;

    /**
     * Microseconds before attempt 2 and 3 (attempt 1 is immediate).
     *
     * @var list<int>
     */
    private const PREVIEW_RETRY_DELAY_MICROS = [400_000, 1_500_000];

    public function __construct(
        private readonly MegaArchiveClient $megaClient,
        private readonly MegaArchiveFailureClassifier $failureClassifier,
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
        ?ArchiveDerivativeUpload $derivativeUpload = null,
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
            $derivativeUpload?->generatedExtension,
        );

        $absolute = $derivativeUpload !== null
            ? $derivativeUpload->uploadAbsolutePath
            : $disk->path($localPath);

        $derivativeTempPath = $derivativeUpload?->uploadAbsolutePath;

        if ($derivativeUpload !== null) {
            Log::channel('payments')->info('external_archive_derivative_prepared', [
                'source_table' => $sourceTable,
                'source_id' => $sourceId,
                'original_local_path' => $localPath,
                'derivative_source_path' => $derivativeUpload->derivativeSourcePath,
                'original_bytes' => $derivativeUpload->originalBytes,
                'archive_bytes' => $derivativeUpload->archiveBytes,
                'reduction_percent' => $derivativeUpload->reductionPercent(),
                'derivative_options' => $derivativeUpload->derivativeOptions,
            ]);
        }

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
            'archived_derivative' => $derivativeUpload !== null,
            'derivative_source_path' => $derivativeUpload?->derivativeSourcePath,
            'derivative_options' => $derivativeUpload?->derivativeOptions,
            'local_deleted_at' => null,
            'archived_at' => null,
            'status' => ExternalFileArchive::STATUS_PENDING,
            'error_message' => null,
        ]);

        return $this->runMegaUploadForPendingRow(
            $row,
            $sourceTable,
            $sourceId,
            $sourceColumn,
            $contextType,
            $localPath,
            $absolute,
            $generated,
            $derivativeTempPath,
            $derivativeUpload,
            false,
        );
    }

    /**
     * Re-run MEGA upload for an existing row in {@see ExternalFileArchive::STATUS_FAILED}, updating the same row.
     *
     * Does not delete MEGA objects. Caller must supply {@see ArchiveDerivativeUpload} when the row uses a derivative.
     *
     * @throws \InvalidArgumentException When preconditions are not met (missing file, duplicate uploaded row, etc.)
     */
    public function retryFailedArchive(ExternalFileArchive $row, ?ArchiveDerivativeUpload $derivativeUpload = null): ExternalFileArchive
    {
        if ($row->status !== ExternalFileArchive::STATUS_FAILED) {
            throw new \InvalidArgumentException('Retry is only for failed archive rows.');
        }
        if ($row->archive_provider !== ExternalFileArchive::PROVIDER_MEGA) {
            throw new \InvalidArgumentException('Unsupported archive provider.');
        }

        $localPath = (string) $row->original_local_path;
        if ($localPath === '' || str_contains($localPath, '..')) {
            throw new \InvalidArgumentException('Invalid original_local_path on archive row.');
        }

        $generated = (string) $row->generated_file_name;
        if ($generated === '') {
            throw new \InvalidArgumentException('Missing generated_file_name.');
        }

        $disk = Storage::disk('local');
        if (! $disk->exists($localPath)) {
            throw new \InvalidArgumentException('Local file does not exist on private disk: '.$localPath);
        }

        $sourceTable = (string) $row->source_table;
        $sourceId = (int) $row->source_id;
        $sourceColumn = $row->source_column;
        $contextType = $row->context_type;

        if (ExternalFileArchive::query()
            ->where('source_table', $sourceTable)
            ->where('source_id', $sourceId)
            ->where('source_column', $sourceColumn)
            ->where('status', ExternalFileArchive::STATUS_UPLOADED)
            ->where('id', '!=', $row->id)
            ->exists()) {
            throw new \InvalidArgumentException('An uploaded archive already exists for this source.');
        }

        $isDerivative = (bool) $row->archived_derivative;
        if ($isDerivative && $derivativeUpload === null) {
            throw new \InvalidArgumentException('Derivative payload required for this archive row.');
        }
        if (! $isDerivative && $derivativeUpload !== null) {
            throw new \InvalidArgumentException('This archive row does not use a derivative upload.');
        }
        if ($derivativeUpload !== null && $derivativeUpload->derivativeSourcePath !== $localPath) {
            throw new \InvalidArgumentException('Derivative source path does not match archive row.');
        }

        $row->update([
            'status' => ExternalFileArchive::STATUS_PENDING,
            'error_message' => null,
            'mega_node_id' => null,
            'mega_path' => null,
            'archived_at' => null,
        ]);
        $row->refresh();

        $absolute = $derivativeUpload !== null
            ? $derivativeUpload->uploadAbsolutePath
            : $disk->path($localPath);
        $derivativeTempPath = $derivativeUpload?->uploadAbsolutePath;

        Log::channel('payments')->info('external_archive_admin_retry_started', [
            'external_file_archive_id' => $row->id,
            'source_table' => $sourceTable,
            'source_id' => $sourceId,
        ]);

        if ($derivativeUpload !== null) {
            Log::channel('payments')->info('external_archive_derivative_prepared', [
                'source_table' => $sourceTable,
                'source_id' => $sourceId,
                'original_local_path' => $localPath,
                'derivative_source_path' => $derivativeUpload->derivativeSourcePath,
                'original_bytes' => $derivativeUpload->originalBytes,
                'archive_bytes' => $derivativeUpload->archiveBytes,
                'reduction_percent' => $derivativeUpload->reductionPercent(),
                'derivative_options' => $derivativeUpload->derivativeOptions,
                'admin_retry' => true,
            ]);
        }

        return $this->runMegaUploadForPendingRow(
            $row,
            $sourceTable,
            $sourceId,
            $sourceColumn,
            $contextType,
            $localPath,
            $absolute,
            $generated,
            $derivativeTempPath,
            $derivativeUpload,
            true,
        );
    }

    /**
     * @return ExternalFileArchive Fresh row after upload attempt (uploaded, failed, or pending).
     */
    private function runMegaUploadForPendingRow(
        ExternalFileArchive $row,
        string $sourceTable,
        int $sourceId,
        ?string $sourceColumn,
        ?string $contextType,
        string $localPath,
        string $absolute,
        string $generatedFileName,
        ?string $derivativeTempPath,
        ?ArchiveDerivativeUpload $derivativeUpload,
        bool $isAdminRetry,
    ): ExternalFileArchive {
        $disk = Storage::disk('local');

        Log::channel('payments')->info('external_archive_upload_started', [
            'source_table' => $sourceTable,
            'source_id' => $sourceId,
            'source_column' => $sourceColumn,
            'context_type' => $contextType,
            'generated_file_name' => $generatedFileName,
            'original_local_path' => $localPath,
            'archived_derivative' => $derivativeUpload !== null,
            'external_file_archive_id' => $row->id,
            'admin_retry' => $isAdminRetry,
        ]);

        /** @var MegaUploadResult|null $upload */
        $upload = null;
        try {
            for ($attempt = 1; $attempt <= self::UPLOAD_MAX_ATTEMPTS; $attempt++) {
                $upload = $this->megaClient->uploadLocalFile($absolute, $generatedFileName);

                if ($upload->ok) {
                    if ($attempt > 1) {
                        Log::channel('payments')->info('external_archive_upload_recovered_after_retry', [
                            'external_file_archive_id' => $row->id,
                            'attempt' => $attempt,
                        ]);
                    }
                    break;
                }

                $errorText = $upload->error ?? 'upload_failed';
                $isTransient = $this->failureClassifier->isTransient($errorText);
                $isLastAttempt = $attempt >= self::UPLOAD_MAX_ATTEMPTS;

                if ($isLastAttempt || ! $isTransient) {
                    $row->update([
                        'status' => ExternalFileArchive::STATUS_FAILED,
                        'error_message' => $errorText,
                    ]);
                    Log::channel('payments')->warning('external_archive_upload_exhausted', [
                        'external_file_archive_id' => $row->id,
                        'source_table' => $sourceTable,
                        'source_id' => $sourceId,
                        'attempts' => $attempt,
                        'max_attempts' => self::UPLOAD_MAX_ATTEMPTS,
                        'reason' => $this->failureClassifier->shortReason($errorText),
                        'transient' => $isTransient,
                    ]);
                    Log::channel('payments')->warning('external_archive_upload_failed', [
                        'external_file_archive_id' => $row->id,
                        'source_table' => $sourceTable,
                        'source_id' => $sourceId,
                        'error' => $errorText,
                        'attempts' => $attempt,
                    ]);

                    return $row->refresh();
                }

                Log::channel('payments')->info('external_archive_upload_retry', [
                    'external_file_archive_id' => $row->id,
                    'attempt' => $attempt,
                    'max_attempts' => self::UPLOAD_MAX_ATTEMPTS,
                    'reason' => $this->failureClassifier->shortReason($errorText),
                ]);

                $sleepMs = self::UPLOAD_RETRY_BACKOFF_MS[$attempt - 1] ?? 0;
                if ($sleepMs > 0) {
                    usleep($sleepMs * 1000);
                }
            }
        } finally {
            if ($derivativeTempPath !== null && is_file($derivativeTempPath)) {
                @unlink($derivativeTempPath);
            }
        }

        if ($upload === null || ! $upload->ok) {
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

        // Bookkeeping only: older failed attempts for this source are historical after durable upload.
        try {
            $this->supersedeFailedSiblingsForSource(
                $sourceTable,
                $sourceId,
                $sourceColumn,
                (int) $row->id,
            );
        } catch (Throwable $e) {
            Log::channel('payments')->warning('external_archive_supersede_failed', [
                'external_file_archive_id' => $row->id,
                'source_table' => $sourceTable,
                'source_id' => $sourceId,
                'source_column' => $sourceColumn,
                'error' => $e->getMessage(),
            ]);
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

        $successLog = [
            'external_file_archive_id' => $row->id,
            'mega_path' => $upload->megaPath,
        ];
        if ($derivativeUpload !== null) {
            $successLog['original_bytes'] = $derivativeUpload->originalBytes;
            $successLog['archive_bytes'] = $derivativeUpload->archiveBytes;
            $successLog['reduction_percent'] = $derivativeUpload->reductionPercent();
        }
        if ($isAdminRetry) {
            $successLog['admin_retry'] = true;
        }
        Log::channel('payments')->info('external_archive_upload_succeeded', $successLog);

        return $row->refresh();
    }

    /**
     * Mark failed archive rows for the same logical source as superseded (historical only).
     *
     * Source identity: source_table + source_id + source_column.
     * Does not touch uploaded, pending, or the successful uploaded row itself.
     *
     * @return int Number of rows updated to superseded
     */
    public function supersedeFailedSiblingsForSource(
        string $sourceTable,
        int $sourceId,
        ?string $sourceColumn,
        ?int $exceptArchiveId = null,
    ): int {
        $query = ExternalFileArchive::query()
            ->where('source_table', $sourceTable)
            ->where('source_id', $sourceId)
            ->where('source_column', $sourceColumn)
            ->where('status', ExternalFileArchive::STATUS_FAILED);

        if ($exceptArchiveId !== null) {
            $query->where('id', '!=', $exceptArchiveId);
        }

        $ids = $query->pluck('id')->all();
        if ($ids === []) {
            return 0;
        }

        $updated = ExternalFileArchive::query()
            ->whereIn('id', $ids)
            ->where('status', ExternalFileArchive::STATUS_FAILED)
            ->update(['status' => ExternalFileArchive::STATUS_SUPERSEDED]);

        if ($updated > 0) {
            Log::channel('payments')->info('external_archive_failed_superseded', [
                'source_table' => $sourceTable,
                'source_id' => $sourceId,
                'source_column' => $sourceColumn,
                'except_archive_id' => $exceptArchiveId,
                'superseded_count' => $updated,
                'superseded_ids' => $ids,
            ]);
        }

        return (int) $updated;
    }

    /**
     * Reconcile historical failed rows that already have an uploaded sibling (same source identity).
     * Does not contact MEGA or touch local files.
     *
     * @return array{would_supersede: int, superseded: int, identities: int}
     */
    public function reconcileFailedWithUploadedSiblings(bool $dryRun = false): array
    {
        $uploadedIdentities = ExternalFileArchive::query()
            ->where('status', ExternalFileArchive::STATUS_UPLOADED)
            ->select(['source_table', 'source_id', 'source_column'])
            ->distinct()
            ->get();

        $wouldSupersede = 0;
        $superseded = 0;
        $identitiesTouched = 0;

        foreach ($uploadedIdentities as $identity) {
            $sourceTable = (string) $identity->source_table;
            $sourceId = (int) $identity->source_id;
            $sourceColumn = $identity->source_column;

            $failedCount = ExternalFileArchive::query()
                ->where('source_table', $sourceTable)
                ->where('source_id', $sourceId)
                ->where('source_column', $sourceColumn)
                ->where('status', ExternalFileArchive::STATUS_FAILED)
                ->count();

            if ($failedCount < 1) {
                continue;
            }

            $identitiesTouched++;
            $wouldSupersede += $failedCount;

            if (! $dryRun) {
                $superseded += $this->supersedeFailedSiblingsForSource(
                    $sourceTable,
                    $sourceId,
                    $sourceColumn,
                    null,
                );
            }
        }

        return [
            'would_supersede' => $wouldSupersede,
            'superseded' => $dryRun ? 0 : $superseded,
            'identities' => $identitiesTouched,
        ];
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

    /**
     * Download from MEGA to original path for a short-lived admin preview.
     * Does not clear local_deleted_at (row still represents “canonical copy on MEGA”).
     */
    public function restoreFromMegaForPreview(ExternalFileArchive $archive): void
    {
        if ($archive->status !== ExternalFileArchive::STATUS_UPLOADED) {
            throw new \InvalidArgumentException('Archive is not in uploaded state.');
        }
        if ($archive->archive_provider !== ExternalFileArchive::PROVIDER_MEGA) {
            throw new \InvalidArgumentException('Unsupported archive provider.');
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

        $megaPathStr = (string) ($megaPath ?? '');
        $lastError = 'MEGA download failed';

        for ($attempt = 1; $attempt <= self::PREVIEW_DOWNLOAD_MAX_ATTEMPTS; $attempt++) {
            if ($attempt === 2) {
                usleep(self::PREVIEW_RETRY_DELAY_MICROS[0]);
            } elseif ($attempt === 3) {
                usleep(self::PREVIEW_RETRY_DELAY_MICROS[1]);
            }

            $result = $this->megaClient->downloadToAbsolutePath(
                $megaPathStr,
                $dest,
                $generated,
            );

            if ($result->ok) {
                $ttl = max(1, (int) config('external_archive.preview_ttl_minutes', 60));
                $expires = now()->addMinutes($ttl);
                $archive->update([
                    'preview_restored_at' => now(),
                    'preview_expires_at' => $expires,
                ]);

                Log::channel('payments')->info('external_archive_preview_restored', [
                    'external_file_archive_id' => $archive->id,
                    'original_local_path' => $archive->original_local_path,
                    'preview_expires_at' => $expires->toIso8601String(),
                    'download_attempt' => $attempt,
                ]);

                return;
            }

            $lastError = $result->error ?? 'MEGA download failed';
            $isTransient = $this->failureClassifier->isTransient($lastError);
            $isLastAttempt = $attempt >= self::PREVIEW_DOWNLOAD_MAX_ATTEMPTS;

            if ($isLastAttempt || ! $isTransient) {
                Log::channel('payments')->warning('external_archive_preview_restore_failed', [
                    'external_file_archive_id' => $archive->id,
                    'original_local_path' => $archive->original_local_path,
                    'attempts' => $attempt,
                    'max_attempts' => self::PREVIEW_DOWNLOAD_MAX_ATTEMPTS,
                    'reason' => $this->failureClassifier->shortReason($lastError),
                    'transient_exhausted' => $isTransient && $isLastAttempt,
                ]);
                throw new \RuntimeException($lastError);
            }

            Log::channel('payments')->info('external_archive_preview_restore_retry', [
                'external_file_archive_id' => $archive->id,
                'attempt' => $attempt,
                'max_attempts' => self::PREVIEW_DOWNLOAD_MAX_ATTEMPTS,
                'reason' => $this->failureClassifier->shortReason($lastError),
            ]);
        }
    }

    /**
     * Ensure a private file exists locally for an authorized preview.
     * Uses MEGA only when the file is missing and an uploaded archive row exists.
     */
    public function ensureLocalPreviewForSource(
        string $sourceTable,
        int $sourceId,
        ?string $sourceColumn,
        string $knownRelativePath,
    ): ?TemporaryPreviewFile {
        $disk = Storage::disk('local');
        if ($knownRelativePath === '' || str_contains($knownRelativePath, '..')) {
            return null;
        }

        if ($disk->exists($knownRelativePath)) {
            return new TemporaryPreviewFile($knownRelativePath, false);
        }

        $archive = ExternalFileArchive::query()
            ->where('source_table', $sourceTable)
            ->where('source_id', $sourceId)
            ->where('source_column', $sourceColumn)
            ->where('status', ExternalFileArchive::STATUS_UPLOADED)
            ->where('archive_provider', ExternalFileArchive::PROVIDER_MEGA)
            ->orderByDesc('id')
            ->first();

        if (! $archive instanceof ExternalFileArchive) {
            return null;
        }

        if ($archive->original_local_path !== $knownRelativePath) {
            return null;
        }

        if ($archive->local_deleted_at === null) {
            return null;
        }

        try {
            $this->restoreFromMegaForPreview($archive);
        } catch (Throwable $e) {
            if (! $e instanceof \RuntimeException) {
                Log::channel('payments')->warning('external_archive_preview_restore_failed', [
                    'external_file_archive_id' => $archive->id,
                    'source_table' => $sourceTable,
                    'source_id' => $sourceId,
                    'source_column' => $sourceColumn,
                    'original_local_path' => $knownRelativePath,
                    'exception' => $e::class,
                    'message' => $e->getMessage(),
                ]);
            }

            return null;
        }

        if (! $disk->exists($knownRelativePath)) {
            return null;
        }

        return new TemporaryPreviewFile($knownRelativePath, true);
    }
}
