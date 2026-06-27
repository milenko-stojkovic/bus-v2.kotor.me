<?php

namespace App\Console\Commands;

use App\Models\AdminAlert;
use App\Models\ExternalFileArchive;
use App\Models\PostFiscalizationData;
use App\Services\AdminPanel\AdminAlertService;
use App\Services\ExternalArchive\MegaDiagnoseService;
use App\Services\Operational\BackgroundWatchdogService;
use App\Support\OperationalHeartbeatCache;
use Carbon\Carbon;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

/**
 * Operational health: production fake config, queue backlog, daily rollup (jobs, archives, MEGA, fiscal retry).
 */
class AlertsSystemHealthCommand extends Command
{
    public const CACHE_KEY_QUEUE_STALE_FIRST_SEEN = 'system_health:queue_stale:first_seen';

    protected $signature = 'alerts:system-health
                            {--assume-production : Run production-only checks (testing)}';

    protected $description = 'Create minimal operational admin_alerts (queue, fake config, daily rollup)';

    public function handle(AdminAlertService $alerts): int
    {
        Cache::put(
            OperationalHeartbeatCache::SYSTEM_HEALTH_LAST_RUN_AT,
            now()->toIso8601String(),
            OperationalHeartbeatCache::ttl(),
        );

        $assumeProd = (bool) $this->option('assume-production');
        $runProdOnly = app()->environment('production') || $assumeProd;

        if ($runProdOnly) {
            $this->checkFakeProductionDrivers($alerts);
        }

        $this->checkStaleQueueJobs($alerts);

        $dateKey = now('Europe/Podgorica')->toDateString();

        $failedJobs24h = 0;
        if (Schema::hasTable('failed_jobs')) {
            $failedJobs24h = (int) DB::table('failed_jobs')
                ->where('failed_at', '>=', now()->subDay())
                ->count();
        }

        $failedArchives = ExternalFileArchive::query()
            ->where('status', ExternalFileArchive::STATUS_FAILED)
            ->count();

        /** @var MegaDiagnoseService $megaDiagnose */
        $megaDiagnose = app(MegaDiagnoseService::class);
        $megaResult = $megaDiagnose->run();

        Cache::put(
            OperationalHeartbeatCache::MEGA_LAST_DIAGNOSE_AT,
            now()->toIso8601String(),
            OperationalHeartbeatCache::ttl(),
        );
        Cache::put(
            OperationalHeartbeatCache::MEGA_LAST_DIAGNOSE_OK,
            (bool) ($megaResult['ok'] ?? false),
            OperationalHeartbeatCache::ttl(),
        );
        $megaErr = trim((string) ($megaResult['error'] ?? ''));
        if ($megaErr !== '') {
            Cache::put(
                OperationalHeartbeatCache::MEGA_LAST_DIAGNOSE_ERROR,
                Str::limit($megaErr, 500),
                OperationalHeartbeatCache::ttl(),
            );
        } else {
            Cache::forget(OperationalHeartbeatCache::MEGA_LAST_DIAGNOSE_ERROR);
        }

        $megaConfigured = ($megaResult['email_present'] ?? false) && ($megaResult['password_present'] ?? false);
        $megaBad = $megaConfigured
            && (! ($megaResult['login_ok'] ?? false)
                || ! ($megaResult['folder_found'] ?? false)
                || ! ($megaResult['ok'] ?? false));

        $fiscalStuck = 0;
        if (Schema::hasTable('post_fiscalization_data')) {
            $fiscalStuck = (int) PostFiscalizationData::query()
                ->unresolved()
                ->where('created_at', '<=', now()->subHours(2))
                ->count();
        }

        $megaLine = ! $megaConfigured
            ? 'MEGA: nije podešeno (kredencijali)'
            : ($megaBad
                ? 'MEGA: problem (login/folder ili dijagnostika)'
                : 'MEGA: ok');

        $hasRollupProblems = $failedJobs24h > 0
            || $failedArchives > 0
            || $megaBad
            || $fiscalStuck > 0;

        if ($hasRollupProblems) {
            $severity = ($fiscalStuck > 0 || $failedArchives > 0 || $megaBad) ? 'high' : 'medium';

            $lines = [
                '- Neuspjeli poslovi (24h): '.$failedJobs24h,
                '- Neuspjela arhiva (MEGA): '.$failedArchives,
                '- '.$megaLine,
                '- Nefiskalizovane rezervacije (post_fiscalization, >2h): '.$fiscalStuck,
            ];

            $alerts->createOnce(
                'system_health_daily',
                'Dnevna sistemska provjera: problemi postoje',
                implode("\n", $lines),
                $severity,
                'system_health_daily:'.$dateKey,
                [
                    'date' => $dateKey,
                    'failed_jobs_24h' => $failedJobs24h,
                    'failed_archives' => $failedArchives,
                    'mega_line' => $megaLine,
                    'mega_configured' => $megaConfigured,
                    'mega_bad' => $megaBad,
                    'fiscal_unresolved_over_2h' => $fiscalStuck,
                ],
            );
        }

        Cache::put(
            OperationalHeartbeatCache::SYSTEM_HEALTH_LAST_OK_AT,
            now()->toIso8601String(),
            OperationalHeartbeatCache::ttl(),
        );

        app(BackgroundWatchdogService::class)->evaluateStaleHeartbeats($alerts);

        return self::SUCCESS;
    }

    private function checkFakeProductionDrivers(AdminAlertService $alerts): void
    {
        $flags = [];

        $bankDriver = config('services.bank.driver', 'fake');

        if ($bankDriver === 'fake') {
            $flags[] = 'services.bank.driver=fake';
        }

        if (config('services.fiscalization.driver') === 'fake') {
            $flags[] = 'services.fiscalization.driver=fake';
        }

        if (filter_var(config('payment.fake_e2e_sync'), FILTER_VALIDATE_BOOLEAN)) {
            $flags[] = 'payment.fake_e2e_sync=true';
        }

        if ($flags === []) {
            return;
        }

        $alerts->createOnce(
            'system_config_fake_production',
            'KRITIČNO: fake payment/fiscal podešavanja u produkciji',
            'Aktivne zastavice: '.implode('; ', $flags)."\n"
                .'Ispraviti .env / config prije pravih uplata i fiskalizacije.',
            'critical',
            'system_config_fake_production',
            ['flags' => $flags],
        );
    }

    private function checkStaleQueueJobs(AdminAlertService $alerts): void
    {
        $cacheKey = self::CACHE_KEY_QUEUE_STALE_FIRST_SEEN;

        if (config('queue.default') !== 'database') {
            Cache::forget($cacheKey);

            return;
        }

        if (! Schema::hasTable('jobs')) {
            Cache::forget($cacheKey);

            return;
        }

        $staleMinutes = max(1, (int) config('queue.system_health.queue_stale_minutes', 5));
        $confirmMinutes = max(1, (int) config('queue.system_health.queue_stale_confirm_minutes', 2));
        $markerTtlMinutes = max($confirmMinutes + 1, (int) config('queue.system_health.queue_stale_marker_ttl_minutes', 60));

        $threshold = now()->getTimestamp() - ($staleMinutes * 60);
        $pendingOld = (int) DB::table('jobs')
            ->whereNull('reserved_at')
            ->where('available_at', '<=', $threshold)
            ->count();

        if ($pendingOld < 1) {
            Cache::forget($cacheKey);

            return;
        }

        $oldest = DB::table('jobs')
            ->whereNull('reserved_at')
            ->where('available_at', '<=', $threshold)
            ->orderBy('available_at')
            ->first();

        $oldestAgeSec = $oldest !== null
            ? (now()->getTimestamp() - (int) $oldest->available_at)
            : 0;

        $queueConn = (string) config('queue.default', 'sync');

        if ($this->hasOpenQueueWorkerDownAlert()) {
            Cache::forget($cacheKey);

            return;
        }

        $marker = Cache::get($cacheKey);
        $markerData = is_array($marker) ? $marker : null;

        if ($markerData === null || ! isset($markerData['first_seen'])) {
            Cache::put(
                $cacheKey,
                [
                    'first_seen' => now()->getTimestamp(),
                    'pending_stale_count' => $pendingOld,
                    'oldest_available_age_seconds' => $oldestAgeSec,
                ],
                now()->addMinutes($markerTtlMinutes),
            );

            Log::channel('payments')->info('system_health_queue_stale_first_seen', [
                'queue_connection' => $queueConn,
                'pending_stale_count' => $pendingOld,
                'oldest_available_age_seconds' => $oldestAgeSec,
                'stale_after_minutes' => $staleMinutes,
                'confirm_after_minutes' => $confirmMinutes,
            ]);

            return;
        }

        $firstSeenAt = Carbon::createFromTimestamp((int) $markerData['first_seen']);
        if (now()->lt($firstSeenAt->copy()->addMinutes($confirmMinutes))) {
            return;
        }

        $firstSeenDisplay = $firstSeenAt->timezone('Europe/Podgorica')->toIso8601String();

        $messageLines = [
            'Detektovano u dva ciklusa provjere (nije slagalo alarm na prvu). Worker se ne automatski restartuje.',
            sprintf('Queue connection: %s', $queueConn),
            sprintf(
                'Broj pending poslova starijih od ~%d min: %d',
                $staleMinutes,
                $pendingOld
            ),
            sprintf('Starost najstarijeg na čekanju: ~%d s', $oldestAgeSec),
            sprintf('Prvo zapažanje (Europe/Podgorica / ISO): %s', $firstSeenDisplay),
        ];
        $message = implode("\n", $messageLines);

        $alerts->createOnce(
            'queue_worker_down',
            'Sistem: queue worker možda ne radi',
            $message,
            'critical',
            'queue_worker_down',
            [
                'pending_stale_count' => $pendingOld,
                'oldest_available_age_seconds' => $oldestAgeSec,
                'queue_connection' => $queueConn,
                'first_seen_at' => $firstSeenDisplay,
                'detected_twice' => true,
                'stale_after_minutes' => $staleMinutes,
                'confirm_after_minutes' => $confirmMinutes,
            ],
        );

        Cache::forget($cacheKey);
    }

    private function hasOpenQueueWorkerDownAlert(): bool
    {
        return AdminAlert::query()
            ->where('type', 'queue_worker_down')
            ->whereNull('removed_at')
            ->whereNot('status', AdminAlert::STATUS_DONE)
            ->where('payload_json->dedupe_key', 'queue_worker_down')
            ->exists();
    }
}
