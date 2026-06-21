<?php

namespace Tests\Feature\Vehicles;

use App\Contracts\MegaArchiveClient;
use App\Jobs\ArchiveVehicleCategoryChangeRequestAttachmentsJob;
use App\Models\Admin;
use App\Models\AdminAlert;
use App\Models\User;
use App\Models\Vehicle;
use App\Models\VehicleCategoryChangeRequest;
use App\Models\VehicleCategoryChangeRequestAttachment;
use App\Models\VehicleType;
use App\Models\VehicleTypeTranslation;
use App\Services\ExternalArchive\MegaUploadResult;
use App\Services\VehicleCategoryChange\VehicleCategoryChangeAttachmentArchiveService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Tests\Support\MegaArchiveFakeClient;
use Tests\TestCase;

final class VehicleCategoryChangeAttachmentArchiveTest extends TestCase
{
    use RefreshDatabase;

    /** @return array{a: VehicleType, b: VehicleType, user: User, old: Vehicle} */
    private function seedFixtures(): array
    {
        $a = VehicleType::query()->create(['price' => 10]);
        $b = VehicleType::query()->create(['price' => 20]);
        foreach ([$a, $b] as $t) {
            VehicleTypeTranslation::query()->create(['vehicle_type_id' => $t->id, 'locale' => 'en', 'name' => 'T'.$t->id, 'description' => null]);
            VehicleTypeTranslation::query()->create(['vehicle_type_id' => $t->id, 'locale' => 'cg', 'name' => 'T'.$t->id, 'description' => null]);
        }

        $user = User::factory()->create(['lang' => 'cg', 'email' => 'archive@example.com', 'name' => 'Archive Agency']);
        $old = Vehicle::query()->create([
            'user_id' => $user->id,
            'license_plate' => 'KO333',
            'vehicle_type_id' => $a->id,
            'status' => Vehicle::STATUS_REMOVED,
        ]);

        return compact('a', 'b', 'user', 'old');
    }

    private function seedAdmin(): Admin
    {
        return Admin::query()->create([
            'username' => 'archiveadmin',
            'email' => 'archive-admin@example.com',
            'password' => bcrypt('secret-password-archive'),
            'control_access' => false,
            'admin_access' => true,
        ]);
    }

    /** @param array<int, UploadedFile> $files */
    private function submitRequest(array $fixtures, array $files): VehicleCategoryChangeRequest
    {
        $this->post(route('panel.vehicles.category_change_requests.store', [], false), [
            'old_vehicle_id' => $fixtures['old']->id,
            'license_plate' => 'KO333',
            'old_vehicle_type_id' => $fixtures['a']->id,
            'requested_vehicle_type_id' => $fixtures['b']->id,
            'documents' => $files,
        ])->assertRedirect(route('panel.vehicles', [], false));

        return VehicleCategoryChangeRequest::query()->firstOrFail();
    }

    private function seedAlert(VehicleCategoryChangeRequest $req, User $user): void
    {
        AdminAlert::query()->create([
            'type' => 'vehicle_category_change_request',
            'status' => AdminAlert::STATUS_UNREAD,
            'title' => 't',
            'message' => 'm',
            'payload_json' => [
                'vehicle_category_change_request_id' => (int) $req->id,
                'user_id' => (int) $user->id,
                'license_plate' => 'KO333',
            ],
        ]);
    }

    public function test_approving_request_dispatches_archive_job_after_commit(): void
    {
        Storage::fake('local');
        Mail::fake();
        Queue::fake();
        $fixtures = $this->seedFixtures();
        $this->actingAs($fixtures['user']);

        $req = $this->submitRequest($fixtures, [
            UploadedFile::fake()->create('a.pdf', 10, 'application/pdf'),
        ]);
        $this->seedAlert($req, $fixtures['user']);

        $admin = $this->seedAdmin();
        $this->actingAs($admin, 'panel_admin');

        $this->post(route('panel_admin.agencies.vehicle_category_change_requests.approve', [
            'user' => $fixtures['user']->id,
            'request' => $req->id,
        ], false))->assertRedirect(route('panel_admin.agencies.show', $fixtures['user'], false));

        Queue::assertPushed(ArchiveVehicleCategoryChangeRequestAttachmentsJob::class, function (ArchiveVehicleCategoryChangeRequestAttachmentsJob $job) use ($req): bool {
            return $job->vehicleCategoryChangeRequestId === (int) $req->id;
        });
    }

    public function test_rejecting_request_dispatches_archive_job_after_commit(): void
    {
        Storage::fake('local');
        Mail::fake();
        Queue::fake();
        $fixtures = $this->seedFixtures();
        $this->actingAs($fixtures['user']);

        $req = $this->submitRequest($fixtures, [
            UploadedFile::fake()->create('a.pdf', 10, 'application/pdf'),
        ]);
        $this->seedAlert($req, $fixtures['user']);

        $admin = $this->seedAdmin();
        $this->actingAs($admin, 'panel_admin');

        $this->post(route('panel_admin.agencies.vehicle_category_change_requests.reject', [
            'user' => $fixtures['user']->id,
            'request' => $req->id,
        ], false), [
            'reason' => 'Dokument nije validan.',
        ])->assertRedirect(route('panel_admin.agencies.show', $fixtures['user'], false));

        Queue::assertPushed(ArchiveVehicleCategoryChangeRequestAttachmentsJob::class, function (ArchiveVehicleCategoryChangeRequestAttachmentsJob $job) use ($req): bool {
            return $job->vehicleCategoryChangeRequestId === (int) $req->id;
        });
    }

    public function test_pending_request_does_not_dispatch_archive_job_on_submit(): void
    {
        Storage::fake('local');
        Mail::fake();
        Queue::fake();
        $fixtures = $this->seedFixtures();
        $this->actingAs($fixtures['user']);

        $this->submitRequest($fixtures, [
            UploadedFile::fake()->create('a.pdf', 10, 'application/pdf'),
        ]);

        Queue::assertNotPushed(ArchiveVehicleCategoryChangeRequestAttachmentsJob::class);
    }

    public function test_job_skips_pending_request(): void
    {
        Storage::fake('local');
        $fixtures = $this->seedFixtures();

        $req = VehicleCategoryChangeRequest::query()->create([
            'user_id' => $fixtures['user']->id,
            'old_vehicle_id' => $fixtures['old']->id,
            'license_plate' => 'KO333',
            'old_vehicle_type_id' => $fixtures['a']->id,
            'requested_vehicle_type_id' => $fixtures['b']->id,
            'status' => VehicleCategoryChangeRequest::STATUS_PENDING,
            'document_original_name' => 'x.pdf',
            'document_path' => 'vehicle-category-change-requests/pending/x.pdf',
            'document_mime_type' => 'application/pdf',
            'document_size_bytes' => 10,
            'locale' => 'cg',
        ]);

        $fake = new MegaArchiveFakeClient;
        $this->app->instance(MegaArchiveClient::class, $fake);

        (new ArchiveVehicleCategoryChangeRequestAttachmentsJob((int) $req->id))->handle(
            app(VehicleCategoryChangeAttachmentArchiveService::class),
        );

        $this->assertSame(0, $fake->uploadCalls);
    }

    public function test_job_uploads_local_attachments_and_marks_archive_metadata(): void
    {
        Storage::fake('local');
        Mail::fake();
        $fixtures = $this->seedFixtures();
        $this->actingAs($fixtures['user']);

        $req = $this->submitRequest($fixtures, [
            UploadedFile::fake()->create('doc.pdf', 10, 'application/pdf'),
        ]);
        $req->update([
            'status' => VehicleCategoryChangeRequest::STATUS_APPROVED,
            'reviewed_at' => now(),
        ]);

        $fake = new MegaArchiveFakeClient;
        $this->app->instance(MegaArchiveClient::class, $fake);

        (new ArchiveVehicleCategoryChangeRequestAttachmentsJob((int) $req->id))->handle(
            app(VehicleCategoryChangeAttachmentArchiveService::class),
        );

        $attachment = $req->attachments()->firstOrFail()->fresh();
        $this->assertNotNull($attachment->archived_at);
        $this->assertSame('mega', $attachment->archive_provider);
        $this->assertNotNull($attachment->archive_path);
        $this->assertNull($attachment->archive_error);
        $this->assertSame(1, $fake->uploadCalls);
        $this->assertStringContainsString('vehicle-category-changes/approved/', $fake->lastUploadRelativeDirectory ?? '');
        $this->assertStringContainsString('attachment-'.$attachment->id, $fake->lastUploadTargetName ?? '');
    }

    public function test_job_deletes_local_file_only_after_successful_upload(): void
    {
        Storage::fake('local');
        Mail::fake();
        $fixtures = $this->seedFixtures();
        $this->actingAs($fixtures['user']);

        $req = $this->submitRequest($fixtures, [
            UploadedFile::fake()->create('doc.pdf', 10, 'application/pdf'),
        ]);
        $req->update([
            'status' => VehicleCategoryChangeRequest::STATUS_REJECTED,
            'reviewed_at' => now(),
        ]);

        $attachment = $req->attachments()->firstOrFail();
        $path = $attachment->path;
        Storage::disk('local')->assertExists($path);

        $fake = new MegaArchiveFakeClient;
        $this->app->instance(MegaArchiveClient::class, $fake);

        (new ArchiveVehicleCategoryChangeRequestAttachmentsJob((int) $req->id))->handle(
            app(VehicleCategoryChangeAttachmentArchiveService::class),
        );

        Storage::disk('local')->assertMissing($path);
        $attachment->refresh();
        $this->assertNotNull($attachment->local_deleted_at);
    }

    public function test_job_failure_keeps_local_file_and_stores_archive_error(): void
    {
        Storage::fake('local');
        Mail::fake();
        $fixtures = $this->seedFixtures();
        $this->actingAs($fixtures['user']);

        $req = $this->submitRequest($fixtures, [
            UploadedFile::fake()->create('doc.pdf', 10, 'application/pdf'),
        ]);
        $req->update([
            'status' => VehicleCategoryChangeRequest::STATUS_APPROVED,
            'reviewed_at' => now(),
        ]);

        $attachment = $req->attachments()->firstOrFail();
        $path = $attachment->path;

        $fake = new MegaArchiveFakeClient;
        $fake->uploadResultsQueue = [
            new MegaUploadResult(false, null, null, 'mega_down'),
        ];
        $this->app->instance(MegaArchiveClient::class, $fake);

        (new ArchiveVehicleCategoryChangeRequestAttachmentsJob((int) $req->id))->handle(
            app(VehicleCategoryChangeAttachmentArchiveService::class),
        );

        Storage::disk('local')->assertExists($path);
        $attachment->refresh();
        $this->assertNull($attachment->archived_at);
        $this->assertSame('mega_down', $attachment->archive_error);
        $this->assertNull($attachment->local_deleted_at);
    }

    public function test_job_is_idempotent(): void
    {
        Storage::fake('local');
        Mail::fake();
        $fixtures = $this->seedFixtures();
        $this->actingAs($fixtures['user']);

        $req = $this->submitRequest($fixtures, [
            UploadedFile::fake()->create('doc.pdf', 10, 'application/pdf'),
        ]);
        $req->update([
            'status' => VehicleCategoryChangeRequest::STATUS_APPROVED,
            'reviewed_at' => now(),
        ]);

        $fake = new MegaArchiveFakeClient;
        $this->app->instance(MegaArchiveClient::class, $fake);
        $service = app(VehicleCategoryChangeAttachmentArchiveService::class);

        (new ArchiveVehicleCategoryChangeRequestAttachmentsJob((int) $req->id))->handle($service);
        $this->assertSame(1, $fake->uploadCalls);

        (new ArchiveVehicleCategoryChangeRequestAttachmentsJob((int) $req->id))->handle($service);
        $this->assertSame(1, $fake->uploadCalls);
    }

    public function test_job_deletes_local_file_when_already_archived_but_local_still_exists(): void
    {
        Storage::fake('local');
        $fixtures = $this->seedFixtures();

        $req = VehicleCategoryChangeRequest::query()->create([
            'user_id' => $fixtures['user']->id,
            'old_vehicle_id' => $fixtures['old']->id,
            'license_plate' => 'KO333',
            'old_vehicle_type_id' => $fixtures['a']->id,
            'requested_vehicle_type_id' => $fixtures['b']->id,
            'status' => VehicleCategoryChangeRequest::STATUS_APPROVED,
            'document_original_name' => 'x.pdf',
            'document_path' => 'vehicle-category-change-requests/1/document',
            'document_mime_type' => 'application/pdf',
            'document_size_bytes' => 10,
            'locale' => 'cg',
            'reviewed_at' => now(),
        ]);

        $path = 'vehicle-category-change-requests/1/attachments/1/file';
        Storage::disk('local')->put($path, 'PDF');

        $attachment = VehicleCategoryChangeRequestAttachment::query()->create([
            'vehicle_category_change_request_id' => $req->id,
            'disk' => 'local',
            'path' => $path,
            'original_name' => 'x.pdf',
            'mime_type' => 'application/pdf',
            'size' => 10,
            'archived_at' => now(),
            'archive_provider' => 'mega',
            'archive_path' => 'bus.kotor/vehicle-category-changes/approved/2026/06/request-1/attachment-1-x.pdf',
        ]);

        $fake = new MegaArchiveFakeClient;
        $this->app->instance(MegaArchiveClient::class, $fake);

        (new ArchiveVehicleCategoryChangeRequestAttachmentsJob((int) $req->id))->handle(
            app(VehicleCategoryChangeAttachmentArchiveService::class),
        );

        Storage::disk('local')->assertMissing($path);
        $this->assertSame(0, $fake->uploadCalls);
        $attachment->refresh();
        $this->assertNotNull($attachment->local_deleted_at);
    }

    public function test_missing_local_file_sets_archive_error_without_failing_others(): void
    {
        Storage::fake('local');
        $fixtures = $this->seedFixtures();

        $req = VehicleCategoryChangeRequest::query()->create([
            'user_id' => $fixtures['user']->id,
            'old_vehicle_id' => $fixtures['old']->id,
            'license_plate' => 'KO333',
            'old_vehicle_type_id' => $fixtures['a']->id,
            'requested_vehicle_type_id' => $fixtures['b']->id,
            'status' => VehicleCategoryChangeRequest::STATUS_APPROVED,
            'document_original_name' => 'missing.pdf',
            'document_path' => 'vehicle-category-change-requests/missing',
            'document_mime_type' => 'application/pdf',
            'document_size_bytes' => 10,
            'locale' => 'cg',
            'reviewed_at' => now(),
        ]);

        $missing = VehicleCategoryChangeRequestAttachment::query()->create([
            'vehicle_category_change_request_id' => $req->id,
            'disk' => 'local',
            'path' => 'vehicle-category-change-requests/missing/file',
            'original_name' => 'missing.pdf',
            'mime_type' => 'application/pdf',
            'size' => 10,
        ]);

        $goodPath = 'vehicle-category-change-requests/good/file';
        Storage::disk('local')->put($goodPath, 'PDF');
        $good = VehicleCategoryChangeRequestAttachment::query()->create([
            'vehicle_category_change_request_id' => $req->id,
            'disk' => 'local',
            'path' => $goodPath,
            'original_name' => 'good.pdf',
            'mime_type' => 'application/pdf',
            'size' => 10,
        ]);

        $fake = new MegaArchiveFakeClient;
        $this->app->instance(MegaArchiveClient::class, $fake);

        (new ArchiveVehicleCategoryChangeRequestAttachmentsJob((int) $req->id))->handle(
            app(VehicleCategoryChangeAttachmentArchiveService::class),
        );

        $missing->refresh();
        $good->refresh();
        $this->assertSame(VehicleCategoryChangeAttachmentArchiveService::ERROR_LOCAL_FILE_MISSING, $missing->archive_error);
        $this->assertNotNull($good->archived_at);
        $this->assertSame(1, $fake->uploadCalls);
    }

    public function test_admin_detail_shows_archived_status(): void
    {
        Storage::fake('local');
        Mail::fake();
        $fixtures = $this->seedFixtures();
        $this->actingAs($fixtures['user']);

        $req = $this->submitRequest($fixtures, [
            UploadedFile::fake()->create('a.pdf', 10, 'application/pdf'),
        ]);
        $req->update([
            'status' => VehicleCategoryChangeRequest::STATUS_APPROVED,
            'reviewed_at' => now(),
        ]);

        $attachment = $req->attachments()->firstOrFail();
        $attachment->update([
            'archived_at' => now(),
            'archive_provider' => 'mega',
            'archive_path' => 'bus.kotor/vehicle-category-changes/approved/2026/06/request-'.$req->id.'/attachment-'.$attachment->id.'-a.pdf',
            'local_deleted_at' => now(),
        ]);
        Storage::disk('local')->delete($attachment->path);

        $admin = $this->seedAdmin();
        $html = $this->actingAs($admin, 'panel_admin')
            ->get(route('panel_admin.agencies.vehicle_category_change_requests.show', [
                'user' => $fixtures['user']->id,
                'request' => $req->id,
            ], false))
            ->assertOk()
            ->getContent();

        $this->assertStringContainsString('Arhivirano na MEGA', $html);
        $this->assertStringContainsString('vehicle-category-changes/approved/', $html);
    }

    public function test_admin_detail_does_not_show_broken_preview_for_archived_deleted_local(): void
    {
        Storage::fake('local');
        Mail::fake();
        $fixtures = $this->seedFixtures();
        $this->actingAs($fixtures['user']);

        $req = $this->submitRequest($fixtures, [
            UploadedFile::fake()->create('a.pdf', 10, 'application/pdf'),
        ]);
        $req->update([
            'status' => VehicleCategoryChangeRequest::STATUS_REJECTED,
            'reviewed_at' => now(),
        ]);

        $attachment = $req->attachments()->firstOrFail();
        $attachment->update([
            'archived_at' => now(),
            'archive_provider' => 'mega',
            'archive_path' => 'bus.kotor/vehicle-category-changes/rejected/2026/06/request-'.$req->id.'/attachment-'.$attachment->id.'-a.pdf',
            'local_deleted_at' => now(),
        ]);
        Storage::disk('local')->delete($attachment->path);

        $admin = $this->seedAdmin();
        $html = $this->actingAs($admin, 'panel_admin')
            ->get(route('panel_admin.agencies.vehicle_category_change_requests.show', [
                'user' => $fixtures['user']->id,
                'request' => $req->id,
            ], false))
            ->assertOk()
            ->getContent();

        $previewUrl = route('panel_admin.agencies.vehicle_category_change_requests.attachments.preview', [
            'user' => $fixtures['user']->id,
            'request' => $req->id,
            'attachment' => $attachment->id,
        ], false);

        $this->assertStringNotContainsString($previewUrl, $html);
    }

    public function test_pending_preview_still_works(): void
    {
        Storage::fake('local');
        Mail::fake();
        $fixtures = $this->seedFixtures();
        $this->actingAs($fixtures['user']);

        $req = $this->submitRequest($fixtures, [
            UploadedFile::fake()->create('a.pdf', 10, 'application/pdf'),
        ]);

        $attachment = $req->attachments()->firstOrFail();
        $admin = $this->seedAdmin();

        $html = $this->actingAs($admin, 'panel_admin')
            ->get(route('panel_admin.agencies.vehicle_category_change_requests.show', [
                'user' => $fixtures['user']->id,
                'request' => $req->id,
            ], false))
            ->assertOk()
            ->getContent();

        $this->assertStringContainsString('Lokalni dokument dostupan', $html);
        $this->assertStringContainsString('Preview', $html);

        $this->actingAs($admin, 'panel_admin')
            ->get(route('panel_admin.agencies.vehicle_category_change_requests.attachments.preview', [
                'user' => $fixtures['user']->id,
                'request' => $req->id,
                'attachment' => $attachment->id,
            ], false))
            ->assertOk();
    }
}
