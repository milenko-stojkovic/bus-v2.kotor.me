<?php

namespace App\Console\Commands;

use App\Models\ExternalFileArchive;
use App\Models\FreeReservationRequest;
use App\Models\FreeReservationRequestAttachment;
use App\Models\LimoPickupPhoto;
use App\Models\LimoPlateUpload;
use App\Services\ExternalArchive\ExternalFileArchiveService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Storage;
use Throwable;

/**
 * Offloads eligible private-disk files to MEGA and deletes local copies after success.
 * Conservative: only terminal FZBR, consumed plate uploads, pickup photos after invoice email sent.
 * Does not handle limo_incidents yet (evidence / email flows) — see TODO in handle().
 */
class ArchivePrivateFilesCommand extends Command
{
    protected $signature = 'files:archive-private
                            {--source=all : all|fzbr|limo}
                            {--dry-run : List candidates; no upload or delete}
                            {--limit=100 : Max candidates per category (fzbr / limo plates / limo pickup photos)}';

    protected $description = 'Archive eligible private files to MEGA (server-side only)';

    public function handle(ExternalFileArchiveService $archiveService): int
    {
        $source = strtolower(trim((string) $this->option('source')));
        if (! in_array($source, ['all', 'fzbr', 'limo'], true)) {
            $this->error('Invalid --source (use all, fzbr, or limo).');

            return self::INVALID;
        }

        $dryRun = (bool) $this->option('dry-run');
        $limit = max(1, (int) $this->option('limit'));

        if ($dryRun) {
            $this->warn('DRY RUN: no uploads and no local deletes.');
        }

        $scanned = $archived = $failed = $skipped = 0;
        $disk = Storage::disk('local');

        $fzbrTable = (new FreeReservationRequestAttachment)->getTable();
        $plateTable = (new LimoPlateUpload)->getTable();
        $pickupPhotoTable = (new LimoPickupPhoto)->getTable();

        if ($source === 'all' || $source === 'fzbr') {
            $attachments = FreeReservationRequestAttachment::query()
                ->select($fzbrTable.'.*')
                ->join('free_reservation_requests', 'free_reservation_requests.id', '=', $fzbrTable.'.request_id')
                ->whereIn('free_reservation_requests.status', [
                    FreeReservationRequest::STATUS_FULFILLED,
                    FreeReservationRequest::STATUS_REJECTED,
                ])
                ->orderBy($fzbrTable.'.id')
                ->limit($limit)
                ->get();

            foreach ($attachments as $att) {
                $scanned++;
                $path = (string) $att->stored_path;
                $col = 'stored_path';
                if ($path === '') {
                    $skipped++;

                    continue;
                }
                if ($this->hasUploadedArchive($fzbrTable, (int) $att->id, $col)) {
                    $skipped++;

                    continue;
                }
                if (! $disk->exists($path)) {
                    $skipped++;

                    continue;
                }
                if ($dryRun) {
                    $archived++;

                    continue;
                }
                try {
                    $archiveService->archiveLocalPrivateFile(
                        $fzbrTable,
                        (int) $att->id,
                        $col,
                        $path,
                        'fzbr_attachment',
                    );
                    $archived++;
                } catch (Throwable $e) {
                    $failed++;
                    $this->error('FZBR attachment '.$att->id.': '.$e->getMessage());
                }
            }
        }

        if ($source === 'all' || $source === 'limo') {
            // Plate uploads consumed (OCR / confirm / incident-from-upload flows set consumed_at when applicable).
            $plates = LimoPlateUpload::query()
                ->whereNotNull('consumed_at')
                ->whereNotNull('path')
                ->where('path', '!=', '')
                ->orderBy('id')
                ->limit($limit)
                ->get();

            foreach ($plates as $row) {
                $scanned++;
                $path = (string) $row->path;
                $col = 'path';
                if ($this->hasUploadedArchive($plateTable, (int) $row->id, $col)) {
                    $skipped++;

                    continue;
                }
                if (! $disk->exists($path)) {
                    $skipped++;

                    continue;
                }
                if ($dryRun) {
                    $archived++;

                    continue;
                }
                try {
                    $archiveService->archiveLocalPrivateFile(
                        $plateTable,
                        (int) $row->id,
                        $col,
                        $path,
                        'limo_plate_upload',
                    );
                    $archived++;
                } catch (Throwable $e) {
                    $failed++;
                    $this->error('limo_plate_uploads '.$row->id.': '.$e->getMessage());
                }
            }

            // Pickup photos: only after invoice email is sent for the parent event (conservative).
            $photos = LimoPickupPhoto::query()
                ->select($pickupPhotoTable.'.*')
                ->join('limo_pickup_events', 'limo_pickup_events.id', '=', $pickupPhotoTable.'.limo_pickup_event_id')
                ->whereNotNull('limo_pickup_events.invoice_email_sent_at')
                ->whereNotNull($pickupPhotoTable.'.path')
                ->where($pickupPhotoTable.'.path', '!=', '')
                ->orderBy($pickupPhotoTable.'.id')
                ->limit($limit)
                ->get();

            foreach ($photos as $photo) {
                $scanned++;
                $path = (string) $photo->path;
                $col = 'path';
                if ($this->hasUploadedArchive($pickupPhotoTable, (int) $photo->id, $col)) {
                    $skipped++;

                    continue;
                }
                if (! $disk->exists($path)) {
                    $skipped++;

                    continue;
                }
                if ($dryRun) {
                    $archived++;

                    continue;
                }
                try {
                    $archiveService->archiveLocalPrivateFile(
                        $pickupPhotoTable,
                        (int) $photo->id,
                        $col,
                        $path,
                        'limo_pickup_photo',
                    );
                    $archived++;
                } catch (Throwable $e) {
                    $failed++;
                    $this->error('limo_pickup_photos '.$photo->id.': '.$e->getMessage());
                }
            }

            // TODO: limo_incidents private files — only after explicit policy (pending email / evidence retention).
        }

        $this->info('Scanned: '.$scanned);
        $this->info($dryRun ? 'Would archive: '.$archived : 'Archived: '.$archived);
        $this->info('Failed: '.$failed);
        $this->info('Skipped: '.$skipped);

        return self::SUCCESS;
    }

    private function hasUploadedArchive(string $sourceTable, int $sourceId, string $sourceColumn): bool
    {
        return ExternalFileArchive::query()
            ->where('source_table', $sourceTable)
            ->where('source_id', $sourceId)
            ->where('source_column', $sourceColumn)
            ->where('status', ExternalFileArchive::STATUS_UPLOADED)
            ->exists();
    }
}
