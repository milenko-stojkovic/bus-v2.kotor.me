<?php

namespace Tests\Feature\ExternalArchive;

use App\Contracts\MegaArchiveClient;
use App\Models\ExternalFileArchive;
use App\Models\FreeReservationRequest;
use App\Models\FreeReservationRequestAttachment;
use App\Models\ListOfTimeSlot;
use App\Models\VehicleType;
use App\Models\VehicleTypeTranslation;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Storage;
use Tests\Support\MegaArchiveFakeClient;
use Tests\TestCase;

class ArchivePrivateFilesCommandTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow(Carbon::parse('2026-05-14 12:00:00', 'Europe/Podgorica'));
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    public function test_dry_run_does_not_upload_or_delete(): void
    {
        Storage::fake('local');
        $fake = new MegaArchiveFakeClient;
        $this->app->instance(MegaArchiveClient::class, $fake);

        [, , $relPath] = $this->makeFulfilledFzbrWithAttachmentFile();

        Artisan::call('files:archive-private', [
            '--source' => 'fzbr',
            '--dry-run' => true,
            '--limit' => 10,
        ]);

        $this->assertSame(0, $fake->uploadCalls);
        $this->assertTrue(Storage::disk('local')->exists($relPath));
        $this->assertSame(0, ExternalFileArchive::query()->count());
    }

    public function test_command_archives_fzbr_attachment(): void
    {
        Storage::fake('local');
        $fake = new MegaArchiveFakeClient;
        $this->app->instance(MegaArchiveClient::class, $fake);

        [, $att, $relPath] = $this->makeFulfilledFzbrWithAttachmentFile();

        Artisan::call('files:archive-private', [
            '--source' => 'fzbr',
            '--limit' => 10,
        ]);

        $this->assertSame(1, $fake->uploadCalls);
        $this->assertFalse(Storage::disk('local')->exists($relPath));
        $this->assertDatabaseHas('external_file_archives', [
            'source_table' => (new FreeReservationRequestAttachment)->getTable(),
            'source_id' => $att->id,
            'source_column' => 'stored_path',
            'status' => ExternalFileArchive::STATUS_UPLOADED,
        ]);
    }

    public function test_already_uploaded_is_skipped(): void
    {
        Storage::fake('local');
        $fake = new MegaArchiveFakeClient;
        $this->app->instance(MegaArchiveClient::class, $fake);

        [, $att, $relPath] = $this->makeFulfilledFzbrWithAttachmentFile();

        ExternalFileArchive::query()->create([
            'source_table' => (new FreeReservationRequestAttachment)->getTable(),
            'source_id' => $att->id,
            'source_column' => 'stored_path',
            'context_type' => 'fzbr_attachment',
            'archive_provider' => ExternalFileArchive::PROVIDER_MEGA,
            'generated_file_name' => 'existing__free_reservation_request_attachments_'.$att->id.'__stored_path__00000000-0000-4000-8000-000000000001.pdf',
            'mega_node_id' => 'x',
            'mega_path' => 'bus.kotor/existing.pdf',
            'original_local_path' => $relPath,
            'local_deleted_at' => now(),
            'archived_at' => now(),
            'status' => ExternalFileArchive::STATUS_UPLOADED,
            'error_message' => null,
        ]);

        Artisan::call('files:archive-private', [
            '--source' => 'fzbr',
            '--limit' => 10,
        ]);

        $this->assertSame(0, $fake->uploadCalls);
        $this->assertTrue(Storage::disk('local')->exists($relPath));
    }

    /**
     * @return array{0: FreeReservationRequest, 1: FreeReservationRequestAttachment, 2: string}
     */
    private function makeFulfilledFzbrWithAttachmentFile(): array
    {
        $slotA = ListOfTimeSlot::query()->create(['time_slot' => '10:00 - 10:20']);
        $slotB = ListOfTimeSlot::query()->create(['time_slot' => '11:00 - 11:20']);
        $vt = VehicleType::query()->create(['price' => 10]);
        VehicleTypeTranslation::query()->create([
            'vehicle_type_id' => $vt->id,
            'locale' => 'cg',
            'name' => 'Autobus',
            'description' => 'x',
        ]);

        $req = FreeReservationRequest::query()->create([
            'locale' => 'cg',
            'institution_name' => 'Test',
            'institution_email' => 't@example.com',
            'institution_phone' => '+382000',
            'reservation_date' => Carbon::now()->addDays(2)->toDateString(),
            'drop_off_time_slot_id' => $slotA->id,
            'pick_up_time_slot_id' => $slotB->id,
            'country' => 'ME',
            'status' => FreeReservationRequest::STATUS_FULFILLED,
        ]);

        $relPath = 'fzbr_docs/'.$req->id.'/doc.pdf';
        Storage::disk('local')->put($relPath, '%PDF-1.4 fake');

        $att = FreeReservationRequestAttachment::query()->create([
            'request_id' => $req->id,
            'original_name' => 'doc.pdf',
            'stored_path' => $relPath,
            'mime_type' => 'application/pdf',
            'size_bytes' => 9,
        ]);

        return [$req, $att, $relPath];
    }
}
