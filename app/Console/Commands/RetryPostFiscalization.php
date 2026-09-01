<?php

namespace App\Console\Commands;

use App\Jobs\SendInvoiceEmailJob;
use App\Models\PostFiscalizationData;
use App\Models\Reservation;
use App\Services\AdminFiscalizationAlertService;
use App\Services\FiscalizationService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

/**
 * Cron: retry fiskalizacije za rezervacije iz post_fiscalization_data (next_retry_at <= now).
 * Uspeh → ažurira reservation fiscal_*, briše slog, šalje kupcu novi fiskalni PDF i email.
 * Neuspeh → poveća attempts, postavi next_retry_at. V. docs/cron-commands.md.
 *
 * Manual (Plesk): --reservation=ID --force or --id=ID --force bypasses next_retry_at for one run.
 */
class RetryPostFiscalization extends Command
{
    protected $signature = 'post-fiscalization:retry
        {--force : Bypass next_retry_at for a targeted manual retry (requires --reservation or --id)}
        {--reservation= : Retry unresolved post_fiscalization_data for this reservation id}
        {--id= : Retry unresolved post_fiscalization_data row by id}';

    protected $description = 'Retry fiscalization for post_fiscalization_data rows; on success send fiscal PDF to customer';

    public function handle(FiscalizationService $fiscalization): int
    {
        $force = (bool) $this->option('force');
        $reservationId = $this->option('reservation');
        $postId = $this->option('id');
        $hasReservation = $reservationId !== null && $reservationId !== '';
        $hasPostId = $postId !== null && $postId !== '';

        if ($hasReservation && $hasPostId) {
            $this->error('Use only one of --reservation or --id, not both.');

            return self::FAILURE;
        }

        if ($force && ! $hasReservation && ! $hasPostId) {
            $this->error('--force requires --reservation=ID or --id=ID for a targeted manual retry.');
            $this->line('Bulk force of all unresolved rows is not supported (mixed error types may need different fixes).');

            return self::FAILURE;
        }

        if (($hasReservation || $hasPostId) && ! $force) {
            $target = $hasReservation ? '--reservation='.$reservationId : '--id='.$postId;
            $this->error($target.' requires --force to bypass next_retry_at scheduling for a manual retry.');
            $this->line('Scheduled cron retry only processes rows where next_retry_at <= now().');

            return self::FAILURE;
        }

        if ($force) {
            return $this->handleManualForce($fiscalization, $hasReservation ? (int) $reservationId : null, $hasPostId ? (int) $postId : null);
        }

        return $this->handleScheduledRetry($fiscalization);
    }

    private function handleScheduledRetry(FiscalizationService $fiscalization): int
    {
        $rows = PostFiscalizationData::unresolved()
            ->where('next_retry_at', '<=', now())
            ->with('reservation')
            ->get();

        foreach ($rows as $post) {
            $this->processPostRow($post, $fiscalization, manualForce: false);
        }

        $this->notifyStaleUnresolvedRows();

        $this->info('Processed '.$rows->count().' post_fiscalization_data rows.');

        return self::SUCCESS;
    }

    private function handleManualForce(FiscalizationService $fiscalization, ?int $reservationId, ?int $postId): int
    {
        $query = PostFiscalizationData::unresolved()->with('reservation');

        if ($reservationId !== null) {
            $this->line('Manual retry reservation #'.$reservationId.'...');
            $query->where('reservation_id', $reservationId);
        } else {
            $this->line('Manual retry post_fiscalization_data #'.$postId.'...');
            $query->whereKey($postId);
        }

        $rows = $query->get();

        if ($rows->isEmpty()) {
            if ($reservationId !== null) {
                $this->warn('No unresolved post-fiscalization row found for reservation #'.$reservationId.'.');
            } else {
                $this->warn('No unresolved post-fiscalization row found with id #'.$postId.'.');
            }

            return self::FAILURE;
        }

        foreach ($rows as $post) {
            $outcome = $this->processPostRow($post, $fiscalization, manualForce: true);
            $this->renderManualOutcome($post, $outcome);
        }

        return self::SUCCESS;
    }

    /**
     * @return array{outcome: string, error?: string, retryable?: bool, next_retry_at?: string|null}
     */
    private function processPostRow(PostFiscalizationData $post, FiscalizationService $fiscalization, bool $manualForce): array
    {
        $reservation = $post->reservation;
        if (! $reservation) {
            $post->delete();
            if ($manualForce) {
                Log::channel('payments')->info('post_fiscalization_manual_force_orphan_deleted', [
                    'source' => 'manual_artisan_force',
                    'post_fiscalization_data_id' => $post->id,
                    'result' => 'orphan_deleted',
                ]);
            }

            return ['outcome' => 'orphan_deleted'];
        }

        if ($reservation->fiscal_jir !== null) {
            $postId = $post->id;
            $reservationId = $reservation->id;
            $post->delete();
            if ($manualForce) {
                Log::channel('payments')->info('post_fiscalization_manual_force_already_fiscalized', [
                    'source' => 'manual_artisan_force',
                    'post_fiscalization_data_id' => $postId,
                    'reservation_id' => $reservationId,
                    'merchant_transaction_id' => $reservation->merchant_transaction_id,
                    'attempts' => $post->attempts,
                    'result' => 'already_fiscalized',
                ]);
            }

            return ['outcome' => 'already_fiscalized'];
        }

        if ($manualForce) {
            Log::channel('payments')->info('post_fiscalization_manual_force_started', [
                'source' => 'manual_artisan_force',
                'post_fiscalization_data_id' => $post->id,
                'reservation_id' => $reservation->id,
                'merchant_transaction_id' => $reservation->merchant_transaction_id,
                'attempts' => $post->attempts,
            ]);
        }

        $result = $fiscalization->tryFiscalize($reservation);

        if (isset($result['fiscal_jir'])) {
            $postId = $post->id;
            $reservationId = $reservation->id;
            $post->applyFiscalDataAndDelete($result);
            SendInvoiceEmailJob::dispatch($reservationId, true);

            if ($manualForce) {
                Log::channel('payments')->info('post_fiscalization_manual_force_success', [
                    'source' => 'manual_artisan_force',
                    'post_fiscalization_data_id' => $postId,
                    'reservation_id' => $reservationId,
                    'merchant_transaction_id' => $reservation->merchant_transaction_id,
                    'attempts' => $post->attempts,
                    'result' => 'success',
                ]);
            } else {
                $this->info('Fiscalized reservation '.$reservationId.', sent fiscal PDF.');
            }

            return ['outcome' => 'success', 'post_fiscalization_data_id' => $postId];
        }

        $post->increment('attempts');
        $post->refresh();
        $retryable = (bool) ($result['retryable'] ?? true);
        $nextRetryAt = $retryable ? now()->addMinutes(15 * $post->attempts) : null;
        $error = $result['error'] ?? 'Fiscal service unavailable';

        $post->update([
            'error' => $error,
            'next_retry_at' => $nextRetryAt,
        ]);

        if ($manualForce) {
            Log::channel('payments')->warning('post_fiscalization_manual_force_failed', [
                'source' => 'manual_artisan_force',
                'post_fiscalization_data_id' => $post->id,
                'reservation_id' => $reservation->id,
                'merchant_transaction_id' => $reservation->merchant_transaction_id,
                'attempts' => $post->attempts,
                'retryable' => $retryable,
                'next_retry_at' => $nextRetryAt?->toIso8601String(),
                'error' => $error,
                'result' => 'failure',
            ]);
        } else {
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
     * @param  array{outcome: string, error?: string, retryable?: bool, next_retry_at?: string|null, post_fiscalization_data_id?: int}  $outcome
     */
    private function renderManualOutcome(PostFiscalizationData $post, array $outcome): void
    {
        $reservationId = $post->reservation_id;
        $postId = $post->id;

        $message = match ($outcome['outcome']) {
            'success' => 'SUCCESS:'."\n"
                .'Reservation #'.$reservationId.' fiscalized successfully.'."\n"
                .'post_fiscalization_data #'.($outcome['post_fiscalization_data_id'] ?? $postId).' removed.',
            'already_fiscalized' => 'Reservation #'.$reservationId.' already has fiscal_jir; post_fiscalization_data #'.$postId.' removed.',
            'orphan_deleted' => 'Orphan post_fiscalization_data #'.$postId.' removed (reservation missing).',
            'failure' => 'FAILURE:'."\n"
                .'Reservation #'.$reservationId.' fiscalization failed.'."\n"
                .'Error: '.($outcome['error'] ?? 'unknown')."\n"
                .'retryable: '.(($outcome['retryable'] ?? false) ? 'true' : 'false')."\n"
                .'next_retry_at: '.$this->formatNextRetryAtForOutput($outcome['next_retry_at'] ?? null),
            default => null,
        };

        if ($message !== null) {
            foreach (explode("\n", $message) as $line) {
                $this->line($line);
            }
        }
    }

    private function formatNextRetryAtForOutput(?string $nextRetryAt): string
    {
        return $nextRetryAt === null || $nextRetryAt === '' ? 'NULL' : $nextRetryAt;
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

    private function notifyStaleUnresolvedRows(): void
    {
        $stale = PostFiscalizationData::unresolved()
            ->where('created_at', '<=', now()->subDay())
            ->where(function ($q) {
                $q->whereNull('admin_notified_at')
                    ->orWhere('admin_notified_at', '<=', now()->subDay());
            })
            ->with('reservation')
            ->get();

        foreach ($stale as $post) {
            $reservation = $post->reservation;
            if (! $reservation) {
                continue;
            }

            $alerts = app(AdminFiscalizationAlertService::class);
            $alerts->notify(
                'FISCAL ALERT: unresolved > 1 day',
                "Fiscalization unresolved for more than 1 day.\n\n"
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
}
