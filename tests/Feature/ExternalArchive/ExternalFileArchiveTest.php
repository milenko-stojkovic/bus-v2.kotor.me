<?php

namespace Tests\Feature\ExternalArchive;

use App\Contracts\MegaArchiveClient;
use App\Models\ExternalFileArchive;
use App\Services\ExternalArchive\ArchiveFilenameGenerator;
use App\Services\ExternalArchive\ExternalFileArchiveService;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\Support\MegaArchiveFakeClient;
use Tests\TestCase;

class ExternalFileArchiveTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow(Carbon::parse('2026-05-14 10:00:00', 'UTC'));
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    public function test_success_creates_row_uploads_and_deletes_local_file(): void
    {
        Storage::fake('local');
        $fake = new MegaArchiveFakeClient;
        $this->app->instance(MegaArchiveClient::class, $fake);

        $path = 'limo_plate_uploads/test.jpg';
        Storage::disk('local')->put($path, 'binary');

        $svc = $this->app->make(ExternalFileArchiveService::class);
        $row = $svc->archiveLocalPrivateFile('limo_plate_uploads', 1, 'path', $path, 'limo_plate_upload');

        $this->assertSame(1, $fake->uploadCalls);
        $this->assertSame(ExternalFileArchive::STATUS_UPLOADED, $row->status);
        $this->assertNotNull($row->archived_at);
        $this->assertNotNull($row->local_deleted_at);
        $this->assertSame($path, $row->original_local_path);
        $this->assertFalse(Storage::disk('local')->exists($path));
        $this->assertDatabaseHas('external_file_archives', [
            'id' => $row->id,
            'status' => ExternalFileArchive::STATUS_UPLOADED,
        ]);
    }

    public function test_upload_failure_keeps_local_and_marks_failed(): void
    {
        Storage::fake('local');
        $fake = new MegaArchiveFakeClient;
        $fake->uploadShouldFail = true;
        $this->app->instance(MegaArchiveClient::class, $fake);

        $path = 'limo_plate_uploads/fail.jpg';
        Storage::disk('local')->put($path, 'x');

        $svc = $this->app->make(ExternalFileArchiveService::class);
        $row = $svc->archiveLocalPrivateFile('limo_plate_uploads', 2, 'path', $path, 'limo_plate_upload');

        $this->assertSame(ExternalFileArchive::STATUS_FAILED, $row->status);
        $this->assertTrue(Storage::disk('local')->exists($path));
        $this->assertNull($row->local_deleted_at);
    }

    public function test_second_call_returns_existing_uploaded_without_reupload(): void
    {
        Storage::fake('local');
        $fake = new MegaArchiveFakeClient;
        $this->app->instance(MegaArchiveClient::class, $fake);

        $path = 'limo_plate_uploads/twice.jpg';
        Storage::disk('local')->put($path, 'x');

        $svc = $this->app->make(ExternalFileArchiveService::class);
        $first = $svc->archiveLocalPrivateFile('limo_plate_uploads', 9, 'path', $path, 'limo_plate_upload');
        $this->assertSame(1, $fake->uploadCalls);

        $second = $svc->archiveLocalPrivateFile('limo_plate_uploads', 9, 'path', $path, 'limo_plate_upload');
        $this->assertSame($first->id, $second->id);
        $this->assertSame(1, $fake->uploadCalls);
    }

    public function test_generated_names_are_unique_and_safe(): void
    {
        $a = ArchiveFilenameGenerator::generate('ctx', 'tbl', 1, 'col', 'p/file Name.JPG');
        $b = ArchiveFilenameGenerator::generate('ctx', 'tbl', 1, 'col', 'p/file Name.JPG');
        $this->assertNotSame($a, $b);
        $this->assertStringContainsString('.jpg', $a);
        $this->assertMatchesRegularExpression('/^[a-z0-9_]+__[a-z0-9_]+_1__[a-z0-9_]+__[a-f0-9\-]{36}\.jpg$/', $a);
    }

    public function test_timestamps_on_row(): void
    {
        Storage::fake('local');
        $fake = new MegaArchiveFakeClient;
        $this->app->instance(MegaArchiveClient::class, $fake);

        $path = 'x/a.bin';
        Storage::disk('local')->put($path, 'z');

        $svc = $this->app->make(ExternalFileArchiveService::class);
        $row = $svc->archiveLocalPrivateFile('t', 3, 'c', $path, null);

        $this->assertNotNull($row->created_at);
        $this->assertNotNull($row->updated_at);
        $this->assertNotNull($row->archived_at);
        $this->assertNotNull($row->local_deleted_at);
    }

    public function test_restore_from_mega_clears_local_deleted_at(): void
    {
        Storage::fake('local');
        $fake = new MegaArchiveFakeClient;
        $this->app->instance(MegaArchiveClient::class, $fake);

        $path = 'restore/me.pdf';
        Storage::disk('local')->put($path, 'orig');

        $svc = $this->app->make(ExternalFileArchiveService::class);
        $row = $svc->archiveLocalPrivateFile('free_reservation_request_attachments', 5, 'stored_path', $path, 'fzbr_attachment');
        $this->assertFalse(Storage::disk('local')->exists($path));

        $svc->restoreFromMega($row->refresh());
        $row->refresh();
        $this->assertNull($row->local_deleted_at);
        $this->assertTrue(Storage::disk('local')->exists($path));
        $this->assertSame(1, $fake->downloadCalls);
    }

    public function test_restore_passes_generated_file_name_to_client(): void
    {
        Storage::fake('local');
        $fake = new MegaArchiveFakeClient;
        $this->app->instance(MegaArchiveClient::class, $fake);

        $path = 'restore/nopath.bin';
        Storage::disk('local')->put($path, 'x');

        $svc = $this->app->make(ExternalFileArchiveService::class);
        $row = $svc->archiveLocalPrivateFile('t', 7, 'c', $path, 'ctx');

        $row->update([
            'mega_path' => null,
            'mega_node_id' => null,
        ]);

        $svc->restoreFromMega($row->refresh());

        $row->refresh();
        $this->assertSame($row->generated_file_name, $fake->lastDownloadGeneratedFileName);
        $this->assertTrue(Storage::disk('local')->exists($path));
    }
}
