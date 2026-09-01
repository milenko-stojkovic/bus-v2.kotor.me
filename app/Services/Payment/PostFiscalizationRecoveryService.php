<?php

namespace App\Services\Payment;

use App\Models\PostFiscalizationData;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

/**
 * Bounded recovery sweep for unresolved post_fiscalization_data rows with next_retry_at = NULL.
 */
final class PostFiscalizationRecoveryService
{
    public const LOCK_KEY = 'post_fiscalization_recovery_sweep';

    public const COOLDOWN_CACHE_KEY = 'post_fiscalization_recovery:last_sweep_at';

    public function __construct(
        private readonly PostFiscalizationRetryProcessor $retryProcessor,
    ) {}

    /**
     * @return array{status: string, processed: int, succeeded: int, failed: int, skipped: int, trigger_reservation_id: int|null}
     */
    public function runSweep(?int $triggerReservationId = null): array
    {
        $summary = [
            'status' => 'skipped',
            'processed' => 0,
            'succeeded' => 0,
            'failed' => 0,
            'skipped' => 0,
            'trigger_reservation_id' => $triggerReservationId,
        ];

        $lock = Cache::lock(self::LOCK_KEY, 600);
        if (! $lock->get()) {
            Log::channel('payments')->info('post_fiscalization_recovery_skipped', [
                'source' => PostFiscalizationRetryProcessor::SOURCE_RECOVERY,
                'reason' => 'concurrent_sweep',
                'trigger_reservation_id' => $triggerReservationId,
            ]);

            $summary['status'] = 'concurrent';

            return $summary;
        }

        try {
            if ($this->isWithinCooldown()) {
                Log::channel('payments')->info('post_fiscalization_recovery_skipped', [
                    'source' => PostFiscalizationRetryProcessor::SOURCE_RECOVERY,
                    'reason' => 'cooldown',
                    'trigger_reservation_id' => $triggerReservationId,
                    'cooldown_minutes' => $this->cooldownMinutes(),
                ]);

                $summary['status'] = 'cooldown';

                return $summary;
            }

            $candidates = $this->selectCandidates();
            if ($candidates->isEmpty()) {
                Log::channel('payments')->info('post_fiscalization_recovery_skipped', [
                    'source' => PostFiscalizationRetryProcessor::SOURCE_RECOVERY,
                    'reason' => 'no_candidates',
                    'trigger_reservation_id' => $triggerReservationId,
                ]);

                $summary['status'] = 'no_candidates';

                return $summary;
            }

            Log::channel('payments')->info('post_fiscalization_recovery_triggered', [
                'source' => PostFiscalizationRetryProcessor::SOURCE_RECOVERY,
                'trigger_reservation_id' => $triggerReservationId,
                'candidate_count' => $candidates->count(),
                'batch_size' => $this->batchSize(),
            ]);

            Cache::put(self::COOLDOWN_CACHE_KEY, now()->toIso8601String(), now()->addDay());

            foreach ($candidates as $post) {
                $summary['processed']++;
                $outcome = $this->retryProcessor->process($post, PostFiscalizationRetryProcessor::SOURCE_RECOVERY);

                match ($outcome['outcome']) {
                    'success' => $summary['succeeded']++,
                    'failure' => $summary['failed']++,
                    default => $summary['skipped']++,
                };
            }

            $summary['status'] = 'completed';

            Log::channel('payments')->info('post_fiscalization_recovery_summary', [
                'source' => PostFiscalizationRetryProcessor::SOURCE_RECOVERY,
                'trigger_reservation_id' => $triggerReservationId,
                'processed' => $summary['processed'],
                'succeeded' => $summary['succeeded'],
                'failed' => $summary['failed'],
                'skipped' => $summary['skipped'],
            ]);

            return $summary;
        } finally {
            $lock->release();
        }
    }

    /**
     * @return Collection<int, PostFiscalizationData>
     */
    public function selectCandidates(): Collection
    {
        $cooldownCutoff = now()->subMinutes($this->cooldownMinutes());

        return PostFiscalizationData::query()
            ->unresolved()
            ->whereNull('next_retry_at')
            ->where('updated_at', '<=', $cooldownCutoff)
            ->whereHas('reservation', fn ($q) => $q->whereNull('fiscal_jir'))
            ->with('reservation')
            ->orderBy('created_at')
            ->orderBy('id')
            ->limit($this->batchSize())
            ->get();
    }

    public function batchSize(): int
    {
        $size = (int) config('services.fiscalization.post_fiscalization_recovery.batch_size', 5);

        return max(1, $size);
    }

    public function cooldownMinutes(): int
    {
        $minutes = (int) config('services.fiscalization.post_fiscalization_recovery.cooldown_minutes', 15);

        return max(1, $minutes);
    }

    private function isWithinCooldown(): bool
    {
        $lastSweep = Cache::get(self::COOLDOWN_CACHE_KEY);
        if (! is_string($lastSweep) || $lastSweep === '') {
            return false;
        }

        try {
            return \Carbon\Carbon::parse($lastSweep)->gt(now()->subMinutes($this->cooldownMinutes()));
        } catch (\Throwable) {
            return false;
        }
    }
}
