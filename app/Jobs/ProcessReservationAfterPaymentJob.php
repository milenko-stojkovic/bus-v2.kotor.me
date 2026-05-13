<?php

namespace App\Jobs;

use App\Models\PostFiscalizationData;
use App\Models\Reservation;
use App\Services\AdminFiscalizationAlertService;
use App\Services\FiscalizationService;
use App\Support\QueueMode;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Payment states: pending → success → [fiscalization_failed → post_fiscalization_data].
 *
 * Reservations are created on success (even if fiscalization fails). Failed fiscalization must not
 * block invoice generation: we always dispatch email (fiscal or non-fiscal PDF generisan u jobu).
 *
 * Try fiscalization. On SUCCESS: update reservation fiscal_*, send invoice email.
 * On FAILURE (fiscalization_failed): insert post_fiscalization_data, send non-fiscal PDF email.
 * On job exhausted ({@see failed()}): ako nema JIR-a i nema nerešenog post sloga, kreira se minimalni post red
 * ({@see RESOLUTION_JOB_FAILED_BEFORE_FISCAL}) da UX ne ostane na paid_processing zauvijek.
 * Do NOT rollback reservation.
 */
class ProcessReservationAfterPaymentJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    /** Marker u `post_fiscalization_data.error` kad job iscrpi pokušaje prije JIR-a i prije normalnog post sloga (UX: fiscal_delayed_known). */
    public const RESOLUTION_JOB_FAILED_BEFORE_FISCAL = 'job_failed_before_fiscal_completion';

    public int $tries = 3;

    /** Fiskal + dispatch email mora stati ispod worker timeout-a. */
    public int $timeout = 120;

    /**
     * @return array<int, int>
     */
    public function backoff(): array
    {
        return [120, 600, 1800];
    }

    public function __construct(
        public int $reservationId,
        /** Scenario za fake efiscal API (kombinovana fake QA forma); null = env/session. */
        public ?string $fakeFiscalScenario = null,
    ) {}

    public function handle(): void
    {
        $reservation = Reservation::find($this->reservationId);
        if (! $reservation) {
            Log::channel('payments')->warning('process_reservation_after_payment_reservation_missing', [
                'reservation_id' => $this->reservationId,
                'fake_fiscal_scenario' => $this->fakeFiscalScenario,
                'job' => static::class,
            ]);

            return;
        }

        if ($reservation->fiscal_jir !== null) {
            if ($reservation->invoice_sent_at !== null) {
                return;
            }
            $this->dispatchInvoiceEmail($reservation->id, true);

            return;
        }

        if ($reservation->status === 'free') {
            return;
        }

        $result = app(FiscalizationService::class)->tryFiscalize($reservation, $this->fakeFiscalScenario);

        if (isset($result['fiscal_jir'])) {
            $reservation->update([
                'fiscal_jir' => $result['fiscal_jir'],
                'fiscal_ikof' => $result['fiscal_ikof'],
                'fiscal_qr' => $result['fiscal_qr'] ?? null,
                'fiscal_operator' => $result['fiscal_operator'] ?? (config('services.fiscal.enu_identifier') ?: null),
                'fiscal_date' => $result['fiscal_date'] ?? now(),
            ]);
            Log::channel('payments')->info('payment_fiscal_success', [
                'reservation_id' => $reservation->id,
                'merchant_transaction_id' => $reservation->merchant_transaction_id,
            ]);
            $this->dispatchInvoiceEmail($reservation->id, true);

            return;
        }

        $errorMessage = $result['error'] ?? 'Fiscal service unavailable';

        $resolutionReason = is_string($result['resolution_reason'] ?? null) ? (string) $result['resolution_reason'] : 'unknown_fiscal_error';
        $notifyAdmin = (bool) ($result['notify_admin'] ?? false);
        $retryable = (bool) ($result['retryable'] ?? true);

        if ($resolutionReason === 'already_fiscalized') {
            $existingFiscal = Reservation::query()
                ->where('merchant_transaction_id', $reservation->merchant_transaction_id)
                ->whereNotNull('fiscal_jir')
                ->first();

            if ($existingFiscal) {
                Log::channel('payments')->warning('Fiscal provider returned already_fiscalized; ignoring because fiscalized reservation exists', [
                    'reservation_id' => $reservation->id,
                    'merchant_transaction_id' => $reservation->merchant_transaction_id,
                    'existing_reservation_id' => $existingFiscal->id,
                ]);

                return;
            }

            $alerts = app(AdminFiscalizationAlertService::class);
            $alerts->notify(
                'FISCAL ALERT: already_fiscalized but not found in DB',
                "Provider returned already_fiscalized (78), but no reservation with fiscal_jir was found for this transaction.\n\n"
                .'resolution_reason: '.$resolutionReason."\n"
                .'error: '.$errorMessage."\n\n"
                .$alerts->buildReservationContext($reservation)."\n", [
                    'reservation_id' => $reservation->id,
                    'merchant_transaction_id' => $reservation->merchant_transaction_id,
                    'resolution_reason' => $resolutionReason,
                ]);

            PostFiscalizationData::create([
                'reservation_id' => $reservation->id,
                'merchant_transaction_id' => $reservation->merchant_transaction_id,
                'error' => $errorMessage,
                'attempts' => 1,
                'next_retry_at' => null,
                'resolved_at' => now(),
                'admin_notified_at' => now(),
            ]);

            return;
        }

        $post = PostFiscalizationData::query()
            ->where('reservation_id', $reservation->id)
            ->unresolved()
            ->first();

        if (! $post) {
            $post = new PostFiscalizationData;
            $post->reservation_id = $reservation->id;
            $post->merchant_transaction_id = $reservation->merchant_transaction_id;
            $post->attempts = 1;
        } else {
            $post->attempts = (int) ($post->attempts ?? 0) + 1;
        }

        $post->error = is_string($errorMessage) ? $errorMessage : 'Fiscal service unavailable';
        $post->next_retry_at = $retryable
            ? now()->addMinutes(15 * max(1, (int) $post->attempts))
            : null;

        if ($resolutionReason === 'timeout') {
            $notifyAdmin = false;
        }

        if ($notifyAdmin && $post->admin_notified_at === null) {
            $alerts = app(AdminFiscalizationAlertService::class);
            $alerts->notify(
                'FISCAL ALERT: initial failure ('.$resolutionReason.')',
                "Initial fiscalization failed after successful payment.\n\n"
                .'resolution_reason: '.$resolutionReason."\n"
                .'error: '.$errorMessage."\n\n"
                .$alerts->buildReservationContext($reservation)."\n", [
                    'reservation_id' => $reservation->id,
                    'merchant_transaction_id' => $reservation->merchant_transaction_id,
                    'resolution_reason' => $resolutionReason,
                ]);
            $post->admin_notified_at = now();
        }

        $post->save();
        Log::channel('payments')->info('post_fiscalization_enqueued', [
            'reservation_id' => $reservation->id,
            'merchant_transaction_id' => $reservation->merchant_transaction_id,
            'attempts' => $post->attempts,
            'next_retry_at' => $post->next_retry_at?->toIso8601String(),
            'retryable' => $retryable,
            'resolution_reason' => $resolutionReason,
        ]);
        $this->dispatchInvoiceEmail($reservation->id, false);
    }

    public function failed(?Throwable $e): void
    {
        $mtid = Reservation::query()->whereKey($this->reservationId)->value('merchant_transaction_id');
        Log::channel('payments')->error('process_reservation_after_payment_job_exhausted', [
            'reservation_id' => $this->reservationId,
            'merchant_transaction_id' => $mtid,
            'message' => $e?->getMessage(),
            'exception' => $e !== null ? $e::class : null,
        ]);

        DB::transaction(function () use ($e): void {
            $reservation = Reservation::query()->whereKey($this->reservationId)->lockForUpdate()->first();
            if ($reservation === null || $reservation->status === 'free' || $reservation->fiscal_jir !== null) {
                return;
            }
            if ($reservation->postFiscalizationDataUnresolved()->exists()) {
                return;
            }

            $detail = $e !== null ? $e::class.': '.($e->getMessage() !== '' ? $e->getMessage() : '(no message)') : 'unknown';
            $error = self::RESOLUTION_JOB_FAILED_BEFORE_FISCAL.' | '.$detail;

            PostFiscalizationData::create([
                'reservation_id' => $reservation->id,
                'merchant_transaction_id' => $reservation->merchant_transaction_id,
                'error' => $error,
                'attempts' => $this->tries,
                'next_retry_at' => now(),
            ]);

            Log::channel('payments')->warning('process_reservation_failed_marked_delayed', [
                'reservation_id' => $reservation->id,
                'merchant_transaction_id' => $reservation->merchant_transaction_id,
                'message' => $e?->getMessage(),
                'exception' => $e !== null ? $e::class : null,
                'resolution_marker' => self::RESOLUTION_JOB_FAILED_BEFORE_FISCAL,
            ]);
        });
    }

    private function dispatchInvoiceEmail(int $reservationId, bool $isFiscal): void
    {
        QueueMode::dispatchForFakeE2e(new SendInvoiceEmailJob($reservationId, $isFiscal));
    }
}
