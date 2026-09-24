<?php

namespace Tests\Feature\ExternalArchive;

use App\Contracts\MegaArchiveClient;
use App\Models\Admin;
use App\Models\ExternalFileArchive;
use App\Services\ExternalArchive\ExternalFileArchiveService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Storage;
use Tests\Support\MegaArchiveFakeClient;
use Tests\TestCase;

/**
 * Failed → superseded when the same logical source later has an uploaded archive.
 */
final class ExternalArchiveSupersedeTest extends TestCase
{
    use RefreshDatabase;

    private function makeFailedRow(
        string $table,
        int $sourceId,
        ?string $column,
        string $path,
        string $genName,
        string $error = 'prev_fail',
    ): ExternalFileArchive {
        return ExternalFileArchive::query()->create([
            'source_table' => $table,
            'source_id' => $sourceId,
            'source_column' => $column,
            'context_type' => 'fzbr_attachment',
            'archive_provider' => ExternalFileArchive::PROVIDER_MEGA,
            'generated_file_name' => $genName,
            'mega_node_id' => null,
            'mega_path' => null,
            'original_local_path' => $path,
            'archived_derivative' => false,
            'derivative_source_path' => null,
            'derivative_options' => null,
            'local_deleted_at' => null,
            'archived_at' => null,
            'status' => ExternalFileArchive::STATUS_FAILED,
            'error_message' => $error,
        ]);
    }

    public function test_a_failed_attempt_alone_remains_failed_and_is_counted(): void
    {
        Storage::fake('local');
        $fake = new MegaArchiveFakeClient;
        $fake->uploadShouldFail = true;
        $this->app->instance(MegaArchiveClient::class, $fake);

        $path = 'free-reservation-requests/1/a.pdf';
        Storage::disk('local')->put($path, '%PDF');

        $svc = $this->app->make(ExternalFileArchiveService::class);
        $row = $svc->archiveLocalPrivateFile(
            'free_reservation_request_attachments',
            1,
            'stored_path',
            $path,
            'fzbr_attachment',
        );

        $this->assertSame(ExternalFileArchive::STATUS_FAILED, $row->status);
        $this->assertTrue(Storage::disk('local')->exists($path));
        $this->assertSame(
            1,
            ExternalFileArchive::query()->where('status', ExternalFileArchive::STATUS_FAILED)->count(),
        );
    }

    public function test_b_failed_then_later_success_supersedes_old_failure(): void
    {
        Storage::fake('local');
        $path = 'free-reservation-requests/35/doc.pdf';
        Storage::disk('local')->put($path, '%PDF-1.4');

        $old = $this->makeFailedRow(
            'free_reservation_request_attachments',
            35,
            'stored_path',
            $path,
            'fzbr_attachment__free_reservation_request_attachments_35__stored_path__old-uuid.pdf',
            'MEGA process failed: {"ok":false,"error":"fetch failed"}',
        );

        $fake = new MegaArchiveFakeClient;
        $this->app->instance(MegaArchiveClient::class, $fake);

        $svc = $this->app->make(ExternalFileArchiveService::class);
        $uploaded = $svc->archiveLocalPrivateFile(
            'free_reservation_request_attachments',
            35,
            'stored_path',
            $path,
            'fzbr_attachment',
        );

        $this->assertSame(ExternalFileArchive::STATUS_UPLOADED, $uploaded->status);
        $this->assertFalse(Storage::disk('local')->exists($path));
        $this->assertNotNull($uploaded->local_deleted_at);

        $old->refresh();
        $this->assertSame(ExternalFileArchive::STATUS_SUPERSEDED, $old->status);
        $this->assertSame(
            'MEGA process failed: {"ok":false,"error":"fetch failed"}',
            $old->error_message,
        );
        $this->assertSame(
            'fzbr_attachment__free_reservation_request_attachments_35__stored_path__old-uuid.pdf',
            $old->generated_file_name,
        );
        $this->assertSame(
            0,
            ExternalFileArchive::query()->where('status', ExternalFileArchive::STATUS_FAILED)->count(),
        );
    }

    public function test_c_multiple_failed_then_one_success_supersedes_all(): void
    {
        Storage::fake('local');
        $path = 'free-reservation-requests/10/x.pdf';
        Storage::disk('local')->put($path, 'x');

        $f1 = $this->makeFailedRow('free_reservation_request_attachments', 10, 'stored_path', $path, 'gen-a.pdf', 'e1');
        $f2 = $this->makeFailedRow('free_reservation_request_attachments', 10, 'stored_path', $path, 'gen-b.pdf', 'e2');

        $fake = new MegaArchiveFakeClient;
        $this->app->instance(MegaArchiveClient::class, $fake);

        $uploaded = $this->app->make(ExternalFileArchiveService::class)->archiveLocalPrivateFile(
            'free_reservation_request_attachments',
            10,
            'stored_path',
            $path,
            'fzbr_attachment',
        );

        $this->assertSame(ExternalFileArchive::STATUS_UPLOADED, $uploaded->status);
        $this->assertSame(ExternalFileArchive::STATUS_SUPERSEDED, $f1->fresh()->status);
        $this->assertSame(ExternalFileArchive::STATUS_SUPERSEDED, $f2->fresh()->status);
        $this->assertSame('e1', $f1->fresh()->error_message);
        $this->assertSame('e2', $f2->fresh()->error_message);
    }

    public function test_d_different_source_id_not_superseded(): void
    {
        Storage::fake('local');
        $pathA = 'free-reservation-requests/1/a.pdf';
        $pathB = 'free-reservation-requests/2/b.pdf';
        Storage::disk('local')->put($pathA, 'a');
        Storage::disk('local')->put($pathB, 'b');

        $otherFail = $this->makeFailedRow(
            'free_reservation_request_attachments',
            2,
            'stored_path',
            $pathB,
            'other-fail.pdf',
        );

        $fake = new MegaArchiveFakeClient;
        $this->app->instance(MegaArchiveClient::class, $fake);

        $this->app->make(ExternalFileArchiveService::class)->archiveLocalPrivateFile(
            'free_reservation_request_attachments',
            1,
            'stored_path',
            $pathA,
            'fzbr_attachment',
        );

        $this->assertSame(ExternalFileArchive::STATUS_FAILED, $otherFail->fresh()->status);
    }

    public function test_e_different_source_column_not_superseded(): void
    {
        Storage::fake('local');
        $path = 'limo_incidents/1/plate.jpg';
        Storage::disk('local')->put($path, 'x');

        $otherCol = $this->makeFailedRow('limo_incidents', 1, 'branding_photo_path', $path, 'branding-fail.jpg');

        $fake = new MegaArchiveFakeClient;
        $this->app->instance(MegaArchiveClient::class, $fake);

        $this->app->make(ExternalFileArchiveService::class)->archiveLocalPrivateFile(
            'limo_incidents',
            1,
            'plate_photo_path',
            $path,
            'limo_incident_plate',
        );

        $this->assertSame(ExternalFileArchive::STATUS_FAILED, $otherCol->fresh()->status);
    }

    public function test_f_uploaded_sibling_never_becomes_superseded(): void
    {
        Storage::fake('local');
        $path = 'x/y.pdf';
        Storage::disk('local')->put($path, 'x');

        $existingUploaded = ExternalFileArchive::query()->create([
            'source_table' => 'free_reservation_request_attachments',
            'source_id' => 99,
            'source_column' => 'stored_path',
            'context_type' => 'fzbr_attachment',
            'archive_provider' => ExternalFileArchive::PROVIDER_MEGA,
            'generated_file_name' => 'already-up.pdf',
            'mega_node_id' => 'n1',
            'mega_path' => 'bus.kotor/already-up.pdf',
            'original_local_path' => $path,
            'archived_derivative' => false,
            'derivative_source_path' => null,
            'derivative_options' => null,
            'local_deleted_at' => now(),
            'archived_at' => now(),
            'status' => ExternalFileArchive::STATUS_UPLOADED,
            'error_message' => null,
        ]);

        $this->makeFailedRow(
            'free_reservation_request_attachments',
            99,
            'stored_path',
            $path,
            'old-fail.pdf',
        );

        // Simulate a new success for same identity (local must exist for archiveLocalPrivateFile).
        Storage::disk('local')->put($path, 'x');
        // Early return: existing uploaded — supersede via reconcile helper instead.
        $count = $this->app->make(ExternalFileArchiveService::class)
            ->supersedeFailedSiblingsForSource(
                'free_reservation_request_attachments',
                99,
                'stored_path',
                (int) $existingUploaded->id,
            );

        $this->assertSame(1, $count);
        $this->assertSame(ExternalFileArchive::STATUS_UPLOADED, $existingUploaded->fresh()->status);
    }

    public function test_g_pending_sibling_remains_pending(): void
    {
        Storage::fake('local');
        $path = 'p/q.pdf';
        Storage::disk('local')->put($path, 'x');

        ExternalFileArchive::query()->create([
            'source_table' => 'free_reservation_request_attachments',
            'source_id' => 50,
            'source_column' => 'stored_path',
            'context_type' => 'fzbr_attachment',
            'archive_provider' => ExternalFileArchive::PROVIDER_MEGA,
            'generated_file_name' => 'pending-row.pdf',
            'mega_node_id' => null,
            'mega_path' => null,
            'original_local_path' => $path,
            'archived_derivative' => false,
            'derivative_source_path' => null,
            'derivative_options' => null,
            'local_deleted_at' => null,
            'archived_at' => null,
            'status' => ExternalFileArchive::STATUS_PENDING,
            'error_message' => null,
        ]);

        $this->makeFailedRow(
            'free_reservation_request_attachments',
            50,
            'stored_path',
            $path,
            'fail-beside-pending.pdf',
        );

        $fake = new MegaArchiveFakeClient;
        $this->app->instance(MegaArchiveClient::class, $fake);

        $this->app->make(ExternalFileArchiveService::class)->archiveLocalPrivateFile(
            'free_reservation_request_attachments',
            50,
            'stored_path',
            $path,
            'fzbr_attachment',
        );

        $this->assertSame(
            1,
            ExternalFileArchive::query()->where('status', ExternalFileArchive::STATUS_PENDING)->count(),
        );
        $this->assertSame(
            1,
            ExternalFileArchive::query()->where('status', ExternalFileArchive::STATUS_SUPERSEDED)->count(),
        );
    }

    public function test_h_superseded_row_cannot_be_retried(): void
    {
        Storage::fake('local');
        $path = 'r/s.pdf';
        Storage::disk('local')->put($path, '%PDF');

        $row = ExternalFileArchive::query()->create([
            'source_table' => 'free_reservation_request_attachments',
            'source_id' => 11,
            'source_column' => 'stored_path',
            'context_type' => 'fzbr_attachment',
            'archive_provider' => ExternalFileArchive::PROVIDER_MEGA,
            'generated_file_name' => 'superseded.pdf',
            'mega_node_id' => null,
            'mega_path' => null,
            'original_local_path' => $path,
            'archived_derivative' => false,
            'derivative_source_path' => null,
            'derivative_options' => null,
            'local_deleted_at' => null,
            'archived_at' => null,
            'status' => ExternalFileArchive::STATUS_SUPERSEDED,
            'error_message' => 'old',
        ]);

        $admin = Admin::query()->create([
            'username' => 'sup-admin',
            'email' => 'sup-admin@test.local',
            'password' => bcrypt('x'),
            'control_access' => false,
            'admin_access' => true,
        ]);

        $this->actingAs($admin, 'panel_admin')
            ->post(route('panel_admin.archive.failed.retry', $row, false))
            ->assertNotFound();

        $this->assertSame(ExternalFileArchive::STATUS_SUPERSEDED, $row->fresh()->status);

        $this->expectException(\InvalidArgumentException::class);
        $this->app->make(ExternalFileArchiveService::class)->retryFailedArchive($row->fresh());
    }

    public function test_i_reconcile_command_supersedes_preexisting_failed_with_uploaded_sibling(): void
    {
        Storage::fake('local');
        $path = 'prod/35.pdf';

        $failed = $this->makeFailedRow(
            'free_reservation_request_attachments',
            35,
            'stored_path',
            $path,
            'failed-34.pdf',
            'fetch failed',
        );

        ExternalFileArchive::query()->create([
            'source_table' => 'free_reservation_request_attachments',
            'source_id' => 35,
            'source_column' => 'stored_path',
            'context_type' => 'fzbr_attachment',
            'archive_provider' => ExternalFileArchive::PROVIDER_MEGA,
            'generated_file_name' => 'uploaded-35.pdf',
            'mega_node_id' => 'n',
            'mega_path' => 'bus.kotor/uploaded-35.pdf',
            'original_local_path' => $path,
            'archived_derivative' => false,
            'derivative_source_path' => null,
            'derivative_options' => null,
            'local_deleted_at' => now(),
            'archived_at' => now(),
            'status' => ExternalFileArchive::STATUS_UPLOADED,
            'error_message' => null,
        ]);

        Artisan::call('files:reconcile-external-archives');
        $this->assertSame(ExternalFileArchive::STATUS_SUPERSEDED, $failed->fresh()->status);
        $this->assertSame('fetch failed', $failed->fresh()->error_message);
        $this->assertSame(
            0,
            ExternalFileArchive::query()->where('status', ExternalFileArchive::STATUS_FAILED)->count(),
        );
    }

    public function test_j_reconcile_leaves_failed_without_uploaded_sibling(): void
    {
        $failed = $this->makeFailedRow(
            'free_reservation_request_attachments',
            77,
            'stored_path',
            'missing.pdf',
            'lonely-fail.pdf',
        );

        Artisan::call('files:reconcile-external-archives');
        $this->assertSame(ExternalFileArchive::STATUS_FAILED, $failed->fresh()->status);
    }

    public function test_k_reconcile_does_not_cross_source_column_or_id(): void
    {
        ExternalFileArchive::query()->create([
            'source_table' => 'free_reservation_request_attachments',
            'source_id' => 1,
            'source_column' => 'stored_path',
            'context_type' => 'fzbr_attachment',
            'archive_provider' => ExternalFileArchive::PROVIDER_MEGA,
            'generated_file_name' => 'up-1.pdf',
            'mega_node_id' => 'n',
            'mega_path' => 'bus.kotor/up-1.pdf',
            'original_local_path' => 'a.pdf',
            'archived_derivative' => false,
            'derivative_source_path' => null,
            'derivative_options' => null,
            'local_deleted_at' => now(),
            'archived_at' => now(),
            'status' => ExternalFileArchive::STATUS_UPLOADED,
            'error_message' => null,
        ]);

        $otherId = $this->makeFailedRow(
            'free_reservation_request_attachments',
            2,
            'stored_path',
            'b.pdf',
            'fail-id-2.pdf',
        );
        $otherCol = $this->makeFailedRow(
            'limo_incidents',
            1,
            'branding_photo_path',
            'c.jpg',
            'fail-col.pdf',
        );

        Artisan::call('files:reconcile-external-archives');
        $this->assertSame(ExternalFileArchive::STATUS_FAILED, $otherId->fresh()->status);
        $this->assertSame(ExternalFileArchive::STATUS_FAILED, $otherCol->fresh()->status);
    }

    public function test_l_reconcile_command_is_idempotent(): void
    {
        Storage::fake('local');
        $path = 'idemp.pdf';
        $failed = $this->makeFailedRow(
            'free_reservation_request_attachments',
            3,
            'stored_path',
            $path,
            'idemp-fail.pdf',
        );
        ExternalFileArchive::query()->create([
            'source_table' => 'free_reservation_request_attachments',
            'source_id' => 3,
            'source_column' => 'stored_path',
            'context_type' => 'fzbr_attachment',
            'archive_provider' => ExternalFileArchive::PROVIDER_MEGA,
            'generated_file_name' => 'idemp-up.pdf',
            'mega_node_id' => 'n',
            'mega_path' => 'bus.kotor/idemp-up.pdf',
            'original_local_path' => $path,
            'archived_derivative' => false,
            'derivative_source_path' => null,
            'derivative_options' => null,
            'local_deleted_at' => now(),
            'archived_at' => now(),
            'status' => ExternalFileArchive::STATUS_UPLOADED,
            'error_message' => null,
        ]);

        Artisan::call('files:reconcile-external-archives');
        Artisan::call('files:reconcile-external-archives');
        $this->assertSame(ExternalFileArchive::STATUS_SUPERSEDED, $failed->fresh()->status);
        $this->assertSame(
            1,
            ExternalFileArchive::query()->where('status', ExternalFileArchive::STATUS_SUPERSEDED)->count(),
        );
    }

    public function test_m_dry_run_reports_without_writes(): void
    {
        $failed = $this->makeFailedRow(
            'free_reservation_request_attachments',
            4,
            'stored_path',
            'dry.pdf',
            'dry-fail.pdf',
        );
        ExternalFileArchive::query()->create([
            'source_table' => 'free_reservation_request_attachments',
            'source_id' => 4,
            'source_column' => 'stored_path',
            'context_type' => 'fzbr_attachment',
            'archive_provider' => ExternalFileArchive::PROVIDER_MEGA,
            'generated_file_name' => 'dry-up.pdf',
            'mega_node_id' => 'n',
            'mega_path' => 'bus.kotor/dry-up.pdf',
            'original_local_path' => 'dry.pdf',
            'archived_derivative' => false,
            'derivative_source_path' => null,
            'derivative_options' => null,
            'local_deleted_at' => now(),
            'archived_at' => now(),
            'status' => ExternalFileArchive::STATUS_UPLOADED,
            'error_message' => null,
        ]);

        Artisan::call('files:reconcile-external-archives', ['--dry-run' => true]);
        $out = Artisan::output();
        $this->assertStringContainsString('DRY RUN', $out);
        $this->assertStringContainsString('would become superseded: 1', $out);
        $this->assertSame(ExternalFileArchive::STATUS_FAILED, $failed->fresh()->status);
    }

    public function test_n_system_health_still_counts_genuine_failed(): void
    {
        $this->makeFailedRow(
            'free_reservation_request_attachments',
            88,
            'stored_path',
            'alone.pdf',
            'alone-fail.pdf',
        );

        $count = ExternalFileArchive::query()
            ->where('status', ExternalFileArchive::STATUS_FAILED)
            ->count();
        $this->assertSame(1, $count);
    }

    public function test_o_production_style_case_after_reconcile_no_active_failed(): void
    {
        $failed34 = $this->makeFailedRow(
            'free_reservation_request_attachments',
            35,
            'stored_path',
            'free-reservation-requests/35/16fa10aa.pdf',
            'fzbr_attachment__free_reservation_request_attachments_35__stored_path__4d0cba32.pdf',
            'MEGA process failed: {"ok":false,"error":"fetch failed"}',
        );

        ExternalFileArchive::query()->create([
            'source_table' => 'free_reservation_request_attachments',
            'source_id' => 35,
            'source_column' => 'stored_path',
            'context_type' => 'fzbr_attachment',
            'archive_provider' => ExternalFileArchive::PROVIDER_MEGA,
            'generated_file_name' => 'fzbr_attachment__free_reservation_request_attachments_35__stored_path__later.pdf',
            'mega_node_id' => 'node',
            'mega_path' => 'bus.kotor/later.pdf',
            'original_local_path' => 'free-reservation-requests/35/16fa10aa.pdf',
            'archived_derivative' => false,
            'derivative_source_path' => null,
            'derivative_options' => null,
            'local_deleted_at' => now(),
            'archived_at' => now(),
            'status' => ExternalFileArchive::STATUS_UPLOADED,
            'error_message' => null,
        ]);

        Artisan::call('files:reconcile-external-archives');

        $this->assertSame(ExternalFileArchive::STATUS_SUPERSEDED, $failed34->fresh()->status);
        $this->assertSame(
            0,
            ExternalFileArchive::query()->where('status', ExternalFileArchive::STATUS_FAILED)->count(),
        );

        $admin = Admin::query()->create([
            'username' => 'fail-page',
            'email' => 'fail-page@test.local',
            'password' => bcrypt('x'),
            'control_access' => false,
            'admin_access' => true,
        ]);
        $this->actingAs($admin, 'panel_admin')
            ->get(route('panel_admin.archive.failed', [], false))
            ->assertOk()
            ->assertSee('Nema neuspjelih arhiva', false)
            ->assertDontSee('4d0cba32', false);
    }

    public function test_supersede_failure_does_not_undo_uploaded_status(): void
    {
        Storage::fake('local');
        $path = 'keep-uploaded.pdf';
        Storage::disk('local')->put($path, 'x');
        $this->makeFailedRow(
            'free_reservation_request_attachments',
            60,
            'stored_path',
            $path,
            'will-try-supersede.pdf',
        );

        $fake = new MegaArchiveFakeClient;
        $this->app->instance(MegaArchiveClient::class, $fake);

        $svc = $this->app->make(ExternalFileArchiveService::class);
        $uploaded = $svc->archiveLocalPrivateFile(
            'free_reservation_request_attachments',
            60,
            'stored_path',
            $path,
            'fzbr_attachment',
        );

        $this->assertSame(ExternalFileArchive::STATUS_UPLOADED, $uploaded->status);
    }
}
