<?php

namespace Tests\Feature\AdminPanel;

use App\Contracts\MegaArchiveClient;
use App\Models\Admin;
use App\Models\ExternalFileArchive;
use App\Models\FreeReservationRequest;
use App\Models\FreeReservationRequestAttachment;
use App\Models\FreeReservationRequestSegment;
use App\Models\FreeReservationRequestVehicle;
use App\Models\ListOfTimeSlot;
use App\Models\VehicleType;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\Support\MegaArchiveFakeClient;
use Tests\TestCase;

final class FzbrAdminReviewAndAttachmentPreviewTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow(Carbon::parse('2026-06-01 12:00:00', 'Europe/Podgorica'));
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    public function test_free_reservations_page_contains_fzbr_review_heading(): void
    {
        $admin = $this->seedAdmin();
        $this->actingAs($admin, 'panel_admin')
            ->get(route('panel_admin.free-reservations', [], false))
            ->assertOk()
            ->assertSee('Pregled besplatnih rezervacija', false);
    }

    public function test_clean_route_does_not_auto_apply_fzbr_review_filters_or_show_results(): void
    {
        $admin = $this->seedAdmin();
        $this->actingAs($admin, 'panel_admin');

        $this->seedTerminalRequest(
            status: FreeReservationRequest::STATUS_FULFILLED,
            institutionName: 'FULFILLED_TODAY_MARKER',
            decidedAt: Carbon::parse('2026-06-01 10:00:00', 'Europe/Podgorica'),
        );

        $html = $this->get(route('panel_admin.free-reservations', [], false))
            ->assertOk()
            ->assertDontSee('Reset filter', false)
            ->assertDontSee('FULFILLED_TODAY_MARKER', false)
            ->getContent();

        // Blank form (no auto-defaults)
        $this->assertStringContainsString('name="fzbr_date_from"', $html);
        $this->assertStringContainsString('name="fzbr_date_to"', $html);
        $this->assertStringNotContainsString('name="fzbr_date_from"'."\n".'                               value="2026-06-01"', $html);
    }

    public function test_approved_filter_shows_only_fulfilled_requests(): void
    {
        $admin = $this->seedAdmin();
        $this->actingAs($admin, 'panel_admin');

        $this->seedTerminalRequest(FreeReservationRequest::STATUS_FULFILLED, 'ONLY_FULFILLED', Carbon::now());
        $this->seedTerminalRequest(FreeReservationRequest::STATUS_REJECTED, 'ONLY_REJECTED', Carbon::now());

        $this->get(route('panel_admin.free-reservations', [
            'fzbr_review' => 'approved',
            'fzbr_date_from' => '2026-06-01',
            'fzbr_date_to' => '2026-06-01',
        ], false))
            ->assertOk()
            ->assertSee('ONLY_FULFILLED', false)
            ->assertDontSee('ONLY_REJECTED', false);
    }

    public function test_rejected_filter_shows_only_rejected_requests(): void
    {
        $admin = $this->seedAdmin();
        $this->actingAs($admin, 'panel_admin');

        $this->seedTerminalRequest(FreeReservationRequest::STATUS_FULFILLED, 'FUL_X', Carbon::now());
        $this->seedTerminalRequest(FreeReservationRequest::STATUS_REJECTED, 'REJ_UNIQUE_MARKER', Carbon::now());

        $this->get(route('panel_admin.free-reservations', [
            'fzbr_review' => 'rejected',
            'fzbr_date_from' => '2026-06-01',
            'fzbr_date_to' => '2026-06-01',
        ], false))
            ->assertOk()
            ->assertSee('REJ_UNIQUE_MARKER', false)
            ->assertDontSee('FUL_X', false);
    }

    public function test_fzbr_date_filters_apply_on_updated_at(): void
    {
        $admin = $this->seedAdmin();
        $this->actingAs($admin, 'panel_admin');

        $this->seedTerminalRequest(FreeReservationRequest::STATUS_FULFILLED, 'IN_RANGE_FZBR', Carbon::parse('2026-05-10 15:00:00', 'Europe/Podgorica'));
        $this->seedTerminalRequest(FreeReservationRequest::STATUS_FULFILLED, 'OUT_RANGE_FZBR', Carbon::parse('2026-07-20 15:00:00', 'Europe/Podgorica'));

        $this->get(route('panel_admin.free-reservations', [
            'fzbr_review' => 'approved',
            'fzbr_date_from' => '2026-05-08',
            'fzbr_date_to' => '2026-05-12',
        ], false))
            ->assertOk()
            ->assertSee('IN_RANGE_FZBR', false)
            ->assertDontSee('OUT_RANGE_FZBR', false);
    }

    public function test_fzbr_review_filtered_page_shows_reset_filter_link_to_clean_route(): void
    {
        $admin = $this->seedAdmin();
        $this->actingAs($admin, 'panel_admin');

        $cleanUrl = route('panel_admin.free-reservations', [], false);
        $filteredUrl = route('panel_admin.free-reservations', [
            'fzbr_review' => 'rejected',
            'fzbr_date_from' => '2026-05-08',
            'fzbr_date_to' => '2026-05-12',
        ], false);

        $html = $this->get($filteredUrl)
            ->assertOk()
            ->assertSee('Reset filter', false)
            ->getContent();

        $this->assertStringContainsString('href="'.$cleanUrl.'"', $html);
    }

    public function test_fzbr_review_clean_page_does_not_show_reset_filter(): void
    {
        $admin = $this->seedAdmin();
        $this->actingAs($admin, 'panel_admin');

        $this->get(route('panel_admin.free-reservations', [], false))
            ->assertOk()
            ->assertDontSee('Reset filter', false)
            ->assertSee('Unesite kriterijume i pokrenite pretragu.', false);
    }

    public function test_fzbr_review_date_filters_still_apply_when_reset_filter_not_used(): void
    {
        $admin = $this->seedAdmin();
        $this->actingAs($admin, 'panel_admin');

        $this->seedTerminalRequest(FreeReservationRequest::STATUS_FULFILLED, 'IN_RANGE_FZBR', Carbon::parse('2026-05-10 15:00:00', 'Europe/Podgorica'));
        $this->seedTerminalRequest(FreeReservationRequest::STATUS_FULFILLED, 'OUT_RANGE_FZBR', Carbon::parse('2026-07-20 15:00:00', 'Europe/Podgorica'));

        $this->get(route('panel_admin.free-reservations', [
            'fzbr_review' => 'approved',
            'fzbr_date_from' => '2026-05-08',
            'fzbr_date_to' => '2026-05-12',
        ], false))
            ->assertOk()
            ->assertSee('IN_RANGE_FZBR', false)
            ->assertDontSee('OUT_RANGE_FZBR', false)
            ->assertSee('Reset filter', false);
    }

    public function test_fzbr_attachment_preview_local_png(): void
    {
        Storage::fake('local');
        $fake = new MegaArchiveFakeClient;
        $this->app->instance(MegaArchiveClient::class, $fake);

        $admin = $this->seedAdmin();
        $this->actingAs($admin, 'panel_admin');

        [, $att] = $this->seedTerminalRequestWithAttachment(
            FreeReservationRequest::STATUS_FULFILLED,
            'PNG_AG',
            Carbon::now(),
            'scan.png',
            'image/png',
        );

        $this->get(route('panel_admin.fzbr-attachments.preview', ['freeReservationRequestAttachment' => $att->id], false))
            ->assertOk()
            ->assertHeader('content-type', 'image/png');

        $this->assertSame(0, $fake->downloadCalls);
    }

    public function test_fzbr_attachment_preview_local_pdf(): void
    {
        Storage::fake('local');
        $fake = new MegaArchiveFakeClient;
        $this->app->instance(MegaArchiveClient::class, $fake);

        $admin = $this->seedAdmin();
        $this->actingAs($admin, 'panel_admin');

        [, $att] = $this->seedTerminalRequestWithAttachment(
            FreeReservationRequest::STATUS_FULFILLED,
            'PDF_AG',
            Carbon::now(),
            'doc.pdf',
            'application/pdf',
        );

        $this->get(route('panel_admin.fzbr-attachments.preview', ['freeReservationRequestAttachment' => $att->id], false))
            ->assertOk()
            ->assertHeader('content-type', 'application/pdf');

        $this->assertSame(0, $fake->downloadCalls);
    }

    public function test_fzbr_preview_restores_from_mega_when_archived_and_local_missing(): void
    {
        Storage::fake('local');
        $fake = new MegaArchiveFakeClient;
        $this->app->instance(MegaArchiveClient::class, $fake);

        $admin = $this->seedAdmin();
        $this->actingAs($admin, 'panel_admin');

        [, $att, $path] = $this->seedTerminalRequestWithAttachment(
            FreeReservationRequest::STATUS_REJECTED,
            'MEGA_AG',
            Carbon::now(),
            'gone.pdf',
            'application/pdf',
        );

        ExternalFileArchive::query()->create([
            'source_table' => (new FreeReservationRequestAttachment)->getTable(),
            'source_id' => $att->id,
            'source_column' => 'stored_path',
            'context_type' => 'fzbr_attachment',
            'archive_provider' => ExternalFileArchive::PROVIDER_MEGA,
            'generated_file_name' => 'fz__att_'.$att->id.'__'.Str::uuid()->toString().'.pdf',
            'mega_node_id' => 'n',
            'mega_path' => 'bus.kotor/fz.pdf',
            'original_local_path' => $path,
            'local_deleted_at' => now()->subDay(),
            'archived_at' => now()->subDay(),
            'status' => ExternalFileArchive::STATUS_UPLOADED,
            'error_message' => null,
        ]);

        Storage::disk('local')->delete($path);

        $this->assertFalse(Storage::disk('local')->exists($path));

        $this->get(route('panel_admin.fzbr-attachments.preview', ['freeReservationRequestAttachment' => $att->id], false))
            ->assertOk();

        $this->assertSame(1, $fake->downloadCalls);
        $this->assertTrue(Storage::disk('local')->exists($path));
    }

    public function test_guest_cannot_access_fzbr_attachment_preview(): void
    {
        Storage::fake('local');
        [, $att] = $this->seedTerminalRequestWithAttachment(
            FreeReservationRequest::STATUS_FULFILLED,
            'GUEST',
            Carbon::now(),
            'x.pdf',
            'application/pdf',
        );

        $this->get(route('panel_admin.fzbr-attachments.preview', ['freeReservationRequestAttachment' => $att->id], false))
            ->assertRedirect();
    }

    public function test_unsafe_attachment_path_returns_404_on_fzbr_preview(): void
    {
        Storage::fake('local');
        $fake = new MegaArchiveFakeClient;
        $this->app->instance(MegaArchiveClient::class, $fake);

        $admin = $this->seedAdmin();
        $this->actingAs($admin, 'panel_admin');

        [, $att] = $this->seedTerminalRequestWithAttachment(
            FreeReservationRequest::STATUS_FULFILLED,
            'UNSAFE',
            Carbon::now(),
            'bad.pdf',
            'application/pdf',
        );
        $att->update(['stored_path' => '../../../.env']);

        $this->get(route('panel_admin.fzbr-attachments.preview', ['freeReservationRequestAttachment' => $att->id], false))
            ->assertNotFound();

        $this->assertSame(0, $fake->downloadCalls);
    }

    public function test_preview_cleanup_removes_restored_fzbr_file_but_not_locally_retained(): void
    {
        Storage::fake('local');
        $path = 'free-reservation-requests/999/archived-only.pdf';
        Storage::disk('local')->put($path, 'body');

        $archive = ExternalFileArchive::query()->create([
            'source_table' => (new FreeReservationRequestAttachment)->getTable(),
            'source_id' => 42,
            'source_column' => 'stored_path',
            'context_type' => 'fzbr_attachment',
            'archive_provider' => ExternalFileArchive::PROVIDER_MEGA,
            'generated_file_name' => 'fz__cleanup__'.Str::uuid()->toString().'.pdf',
            'mega_node_id' => 'n',
            'mega_path' => 'bus.kotor/x.pdf',
            'original_local_path' => $path,
            'local_deleted_at' => now()->subDays(2),
            'archived_at' => now()->subDays(2),
            'preview_restored_at' => now()->subHours(2),
            'preview_expires_at' => now()->subMinute(),
            'status' => ExternalFileArchive::STATUS_UPLOADED,
            'error_message' => null,
        ]);

        Artisan::call('files:cleanup-preview-cache');

        $this->assertFalse(Storage::disk('local')->exists($path));
        $archive->refresh();
        $this->assertNull($archive->preview_expires_at);
        $this->assertNotNull($archive->local_deleted_at);

        $pathKeep = 'free-reservation-requests/998/kept.pdf';
        Storage::disk('local')->put($pathKeep, 'keep');
        $archiveKeep = ExternalFileArchive::query()->create([
            'source_table' => (new FreeReservationRequestAttachment)->getTable(),
            'source_id' => 43,
            'source_column' => 'stored_path',
            'context_type' => 'fzbr_attachment',
            'archive_provider' => ExternalFileArchive::PROVIDER_MEGA,
            'generated_file_name' => 'fz__keep__'.Str::uuid()->toString().'.pdf',
            'mega_node_id' => 'n',
            'mega_path' => 'bus.kotor/y.pdf',
            'original_local_path' => $pathKeep,
            'local_deleted_at' => null,
            'archived_at' => now()->subDay(),
            'preview_restored_at' => now()->subHour(),
            'preview_expires_at' => now()->subMinute(),
            'status' => ExternalFileArchive::STATUS_UPLOADED,
            'error_message' => null,
        ]);

        Artisan::call('files:cleanup-preview-cache');

        $this->assertTrue(Storage::disk('local')->exists($pathKeep));
        $archiveKeep->refresh();
        $this->assertNotNull($archiveKeep->preview_expires_at);
    }

    private function seedAdmin(): Admin
    {
        return Admin::query()->create([
            'username' => 'fzbr_review_admin',
            'email' => 'fzbr-review@example.com',
            'password' => bcrypt('secret'),
            'control_access' => false,
            'admin_access' => true,
        ]);
    }

    private function seedTerminalRequest(string $status, string $institutionName, Carbon $decidedAt): FreeReservationRequest
    {
        [$req] = $this->seedTerminalRequestWithAttachment($status, $institutionName, $decidedAt, 'd.pdf', 'application/pdf');

        return $req;
    }

    /**
     * @return array{0: FreeReservationRequest, 1: FreeReservationRequestAttachment, 2: string}
     */
    private function seedTerminalRequestWithAttachment(
        string $status,
        string $institutionName,
        Carbon $decidedAt,
        string $fileName,
        string $mime,
    ): array {
        $slotA = ListOfTimeSlot::query()->create(['time_slot' => '10:00 - 10:20']);
        $slotB = ListOfTimeSlot::query()->create(['time_slot' => '11:00 - 11:20']);
        $vt = VehicleType::query()->create(['price' => 10]);

        $req = FreeReservationRequest::query()->create([
            'locale' => 'cg',
            'institution_name' => $institutionName,
            'institution_email' => 'fzbr-'.Str::random(4).'@example.com',
            'institution_phone' => null,
            'reservation_date' => Carbon::now()->addDays(2)->toDateString(),
            'drop_off_time_slot_id' => $slotA->id,
            'pick_up_time_slot_id' => $slotB->id,
            'country' => 'ME',
            'status' => $status,
        ]);

        $seg = FreeReservationRequestSegment::query()->create([
            'request_id' => $req->id,
            'reservation_date' => $req->reservation_date,
            'drop_off_time_slot_id' => $slotA->id,
            'pick_up_time_slot_id' => $slotB->id,
            'position' => 1,
        ]);
        FreeReservationRequestVehicle::query()->create([
            'request_id' => $req->id,
            'segment_id' => $seg->id,
            'license_plate' => 'KO88FZ',
            'vehicle_type_id' => $vt->id,
            'vehicle_type_label' => 'Bus',
        ]);

        $relPath = 'free-reservation-requests/'.$req->id.'/'.$fileName;
        Storage::disk('local')->put($relPath, $mime === 'application/pdf' ? '%PDF-1.4 test' : random_bytes(80));

        $att = FreeReservationRequestAttachment::query()->create([
            'request_id' => $req->id,
            'original_name' => $fileName,
            'stored_path' => $relPath,
            'mime_type' => $mime,
            'size_bytes' => 100,
        ]);

        DB::table('free_reservation_requests')->where('id', $req->id)->update([
            'updated_at' => $decidedAt,
            'created_at' => $decidedAt->copy()->subHours(2),
        ]);

        $req->refresh();

        return [$req, $att, $relPath];
    }
}
