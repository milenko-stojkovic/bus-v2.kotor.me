<?php

namespace Tests\Feature\Console;

use App\Models\AdminAlert;
use App\Services\AdminPanel\AdminSystemStatusService;
use App\Services\Operational\BackgroundWatchdogService;
use App\Support\OperationalHeartbeatCache;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Tests\TestCase;

final class BackgroundWatchdogTest extends TestCase
{
    use RefreshDatabase;

    private BackgroundWatchdogService $watchdog;

    protected function setUp(): void
    {
        parent::setUp();
        Cache::flush();
        $this->watchdog = app(BackgroundWatchdogService::class);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    public function test_scheduler_heartbeat_is_recorded_on_successful_run(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-06-27 12:00:00', 'Europe/Podgorica'));

        $this->watchdog->recordSchedulerRunStarted();
        $this->watchdog->recordSchedulerRunFinished(0);

        $expected = now()->toIso8601String();
        $this->assertSame($expected, Cache::get(OperationalHeartbeatCache::SCHEDULER_LAST_RUN_AT));
        $this->assertSame($expected, Cache::get(OperationalHeartbeatCache::SCHEDULER_LAST_OK_AT));
        $this->assertNull(Cache::get(OperationalHeartbeatCache::SCHEDULER_LAST_ERROR));
    }

    public function test_queue_worker_heartbeat_is_recorded_on_successful_run(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-06-27 12:00:00', 'Europe/Podgorica'));

        $this->watchdog->recordQueueWorkerStarted();
        $this->watchdog->recordQueueWorkerFinished(0);

        $expected = now()->toIso8601String();
        $this->assertSame($expected, Cache::get(OperationalHeartbeatCache::QUEUE_WORKER_LAST_RUN_AT));
        $this->assertSame($expected, Cache::get(OperationalHeartbeatCache::QUEUE_WORKER_LAST_OK_AT));
    }

    public function test_system_status_shows_ok_when_heartbeat_is_recent(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-06-27 12:03:00', 'Europe/Podgorica'));
        $fresh = now()->subMinutes(2)->toIso8601String();

        Cache::put(OperationalHeartbeatCache::SCHEDULER_LAST_RUN_AT, $fresh, 600);
        Cache::put(OperationalHeartbeatCache::SCHEDULER_LAST_OK_AT, $fresh, 600);
        Cache::put(OperationalHeartbeatCache::QUEUE_WORKER_LAST_RUN_AT, $fresh, 600);
        Cache::put(OperationalHeartbeatCache::QUEUE_WORKER_LAST_OK_AT, $fresh, 600);

        $snapshot = app(AdminSystemStatusService::class)->snapshot();

        $this->assertSame('ok', $snapshot['scheduler']['section_status']);
        $this->assertSame('OK', $snapshot['scheduler']['section_label']);
        $this->assertSame('ok', $snapshot['queue_worker']['section_status']);
        $this->assertFalse($snapshot['scheduler']['is_stale']);
    }

    public function test_system_status_shows_warning_when_scheduler_heartbeat_is_stale(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-06-27 12:10:00', 'Europe/Podgorica'));
        $stale = now()->subMinutes(10)->toIso8601String();

        Cache::put(OperationalHeartbeatCache::SCHEDULER_LAST_OK_AT, $stale, 600);

        $snapshot = $this->watchdog->schedulerStatusSnapshot();

        $this->assertTrue($snapshot['is_stale']);
        $this->assertSame('warn', $snapshot['section_status']);
        $this->assertSame('Upozorenje', $snapshot['section_label']);
    }

    public function test_system_status_shows_warning_when_queue_worker_heartbeat_is_stale(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-06-27 12:10:00', 'Europe/Podgorica'));
        $stale = now()->subMinutes(10)->toIso8601String();

        Cache::put(OperationalHeartbeatCache::QUEUE_WORKER_LAST_OK_AT, $stale, 600);

        $snapshot = $this->watchdog->queueWorkerStatusSnapshot();

        $this->assertTrue($snapshot['is_stale']);
        $this->assertSame('warn', $snapshot['section_status']);
    }

    public function test_open_admin_alert_is_created_for_stale_scheduler(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-06-27 12:10:00', 'Europe/Podgorica'));
        Cache::put(OperationalHeartbeatCache::SCHEDULER_LAST_OK_AT, now()->subMinutes(10)->toIso8601String(), 600);

        $this->watchdog->evaluateStaleHeartbeats();

        $this->assertSame(1, AdminAlert::query()->where('type', BackgroundWatchdogService::ALERT_TYPE_SCHEDULER_STALE)->count());
    }

    public function test_open_admin_alert_is_created_for_stale_queue_worker(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-06-27 12:10:00', 'Europe/Podgorica'));
        Cache::put(OperationalHeartbeatCache::QUEUE_WORKER_LAST_OK_AT, now()->subMinutes(10)->toIso8601String(), 600);

        $this->watchdog->evaluateStaleHeartbeats();

        $this->assertSame(1, AdminAlert::query()->where('type', BackgroundWatchdogService::ALERT_TYPE_QUEUE_WORKER_STALE)->count());
    }

    public function test_duplicate_alerts_are_not_created_on_repeated_stale_checks(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-06-27 12:10:00', 'Europe/Podgorica'));
        Cache::put(OperationalHeartbeatCache::SCHEDULER_LAST_OK_AT, now()->subMinutes(10)->toIso8601String(), 600);

        $this->watchdog->evaluateStaleHeartbeats();
        $this->watchdog->evaluateStaleHeartbeats();

        $this->assertSame(1, AdminAlert::query()->where('type', BackgroundWatchdogService::ALERT_TYPE_SCHEDULER_STALE)->count());
    }

    public function test_alert_is_resolved_when_scheduler_heartbeat_is_fresh_again(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-06-27 12:10:00', 'Europe/Podgorica'));
        Cache::put(OperationalHeartbeatCache::SCHEDULER_LAST_OK_AT, now()->subMinutes(10)->toIso8601String(), 600);
        $this->watchdog->evaluateStaleHeartbeats();

        Carbon::setTestNow(Carbon::parse('2026-06-27 12:11:00', 'Europe/Podgorica'));
        $this->watchdog->recordSchedulerRunFinished(0);
        $this->watchdog->evaluateStaleHeartbeats();

        $alert = AdminAlert::query()->where('type', BackgroundWatchdogService::ALERT_TYPE_SCHEDULER_STALE)->first();
        $this->assertNotNull($alert);
        $this->assertSame(AdminAlert::STATUS_DONE, $alert->status);
        $this->assertNotNull($alert->resolved_at);
    }

    public function test_scheduler_failure_records_error_without_ok_timestamp(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-06-27 12:00:00', 'Europe/Podgorica'));

        $this->watchdog->recordSchedulerRunFinished(1);

        $this->assertStringContainsString('exit code 1', (string) Cache::get(OperationalHeartbeatCache::SCHEDULER_LAST_ERROR));
        $this->assertNull(Cache::get(OperationalHeartbeatCache::SCHEDULER_LAST_OK_AT));
    }
}
