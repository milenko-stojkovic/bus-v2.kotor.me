<?php

namespace App\Services\Payment;

use App\Jobs\SendInvoiceEmailJob;
use App\Models\PostFiscalizationData;
use App\Models\Reservation;
use App\Services\AdminFiscalizationAlertService;
use App\Services\FiscalizationService;
use Illuminate\Support\Facades\Log;

/**
 * Shared post-fiscalization retry execution for cron, manual Artisan force, and recovery sweeps.
 */
final class PostFiscalizationRetryProcessor
{
    public const SOURCE_SCHEDULED = 'scheduled';

    public const SOURCE_MANUAL_ARTISAN_FORCE = 'manual_artisan_force';

    public const SOURCE_RECOVERY = 'post_fiscalization_recovery';

    public function __construct(
        private readonly FiscalizationService $fiscalization,
        private readonly PostFiscalizationRecoveryDispatcher $recoveryDispatcher,
    ) {}

    /**
     * @return array{outcome: string, error?: string, retryable?: bool, next_retry_at?: string|null, post_fiscalization_data_id?: int}
     */
    public function process(PostFiscalizationData $post, string $source): array
    {
        $reservation = $post->reservation;
        if (! $reservation) {
            $post->delete();
            $this->log($source, 'info', 'orphan_deleted', $post);

            return ['outcome' => 'orphan_deleted'];
        }

        if ($reservation->fiscal_jir !== null) {
            $postId = $post->id;
            $post->delete();
            $this->log($source, 'info', 'already_fiscalized', $post, $reservation, [
                'post_fiscalization_data_id' => $postId,
            ]);

            return ['outcome' => 'already_fiscalized'];
        }

        $this->log($source, 'info', 'candidate_started', $post, $reservation);

        try {
            $result = $this->fiscalization->tryFiscalize($reservation);
        } catch (\Throwable $e) {
            Log::channel('payments')->error('post_fiscalization_retry_exception', [
                'source' => $source,
                'post_fiscalization_data_id' => $post->id,
                'reservation_id' => $reservation->id,
                'merchant_transaction_id' => $reservation->merchant_transaction_id,
                'attempts' => $post->attempts,
                'message' => $e->getMessage(),
                'exception' => $e::class,
            ]);

            return $this->recordFailure($post, $reservation, $source, [
                'error' => 'Fiscal service unavailable',
                'retryable' => true,
            ]);
        }

        if (isset($result['fiscal_jir'])) {
            $postId = $post->id;
            $reservationId = $reservation->id;
            $post->applyFiscalDataAndDelete($result);
            SendInvoiceEmailJob::dispatch($reservationId, true);

            $this->log($source, 'info', 'candidate_success', $post, $reservation, [
                'post_fiscalization_data_id' => $postId,
                'result' => 'success',
            ]);

            if ($source !== self::SOURCE_RECOVERY) {
                $this->recoveryDispatcher->signalFiscalChannelHealthy($reservationId);
            }

            return ['outcome' => 'success', 'post_fiscalization_data_id' => $postId];
        }

        return $this->recordFailure($post, $reservation, $source, $result);
    }

    /**
     * @param  array<string, mixed>  $result
     * @return array{outcome: string, error?: string, retryable?: bool, next_retry_at?: string|null}
     */
    private function recordFailure(PostFiscalizationData $post, Reservation $reservation, string $source, array $result): array
    {
        $post->increment('attempts');
        $post->refresh();
        $retryable = (bool) ($result['retryable'] ?? true);
        $nextRetryAt = $retryable ? now()->addMinutes(15 * $post->attempts) : null;
        $error = is_string($result['error'] ?? null) ? $result['error'] : 'Fiscal service unavailable';

        $post->update([
            'error' => $error,
            'next_retry_at' => $nextRetryAt,
        ]);

        $this->log($source, 'warning', 'candidate_failure', $post, $reservation, [
            'retryable' => $retryable,
            'next_retry_at' => $nextRetryAt?->toIso8601String(),
            'error' => $error,
            'result' => 'failure',
        ]);

        if ($source === self::SOURCE_SCHEDULED) {
            $this->notifyRetryFailingOverOneDay($post, $reservation, $result);
        }

        return [
            'outcome' => 'failure',
            'error' => $error,
            'retryable' => $retryable,
            'next_retry_at' => $nextRetryAt?->toDateTimeString(),
        ];
    }

    /**
     * @param  array<string, mixed>  $extra
     */
    private function log(string $source, string $level, string $event, PostFiscalizationData $post, ?Reservation $reservation = null, array $extra = []): void
    {
        $context = array_merge([
            'source' => $source,
            'event' => $event,
            'post_fiscalization_data_id' => $post->id,
            'reservation_id' => $reservation?->id ?? $post->reservation_id,
            'merchant_transaction_id' => $reservation?->merchant_transaction_id ?? $post->merchant_transaction_id,
            'attempts' => $post->attempts,
        ], $extra);

        Log::channel('payments')->{$level}('post_fiscalization_retry_'.$event, $context);
    }

    /**
     * @param  array<string, mixed>  $result
     */
    private function notifyRetryFailingOverOneDay(PostFiscalizationData $post, Reservation $reservation, array $result): void
    {
        $isOlderThanDay = $post->created_at !== null && $post->created_at->lte(now()->subDay());
        $shouldNotifyNow = $isOlderThanDay && ($post->admin_notified_at === null || $post->admin_notified_at->lte(now()->subDay()));
        if (! $shouldNotifyNow) {
            return;
        }

        $reason = $result['resolution_reason'] ?? ($result['category'] ?? 'error');
        $alerts = app(AdminFiscalizationAlertService::class);
        $alerts->notify(
            'FISCAL ALERT: retry failing > 1 day ('.$reason.')',
            "Fiscalization retry has been failing for more than 1 day.\n\n"
            .'reason: '.$reason."\n"
            .'error: '.($result['error'] ?? 'Fiscal service unavailable')."\n\n"
            .$alerts->buildReservationContext($reservation)."\n\n"
            .$alerts->buildPostRowContext($post)."\n",
            [
                'reservation_id' => $reservation->id,
                'merchant_transaction_id' => $reservation->merchant_transaction_id,
                'post_fiscalization_data_id' => $post->id,
            ]
        );
        $post->update(['admin_notified_at' => now()]);
    }
}
