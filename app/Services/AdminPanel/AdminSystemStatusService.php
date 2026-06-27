<?php

namespace App\Services\AdminPanel;

use App\Console\Commands\AlertsSystemHealthCommand;
use App\Models\AdminAlert;
use App\Models\ExternalFileArchive;
use App\Models\PostFiscalizationData;
use App\Services\Operational\BackgroundWatchdogService;
use App\Support\OperationalHeartbeatCache;
use Carbon\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Read-only snapshot for admin “Sistem status” (DB + operational heartbeat cache only).
 */
final class AdminSystemStatusService
{
    public function snapshot(): array
    {
        $watchdog = app(BackgroundWatchdogService::class);

        return [
            'scheduler' => $watchdog->schedulerStatusSnapshot(),
            'queue_worker' => $watchdog->queueWorkerStatusSnapshot(),
            'queue' => $this->queueSection($watchdog),
            'mega' => $this->megaSection(),
            'archive' => $this->archiveSection(),
            'fiscalization' => $this->fiscalizationSection(),
            'failed_jobs' => $this->failedJobsSection(),
            'admin_alerts' => $this->criticalAlertsSection(),
            'system_health' => $this->systemHealthSection(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function queueSection(BackgroundWatchdogService $watchdog): array
    {
        $driver = (string) config('queue.default', 'sync');
        $isDatabase = $driver === 'database';
        $workerSnapshot = $watchdog->queueWorkerStatusSnapshot();
        $workerStale = (bool) ($workerSnapshot['is_stale'] ?? false);

        if (! $isDatabase) {
            return [
                'driver' => $driver,
                'is_database' => false,
                'pending_count' => null,
                'stale_count' => null,
                'stale_threshold_minutes' => null,
                'oldest_pending_age_seconds' => null,
                'stale_marker' => null,
                'worker_heartbeat_stale' => $workerStale,
                'worker_likely_down_hint' => null,
                'section_status' => $workerStale ? 'warn' : 'neutral',
                'section_label' => $workerStale ? 'Upozorenje' : 'Info',
            ];
        }

        if (! Schema::hasTable('jobs')) {
            return [
                'driver' => $driver,
                'is_database' => true,
                'pending_count' => null,
                'stale_count' => null,
                'stale_threshold_minutes' => null,
                'oldest_pending_age_seconds' => null,
                'stale_marker' => null,
                'worker_heartbeat_stale' => $workerStale,
                'worker_likely_down_hint' => null,
                'section_status' => 'warn',
                'section_label' => 'Upozorenje',
            ];
        }

        $staleMinutes = max(1, (int) config('queue.system_health.queue_stale_minutes', 5));
        $threshold = now()->getTimestamp() - ($staleMinutes * 60);

        $pendingCount = (int) DB::table('jobs')->whereNull('reserved_at')->count();

        $staleCount = (int) DB::table('jobs')
            ->whereNull('reserved_at')
            ->where('available_at', '<=', $threshold)
            ->count();

        $oldest = DB::table('jobs')
            ->whereNull('reserved_at')
            ->orderBy('available_at')
            ->first();

        $oldestAgeSec = $oldest !== null
            ? max(0, now()->getTimestamp() - (int) $oldest->available_at)
            : null;

        $markerRaw = Cache::get(AlertsSystemHealthCommand::CACHE_KEY_QUEUE_STALE_FIRST_SEEN);
        $markerRaw = is_array($markerRaw) ? $markerRaw : null;

        $staleMarker = null;
        if ($markerRaw !== null && isset($markerRaw['first_seen'])) {
            $staleMarker = [
                'first_seen_at' => Carbon::createFromTimestamp((int) $markerRaw['first_seen'])
                    ->timezone('Europe/Podgorica')
                    ->toIso8601String(),
                'pending_stale_count' => $markerRaw['pending_stale_count'] ?? null,
                'oldest_available_age_seconds' => $markerRaw['oldest_available_age_seconds'] ?? null,
            ];
        }

        $sectionStatus = 'ok';
        $sectionLabel = 'OK';
        $workerHint = null;

        if ($workerStale && ($pendingCount > 0 || $staleCount > 0)) {
            $workerHint = 'Ima poslova u redu, ali queue worker heartbeat nije svjež — worker vjerovatno ne obrađuje.';
        } elseif ($workerStale) {
            $workerHint = 'Queue worker heartbeat nije svjež — provjeriti queue-worker.php cron.';
        }

        if ($staleCount > 0) {
            if ($staleMarker !== null) {
                $sectionStatus = 'bad';
                $sectionLabel = 'Kritično';
            } else {
                $sectionStatus = 'warn';
                $sectionLabel = 'Upozorenje';
            }
        } elseif ($workerStale && $pendingCount > 0) {
            $sectionStatus = 'warn';
            $sectionLabel = 'Upozorenje';
        } elseif ($pendingCount > 0 && $oldestAgeSec !== null && $oldestAgeSec >= $staleMinutes * 60) {
            $sectionStatus = 'warn';
            $sectionLabel = 'Upozorenje';
        } elseif ($workerStale) {
            $sectionStatus = 'warn';
            $sectionLabel = 'Upozorenje';
        }

        return [
            'driver' => $driver,
            'is_database' => true,
            'pending_count' => $pendingCount,
            'stale_count' => $staleCount,
            'stale_threshold_minutes' => $staleMinutes,
            'oldest_pending_age_seconds' => $oldestAgeSec,
            'stale_marker' => $staleMarker,
            'worker_heartbeat_stale' => $workerStale,
            'worker_likely_down_hint' => $workerHint,
            'section_status' => $sectionStatus,
            'section_label' => $sectionLabel,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function megaSection(): array
    {
        $at = Cache::get(OperationalHeartbeatCache::MEGA_LAST_DIAGNOSE_AT);
        $ok = Cache::get(OperationalHeartbeatCache::MEGA_LAST_DIAGNOSE_OK);
        $err = Cache::get(OperationalHeartbeatCache::MEGA_LAST_DIAGNOSE_ERROR);
        $err = is_string($err) && $err !== '' ? $err : null;

        $neverChecked = $at === null || $at === '';
        $sectionStatus = 'neutral';
        $sectionLabel = 'Nepoznato';

        if ($neverChecked) {
            $sectionStatus = 'warn';
            $sectionLabel = 'Nije provjereno';
        } elseif ($ok === true) {
            $sectionStatus = 'ok';
            $sectionLabel = 'OK';
        } elseif ($ok === false) {
            $sectionStatus = 'bad';
            $sectionLabel = 'Problem';
        }

        return [
            'last_diagnose_at' => is_string($at) ? $at : null,
            'last_diagnose_ok' => is_bool($ok) ? $ok : null,
            'last_diagnose_error' => $err,
            'never_checked' => $neverChecked,
            'section_status' => $sectionStatus,
            'section_label' => $sectionLabel,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function archiveSection(): array
    {
        $failed = ExternalFileArchive::query()
            ->where('status', ExternalFileArchive::STATUS_FAILED)
            ->count();

        $rawSummary = Cache::get(OperationalHeartbeatCache::ARCHIVE_PRIVATE_LAST_SUMMARY);
        $summary = null;
        if (is_string($rawSummary) && $rawSummary !== '') {
            $decoded = json_decode($rawSummary, true);
            $summary = is_array($decoded) ? $decoded : ['raw' => $rawSummary];
        }

        $sectionStatus = $failed > 0 ? 'bad' : 'ok';
        $sectionLabel = $failed > 0 ? 'Neuspjeli zapisi' : 'OK';

        return [
            'last_run_at' => $this->cacheStringOrNull(OperationalHeartbeatCache::ARCHIVE_PRIVATE_LAST_RUN_AT),
            'last_ok_at' => $this->cacheStringOrNull(OperationalHeartbeatCache::ARCHIVE_PRIVATE_LAST_OK_AT),
            'last_summary' => $summary,
            'failed_archives_count' => $failed,
            'section_status' => $sectionStatus,
            'section_label' => $sectionLabel,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function fiscalizationSection(): array
    {
        $count = 0;
        if (Schema::hasTable('post_fiscalization_data')) {
            $count = (int) PostFiscalizationData::query()
                ->unresolved()
                ->where('created_at', '<=', now()->subHours(2))
                ->count();
        }

        $sectionStatus = $count > 0 ? 'bad' : 'ok';
        $sectionLabel = $count > 0 ? 'Potrebna pažnja' : 'OK';

        return [
            'unresolved_over_2h' => $count,
            'section_status' => $sectionStatus,
            'section_label' => $sectionLabel,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function failedJobsSection(): array
    {
        $count = 0;
        if (Schema::hasTable('failed_jobs')) {
            $count = (int) DB::table('failed_jobs')
                ->where('failed_at', '>=', now()->subDay())
                ->count();
        }

        $sectionStatus = $count > 0 ? 'warn' : 'ok';
        $sectionLabel = $count > 0 ? 'Ima neuspješnih' : 'OK';

        return [
            'failed_last_24h' => $count,
            'section_status' => $sectionStatus,
            'section_label' => $sectionLabel,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function criticalAlertsSection(): array
    {
        $base = AdminAlert::query()
            ->whereNull('removed_at')
            ->whereNot('status', AdminAlert::STATUS_DONE)
            ->where('payload_json->severity', 'critical');

        $count = (clone $base)->count();
        $latest = (clone $base)
            ->orderByDesc('created_at')
            ->limit(5)
            ->get(['title', 'created_at']);

        $sectionStatus = $count > 0 ? 'bad' : 'ok';
        $sectionLabel = $count > 0 ? 'Otvoreno' : 'Nema';

        return [
            'open_critical_count' => $count,
            'latest_open_critical' => $latest,
            'section_status' => $sectionStatus,
            'section_label' => $sectionLabel,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function systemHealthSection(): array
    {
        $runAt = $this->cacheStringOrNull(OperationalHeartbeatCache::SYSTEM_HEALTH_LAST_RUN_AT);
        $okAt = $this->cacheStringOrNull(OperationalHeartbeatCache::SYSTEM_HEALTH_LAST_OK_AT);

        $sectionStatus = 'neutral';
        $sectionLabel = 'Nepoznato';
        if ($runAt === null && $okAt === null) {
            $sectionStatus = 'neutral';
            $sectionLabel = 'Nepoznato';
        } elseif ($okAt !== null) {
            $sectionStatus = 'ok';
            $sectionLabel = 'OK';
        } elseif ($runAt !== null) {
            $sectionStatus = 'warn';
            $sectionLabel = 'Čeka završetak?';
        }

        return [
            'last_run_at' => $runAt,
            'last_ok_at' => $okAt,
            'section_status' => $sectionStatus,
            'section_label' => $sectionLabel,
            'note' => 'Dnevni rollup komande alerts:system-health (07:30), ne zamjenjuje scheduler/worker heartbeat iznad.',
        ];
    }

    private function cacheStringOrNull(string $key): ?string
    {
        $v = Cache::get($key);

        return is_string($v) && $v !== '' ? $v : null;
    }
}
