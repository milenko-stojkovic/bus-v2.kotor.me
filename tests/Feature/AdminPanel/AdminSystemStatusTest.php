<?php

namespace Tests\Feature\AdminPanel;

use App\Models\Admin;
use App\Models\ExternalFileArchive;
use App\Models\User;
use App\Support\OperationalHeartbeatCache;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

class AdminSystemStatusTest extends TestCase
{
    use RefreshDatabase;

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function makeAdmin(): Admin
    {
        return Admin::query()->create([
            'username' => 'status-admin',
            'email' => 'status-admin@test.local',
            'password' => bcrypt('x'),
            'control_access' => false,
            'admin_access' => true,
        ]);
    }

    public function test_admin_can_open_sistem_status_page(): void
    {
        $admin = $this->makeAdmin();

        $this->actingAs($admin, 'panel_admin')
            ->get(route('panel_admin.system-status', [], false))
            ->assertOk()
            ->assertSee('Sistem status', false);
    }

    public function test_guest_cannot_access_sistem_status(): void
    {
        $this->get(route('panel_admin.system-status', [], false))
            ->assertRedirect(route('panel_admin.login', [], false));
    }

    public function test_non_panel_admin_user_cannot_access_sistem_status(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)
            ->get(route('panel_admin.system-status', [], false))
            ->assertRedirect(route('panel_admin.login', [], false));
    }

    public function test_page_shows_queue_database_metrics(): void
    {
        config(['queue.default' => 'database']);

        $old = now()->subMinutes(10)->timestamp;
        DB::table('jobs')->insert([
            'queue' => 'default',
            'payload' => '{"t":1}',
            'attempts' => 0,
            'reserved_at' => null,
            'available_at' => $old,
            'created_at' => $old,
        ]);

        $admin = $this->makeAdmin();
        $html = $this->actingAs($admin, 'panel_admin')
            ->get(route('panel_admin.system-status', [], false))
            ->assertOk()
            ->getContent();

        $this->assertStringContainsString('database', $html);
        $this->assertStringContainsString('Pending', $html);
        $this->assertStringContainsString('Stale', $html);
        $this->assertStringContainsString('1', $html);
    }

    public function test_page_shows_scheduler_and_queue_worker_heartbeat_sections(): void
    {
        $fresh = now()->subMinutes(1)->toIso8601String();
        Cache::put(OperationalHeartbeatCache::SCHEDULER_LAST_OK_AT, $fresh, 600);
        Cache::put(OperationalHeartbeatCache::QUEUE_WORKER_LAST_OK_AT, $fresh, 600);

        $admin = $this->makeAdmin();
        $html = $this->actingAs($admin, 'panel_admin')
            ->get(route('panel_admin.system-status', [], false))
            ->assertOk()
            ->getContent();

        $this->assertStringContainsString('Scheduler (Laravel schedule:run)', $html);
        $this->assertStringContainsString('Queue worker (queue-worker.php)', $html);
    }

    public function test_page_shows_worker_down_hint_when_pending_jobs_and_stale_worker_heartbeat(): void
    {
        config(['queue.default' => 'database']);
        Carbon::setTestNow(Carbon::parse('2026-06-27 12:10:00', 'Europe/Podgorica'));

        Cache::put(
            OperationalHeartbeatCache::QUEUE_WORKER_LAST_OK_AT,
            now()->subMinutes(10)->toIso8601String(),
            600,
        );

        $old = now()->subMinutes(10)->timestamp;
        DB::table('jobs')->insert([
            'queue' => 'default',
            'payload' => '{"t":1}',
            'attempts' => 0,
            'reserved_at' => null,
            'available_at' => $old,
            'created_at' => $old,
        ]);

        $admin = $this->makeAdmin();
        $html = $this->actingAs($admin, 'panel_admin')
            ->get(route('panel_admin.system-status', [], false))
            ->assertOk()
            ->getContent();

        $this->assertStringContainsString('worker vjerovatno ne obrađuje', $html);
    }

    public function test_page_shows_heartbeat_cache_values(): void
    {
        Cache::put(OperationalHeartbeatCache::SYSTEM_HEALTH_LAST_RUN_AT, '2026-05-15T08:00:00+00:00', 600);
        Cache::put(OperationalHeartbeatCache::SYSTEM_HEALTH_LAST_OK_AT, '2026-05-15T08:01:00+00:00', 600);
        Cache::put(OperationalHeartbeatCache::MEGA_LAST_DIAGNOSE_AT, '2026-05-15T07:00:00+00:00', 600);
        Cache::put(OperationalHeartbeatCache::MEGA_LAST_DIAGNOSE_OK, true, 600);
        Cache::put(OperationalHeartbeatCache::ARCHIVE_PRIVATE_LAST_RUN_AT, '2026-05-15T06:00:00+00:00', 600);
        Cache::put(OperationalHeartbeatCache::ARCHIVE_PRIVATE_LAST_OK_AT, '2026-05-15T06:05:00+00:00', 600);
        Cache::put(
            OperationalHeartbeatCache::ARCHIVE_PRIVATE_LAST_SUMMARY,
            json_encode([
                'scanned' => 2,
                'archived' => 1,
                'failed' => 0,
                'skipped' => 1,
                'source' => 'all',
                'limit' => 5,
                'dry_run' => false,
                'require_mega_health' => false,
                'timestamp' => '2026-05-15T06:05:00+00:00',
            ], JSON_UNESCAPED_UNICODE),
            600,
        );

        $admin = $this->makeAdmin();
        $html = $this->actingAs($admin, 'panel_admin')
            ->get(route('panel_admin.system-status', [], false))
            ->assertOk()
            ->getContent();

        $this->assertStringContainsString('15.05.2026', $html);
        $this->assertStringContainsString('Scanned:', $html);
        $this->assertStringContainsString('Archived:', $html);
        $this->assertStringContainsString('Dry run:', $html);
        $this->assertStringContainsString('Require MEGA health:', $html);
        $this->assertStringContainsString('MEGA', $html);
    }

    public function test_page_shows_failed_archives_count_and_link(): void
    {
        ExternalFileArchive::query()->create([
            'source_table' => 'free_reservation_request_attachments',
            'source_id' => 10,
            'source_column' => 'stored_path',
            'context_type' => 'fzbr_attachment',
            'archive_provider' => ExternalFileArchive::PROVIDER_MEGA,
            'generated_file_name' => 'g1.pdf',
            'mega_node_id' => null,
            'mega_path' => null,
            'original_local_path' => 'a/f1.pdf',
            'archived_derivative' => false,
            'derivative_source_path' => null,
            'derivative_options' => null,
            'local_deleted_at' => null,
            'archived_at' => null,
            'status' => ExternalFileArchive::STATUS_FAILED,
            'error_message' => 'e1',
        ]);

        $admin = $this->makeAdmin();
        $failedUrl = route('panel_admin.archive.failed', [], false);

        $this->actingAs($admin, 'panel_admin')
            ->get(route('panel_admin.system-status', [], false))
            ->assertOk()
            ->assertSee('Neuspjelih u bazi', false)
            ->assertSee($failedUrl, false);
    }

    public function test_page_shows_failed_jobs_24h_count(): void
    {
        DB::table('failed_jobs')->insert([
            'uuid' => (string) Str::uuid(),
            'connection' => 'database',
            'queue' => 'default',
            'payload' => '{}',
            'exception' => 'test',
            'failed_at' => now()->subHours(3),
        ]);

        $admin = $this->makeAdmin();
        $this->actingAs($admin, 'panel_admin')
            ->get(route('panel_admin.system-status', [], false))
            ->assertOk()
            ->assertSee('failed_jobs', false)
            ->assertSee('1', false);
    }

    public function test_page_handles_missing_cache_values_gracefully(): void
    {
        Cache::flush();

        $admin = $this->makeAdmin();
        $html = $this->actingAs($admin, 'panel_admin')
            ->get(route('panel_admin.system-status', [], false))
            ->assertOk()
            ->getContent();

        $this->assertStringContainsString('Nema sačuvane posebne MEGA dijagnostike u kešu', $html);
        $this->assertStringContainsString('Ovo nije greška. Stranica ne pokreće živi MEGA test pri učitavanju.', $html);
        $this->assertStringContainsString('Još nije zabilježen nakon poslednjeg čišćenja keša ili deploy-a', $html);
        $this->assertStringContainsString('Dnevni health rollup', $html);
        $this->assertStringNotContainsString('nije još provjereno', $html);
        $this->assertStringNotContainsString('Nije provjereno', $html);
    }

    public function test_no_mega_diagnostic_cache_shows_non_alarming_explanatory_text(): void
    {
        Cache::flush();

        $admin = $this->makeAdmin();
        $html = $this->actingAs($admin, 'panel_admin')
            ->get(route('panel_admin.system-status', [], false))
            ->assertOk()
            ->getContent();

        $this->assertStringContainsString('Nema sačuvane posebne MEGA dijagnostike u kešu', $html);
        $this->assertStringContainsString('Provjerite sekciju Privatna arhiva za poslednji arhivski run', $html);
        $this->assertStringNotContainsString('Nije provjereno', $html);
    }

    public function test_private_archive_ok_still_displays_as_ok_and_hints_mega_section(): void
    {
        Cache::flush();
        Cache::put(OperationalHeartbeatCache::ARCHIVE_PRIVATE_LAST_RUN_AT, now()->subHour()->toIso8601String(), 600);
        Cache::put(OperationalHeartbeatCache::ARCHIVE_PRIVATE_LAST_OK_AT, now()->subHour()->toIso8601String(), 600);

        $admin = $this->makeAdmin();
        $html = $this->actingAs($admin, 'panel_admin')
            ->get(route('panel_admin.system-status', [], false))
            ->assertOk()
            ->getContent();

        $this->assertStringContainsString('Privatna arhiva (heartbeat + DB)', $html);
        $this->assertStringContainsString('Poslednji arhivski run je završen bez greške.', $html);
    }

    public function test_missing_daily_system_health_rollup_shows_not_yet_recorded_wording(): void
    {
        Cache::flush();

        $admin = $this->makeAdmin();
        $html = $this->actingAs($admin, 'panel_admin')
            ->get(route('panel_admin.system-status', [], false))
            ->assertOk()
            ->getContent();

        $this->assertStringContainsString('Dnevni health rollup', $html);
        $this->assertStringContainsString('Još nije zabilježen', $html);
        $this->assertStringContainsString('Ovo nije isto što i scheduler/queue heartbeat', $html);
        $this->assertStringNotContainsString('Sistemsko zdravlje (heartbeat)', $html);
    }

    public function test_scheduler_and_queue_fresh_heartbeat_still_show_ok(): void
    {
        Cache::flush();
        $fresh = now()->subMinutes(1)->toIso8601String();
        Cache::put(OperationalHeartbeatCache::SCHEDULER_LAST_RUN_AT, $fresh, 600);
        Cache::put(OperationalHeartbeatCache::SCHEDULER_LAST_OK_AT, $fresh, 600);
        Cache::put(OperationalHeartbeatCache::QUEUE_WORKER_LAST_RUN_AT, $fresh, 600);
        Cache::put(OperationalHeartbeatCache::QUEUE_WORKER_LAST_OK_AT, $fresh, 600);

        $admin = $this->makeAdmin();
        $html = $this->actingAs($admin, 'panel_admin')
            ->get(route('panel_admin.system-status', [], false))
            ->assertOk()
            ->getContent();

        $schedulerPos = strpos($html, 'Scheduler (Laravel schedule:run)');
        $queueWorkerPos = strpos($html, 'Queue worker (queue-worker.php)');
        $this->assertNotFalse($schedulerPos);
        $this->assertNotFalse($queueWorkerPos);
        $this->assertLessThan(
            strpos($html, 'Queue worker', $schedulerPos),
            strpos($html, 'OK', $schedulerPos),
        );
        $this->assertLessThan(
            strpos($html, 'Queue', $queueWorkerPos + 1),
            strpos($html, 'OK', $queueWorkerPos),
        );
    }

    public function test_stale_scheduler_heartbeat_still_shows_warning(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-06-27 12:10:00', 'Europe/Podgorica'));
        Cache::flush();
        Cache::put(
            OperationalHeartbeatCache::SCHEDULER_LAST_OK_AT,
            now()->subMinutes(10)->toIso8601String(),
            600,
        );

        $admin = $this->makeAdmin();
        $html = $this->actingAs($admin, 'panel_admin')
            ->get(route('panel_admin.system-status', [], false))
            ->assertOk()
            ->getContent();

        $schedulerPos = strpos($html, 'Scheduler (Laravel schedule:run)');
        $this->assertNotFalse($schedulerPos);
        $this->assertLessThan(
            strpos($html, 'Queue worker', $schedulerPos),
            strpos($html, 'Upozorenje', $schedulerPos),
        );
    }

    public function test_stale_queue_worker_heartbeat_still_shows_warning(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-06-27 12:10:00', 'Europe/Podgorica'));
        Cache::flush();
        Cache::put(
            OperationalHeartbeatCache::QUEUE_WORKER_LAST_OK_AT,
            now()->subMinutes(10)->toIso8601String(),
            600,
        );

        $admin = $this->makeAdmin();
        $html = $this->actingAs($admin, 'panel_admin')
            ->get(route('panel_admin.system-status', [], false))
            ->assertOk()
            ->getContent();

        $queueWorkerPos = strpos($html, 'Queue worker (queue-worker.php)');
        $this->assertNotFalse($queueWorkerPos);
        $this->assertLessThan(
            strpos($html, 'Queue', $queueWorkerPos + 20),
            strpos($html, 'Upozorenje', $queueWorkerPos),
        );
    }

    public function test_page_shows_critical_alerts_summary(): void
    {
        \App\Models\AdminAlert::query()->create([
            'type' => 'queue_worker_down',
            'status' => \App\Models\AdminAlert::STATUS_UNREAD,
            'title' => 'Kritičan test alert',
            'message' => 'Opis',
            'payload_json' => ['dedupe_key' => 'queue_worker_down', 'severity' => 'critical'],
        ]);

        $admin = $this->makeAdmin();
        $this->actingAs($admin, 'panel_admin')
            ->get(route('panel_admin.system-status', [], false))
            ->assertOk()
            ->assertSee('Kritičan test alert', false)
            ->assertSee('Upozorenja / Informacije', false);
    }
}
