<?php

namespace App\Services\Operational;

use App\Models\AdminAlert;
use App\Services\AdminPanel\AdminAlertService;
use App\Support\OperationalHeartbeatCache;
use Carbon\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

/**
 * Lightweight scheduler / queue-worker heartbeat + stale detection (cache only).
 */
final class BackgroundWatchdogService
{
    public const ALERT_TYPE_SCHEDULER_STALE = 'scheduler_heartbeat_stale';

    public const ALERT_TYPE_QUEUE_WORKER_STALE = 'queue_worker_heartbeat_stale';

    public const DEDUPE_SCHEDULER_STALE = 'scheduler_heartbeat_stale';

    public const DEDUPE_QUEUE_WORKER_STALE = 'queue_worker_heartbeat_stale';

    public function recordSchedulerRunStarted(): void
    {
        $now = now()->toIso8601String();
        Cache::put(OperationalHeartbeatCache::SCHEDULER_LAST_RUN_AT, $now, OperationalHeartbeatCache::ttl());

        Log::channel('payments')->info('scheduler_watchdog_heartbeat', [
            'phase' => 'started',
            'last_scheduler_run_at' => $now,
        ]);
    }

    public function recordSchedulerRunFinished(int $exitCode): void
    {
        $now = now()->toIso8601String();
        Cache::put(OperationalHeartbeatCache::SCHEDULER_LAST_RUN_AT, $now, OperationalHeartbeatCache::ttl());

        if ($exitCode === 0) {
            Cache::put(OperationalHeartbeatCache::SCHEDULER_LAST_OK_AT, $now, OperationalHeartbeatCache::ttl());
            Cache::forget(OperationalHeartbeatCache::SCHEDULER_LAST_ERROR);
            $this->resolveOpenAlert(self::ALERT_TYPE_SCHEDULER_STALE, self::DEDUPE_SCHEDULER_STALE);
        } else {
            $err = 'schedule:run exit code '.$exitCode;
            Cache::put(OperationalHeartbeatCache::SCHEDULER_LAST_ERROR, $err, OperationalHeartbeatCache::ttl());
        }

        Log::channel('payments')->info('scheduler_watchdog_heartbeat', [
            'phase' => 'finished',
            'exit_code' => $exitCode,
            'last_scheduler_run_at' => $now,
            'last_scheduler_ok_at' => $exitCode === 0 ? $now : Cache::get(OperationalHeartbeatCache::SCHEDULER_LAST_OK_AT),
        ]);
    }

    public function recordQueueWorkerStarted(): void
    {
        $now = now()->toIso8601String();
        Cache::put(OperationalHeartbeatCache::QUEUE_WORKER_LAST_RUN_AT, $now, OperationalHeartbeatCache::ttl());

        Log::channel('payments')->info('queue_worker_heartbeat', [
            'phase' => 'started',
            'last_queue_worker_run_at' => $now,
        ]);
    }

    public function recordQueueWorkerFinished(int $exitCode, ?string $error = null): void
    {
        $now = now()->toIso8601String();
        Cache::put(OperationalHeartbeatCache::QUEUE_WORKER_LAST_RUN_AT, $now, OperationalHeartbeatCache::ttl());

        if ($exitCode === 0 && ($error === null || $error === '')) {
            Cache::put(OperationalHeartbeatCache::QUEUE_WORKER_LAST_OK_AT, $now, OperationalHeartbeatCache::ttl());
            Cache::forget(OperationalHeartbeatCache::QUEUE_WORKER_LAST_ERROR);
            $this->resolveOpenAlert(self::ALERT_TYPE_QUEUE_WORKER_STALE, self::DEDUPE_QUEUE_WORKER_STALE);
        } elseif ($error !== null && $error !== '') {
            Cache::put(
                OperationalHeartbeatCache::QUEUE_WORKER_LAST_ERROR,
                \Illuminate\Support\Str::limit($error, 500),
                OperationalHeartbeatCache::ttl(),
            );
        }

        Log::channel('payments')->info('queue_worker_heartbeat', [
            'phase' => 'finished',
            'exit_code' => $exitCode,
            'last_queue_worker_run_at' => $now,
            'last_queue_worker_ok_at' => ($exitCode === 0 && ($error === null || $error === ''))
                ? $now
                : Cache::get(OperationalHeartbeatCache::QUEUE_WORKER_LAST_OK_AT),
            'error' => $error,
        ]);
    }

    public function evaluateStaleHeartbeats(?AdminAlertService $alerts = null): void
    {
        $alerts ??= app(AdminAlertService::class);
        $staleMinutes = $this->staleThresholdMinutes();

        $this->evaluateSchedulerStale($alerts, $staleMinutes);
        $this->evaluateQueueWorkerStale($alerts, $staleMinutes);
    }

    /**
     * @return array<string, mixed>
     */
    public function schedulerStatusSnapshot(): array
    {
        return $this->buildProcessStatus(
            OperationalHeartbeatCache::SCHEDULER_LAST_RUN_AT,
            OperationalHeartbeatCache::SCHEDULER_LAST_OK_AT,
            OperationalHeartbeatCache::SCHEDULER_LAST_ERROR,
        );
    }

    /**
     * @return array<string, mixed>
     */
    public function queueWorkerStatusSnapshot(): array
    {
        return $this->buildProcessStatus(
            OperationalHeartbeatCache::QUEUE_WORKER_LAST_RUN_AT,
            OperationalHeartbeatCache::QUEUE_WORKER_LAST_OK_AT,
            OperationalHeartbeatCache::QUEUE_WORKER_LAST_ERROR,
        );
    }

    public function staleThresholdMinutes(): int
    {
        return max(1, (int) config('queue.system_health.watchdog_stale_minutes', 5));
    }

    /**
     * @return array<string, mixed>
     */
    private function buildProcessStatus(string $runKey, string $okKey, string $errorKey): array
    {
        $runAt = $this->cacheIso($runKey);
        $okAt = $this->cacheIso($okKey);
        $error = Cache::get($errorKey);
        $error = is_string($error) && $error !== '' ? $error : null;

        $okAgeMin = $this->ageMinutes($okAt);
        $runAgeMin = $this->ageMinutes($runAt);
        $staleMinutes = $this->staleThresholdMinutes();

        $sectionStatus = 'neutral';
        $sectionLabel = 'Nepoznato';
        $isStale = false;

        if ($okAt === null && $runAt === null) {
            $sectionStatus = 'neutral';
            $sectionLabel = 'Nepoznato';
        } elseif ($okAt === null) {
            $sectionStatus = 'warn';
            $sectionLabel = 'Upozorenje';
            $isStale = true;
        } elseif ($okAgeMin !== null && $okAgeMin > $staleMinutes) {
            $sectionStatus = 'warn';
            $sectionLabel = 'Upozorenje';
            $isStale = true;
        } else {
            $sectionStatus = 'ok';
            $sectionLabel = 'OK';
        }

        return [
            'last_run_at' => $runAt,
            'last_ok_at' => $okAt,
            'last_error' => $error,
            'last_run_age_minutes' => $runAgeMin,
            'last_ok_age_minutes' => $okAgeMin,
            'stale_threshold_minutes' => $staleMinutes,
            'is_stale' => $isStale,
            'section_status' => $sectionStatus,
            'section_label' => $sectionLabel,
        ];
    }

    private function evaluateSchedulerStale(AdminAlertService $alerts, int $staleMinutes): void
    {
        $snapshot = $this->schedulerStatusSnapshot();

        if (! ($snapshot['is_stale'] ?? false)) {
            $this->resolveOpenAlert(self::ALERT_TYPE_SCHEDULER_STALE, self::DEDUPE_SCHEDULER_STALE);

            return;
        }

        $okAge = $snapshot['last_ok_age_minutes'];
        $runAge = $snapshot['last_run_age_minutes'];
        $ageMin = $okAge ?? $runAge ?? $staleMinutes + 1;

        Log::channel('payments')->warning('scheduler_watchdog_stale', [
            'last_scheduler_run_at' => $snapshot['last_run_at'],
            'last_scheduler_ok_at' => $snapshot['last_ok_at'],
            'age_minutes' => $ageMin,
            'stale_threshold_minutes' => $staleMinutes,
        ]);

        $alerts->createOnce(
            self::ALERT_TYPE_SCHEDULER_STALE,
            'Sistem: Laravel scheduler nije nedavno potvrdio heartbeat',
            implode("\n", [
                'Poslednji uspješan scheduler run nije u poslednjih ~'.$staleMinutes.' min.',
                'Poslednji run: '.($snapshot['last_run_at'] ?? '—'),
                'Poslednji OK: '.($snapshot['last_ok_at'] ?? '—'),
                'Starost (min): ~'.$ageMin,
                'Provjeriti Plesk cron za schedule-run.php (* * * * *).',
            ]),
            $ageMin > ($staleMinutes * 3) ? 'critical' : 'high',
            self::DEDUPE_SCHEDULER_STALE,
            [
                'last_run_at' => $snapshot['last_run_at'],
                'last_ok_at' => $snapshot['last_ok_at'],
                'age_minutes' => $ageMin,
                'stale_threshold_minutes' => $staleMinutes,
            ],
        );
    }

    private function evaluateQueueWorkerStale(AdminAlertService $alerts, int $staleMinutes): void
    {
        $snapshot = $this->queueWorkerStatusSnapshot();

        if (! ($snapshot['is_stale'] ?? false)) {
            $this->resolveOpenAlert(self::ALERT_TYPE_QUEUE_WORKER_STALE, self::DEDUPE_QUEUE_WORKER_STALE);

            return;
        }

        $okAge = $snapshot['last_ok_age_minutes'];
        $runAge = $snapshot['last_run_age_minutes'];
        $ageMin = $okAge ?? $runAge ?? $staleMinutes + 1;

        Log::channel('payments')->warning('queue_worker_stale', [
            'last_queue_worker_run_at' => $snapshot['last_run_at'],
            'last_queue_worker_ok_at' => $snapshot['last_ok_at'],
            'age_minutes' => $ageMin,
            'stale_threshold_minutes' => $staleMinutes,
        ]);

        $alerts->createOnce(
            self::ALERT_TYPE_QUEUE_WORKER_STALE,
            'Sistem: queue worker nije nedavno potvrdio heartbeat',
            implode("\n", [
                'Poslednji uspješan queue-worker.php / queue:work ciklus nije u poslednjih ~'.$staleMinutes.' min.',
                'Poslednji run: '.($snapshot['last_run_at'] ?? '—'),
                'Poslednji OK: '.($snapshot['last_ok_at'] ?? '—'),
                'Starost (min): ~'.$ageMin,
                'Provjeriti Plesk cron za queue-worker.php (* * * * *). Worker se ne restartuje automatski.',
            ]),
            $ageMin > ($staleMinutes * 3) ? 'critical' : 'high',
            self::DEDUPE_QUEUE_WORKER_STALE,
            [
                'last_run_at' => $snapshot['last_run_at'],
                'last_ok_at' => $snapshot['last_ok_at'],
                'age_minutes' => $ageMin,
                'stale_threshold_minutes' => $staleMinutes,
            ],
        );
    }

    private function resolveOpenAlert(string $type, string $dedupeKey): void
    {
        AdminAlert::query()
            ->where('type', $type)
            ->whereNull('removed_at')
            ->whereNot('status', AdminAlert::STATUS_DONE)
            ->where('payload_json->dedupe_key', $dedupeKey)
            ->update([
                'status' => AdminAlert::STATUS_DONE,
                'resolved_at' => now(),
            ]);
    }

    private function cacheIso(string $key): ?string
    {
        $v = Cache::get($key);

        return is_string($v) && $v !== '' ? $v : null;
    }

    private function ageMinutes(?string $iso): ?int
    {
        if ($iso === null || $iso === '') {
            return null;
        }

        try {
            return max(0, (int) Carbon::parse($iso)->diffInMinutes(now()));
        } catch (\Throwable) {
            return null;
        }
    }
}
