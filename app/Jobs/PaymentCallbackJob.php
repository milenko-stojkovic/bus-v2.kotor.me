<?php

namespace App\Jobs;

use App\Events\PaymentFailed;
use App\Models\Reservation;
use App\Models\TempData;
use App\Services\AgencyAdvance\AgencyLateSuccessAdvanceConversionService;
use App\Services\AgencyAdvance\AdvanceTopupProcessor;
use App\Services\AdminPanel\Blocking\BlockZoneWorklistService;
use App\Services\AdminFiscalizationAlertService;
use App\Services\Payment\ErrorClassifier;
use App\Services\Payment\PaymentSuccessHandler;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Payment state machine. Only API bank callbacks drive transitions.
 *
 * Rules:
 * - All payments start as pending. processed is terminal (no further transitions).
 * - late_success only when: bank SUCCESS after temp_data was expired (cron lock released); never creates reservation.
 * - canceled is terminal: a later bank SUCCESS does not transition to late_success (logged and ignored).
 * - processed is idempotent: duplicate SUCCESS callbacks must not create duplicate reservations.
 * - Every transition logged; all transitions inside DB transaction. Never rely on frontend for state.
 */
class PaymentCallbackJob implements ShouldQueue, ShouldBeUnique
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    public int $timeout = 60;

    /**
     * @return array<int, int> seconds before retry 2 and 3
     */
    public function backoff(): array
    {
        return [60, 300, 900];
    }

    public function __construct(
        public array $payload,
        public array $rawPayload = [],
        /**
         * true samo za FakeBankCompleteController ({@see \App\Support\QueueMode::dispatchPaymentCallbackSyncForFakeQaForm}) — pipeline sa scenarijem ide odmah posle callbacka u istom zahtjevu.
         * false za async webhook: mora dispatch ProcessReservationAfterPaymentJob iz PaymentSuccessHandler.
         */
        public bool $deferFakeBankFiscalPipeline = false,
    ) {}

    public function handle(): void
    {
        $txId = $this->payload['merchant_transaction_id'] ?? null;
        $callbackStatus = $this->normalizeStatus($this->payload['status'] ?? null);

        if (! $txId) {
            Log::channel('payments')->warning('Payment callback job skipped: missing merchant_transaction_id', [
                'payload_status' => $this->payload['status'] ?? null,
                'job' => static::class,
            ]);

            return;
        }

        if (! $callbackStatus) {
            Log::channel('payments')->warning('Payment callback job skipped: unsupported or empty callback status', [
                'merchant_transaction_id' => $txId,
                'raw_status' => $this->payload['status'] ?? null,
                'job' => static::class,
            ]);

            return;
        }

        $txId = (string) $txId;
        $reservationIdEarly = $this->reservationIdForMerchantTx($txId);

        $temp = TempData::where('merchant_transaction_id', $txId)->first();
        if (! $temp) {
            // Not a reservation/temp_data payment. Try agency advance topups (feature-flag gated).
            if ((bool) config('features.advance_payments')) {
                if ($callbackStatus === 'success') {
                    app(AdvanceTopupProcessor::class)->markPaid($txId, $this->rawPayload);
                } else {
                    app(AdvanceTopupProcessor::class)->markFailed($txId, $this->rawPayload);
                }

                return;
            }

            Log::channel('payments')->warning('Payment callback job skipped: temp_data not found', [
                'merchant_transaction_id' => $txId,
                'reservation_id' => $reservationIdEarly,
                'status' => $callbackStatus,
            ]);
            return;
        }

        if ($temp->isTerminal()) {
            Log::channel('payments')->info('Payment callback job terminal temp_data', [
                'merchant_transaction_id' => $txId,
                'temp_data_id' => $temp->id,
                'reservation_id' => $reservationIdEarly,
                'status' => $callbackStatus,
                'temp_status' => $temp->status,
            ]);
            if ($temp->status === TempData::STATUS_PROCESSED) {
                return;
            }
            if ($callbackStatus === 'success' && $temp->status === TempData::STATUS_EXPIRED) {
                app(PaymentSuccessHandler::class)->applyLateSuccess($temp, $this->rawPayload, false);
                app(AgencyLateSuccessAdvanceConversionService::class)->convertIfEligible($temp, $this->rawPayload);

                return;
            }
            if ($callbackStatus === 'success' && $temp->status === TempData::STATUS_LATE_SUCCESS) {
                // Conversion is safe & idempotent; also covers case where first run set late_success but conversion failed/crashed.
                app(AgencyLateSuccessAdvanceConversionService::class)->convertIfEligible($temp, $this->rawPayload);
                return;
            }
            if ($callbackStatus === 'success' && $temp->status === TempData::STATUS_CANCELED) {
                Log::channel('payments')->warning('payment_success_after_canceled_ignored', [
                    'merchant_transaction_id' => $txId,
                    'temp_data_id' => $temp->id,
                ]);
                app(AdminFiscalizationAlertService::class)->notifyPaymentSuccessAfterCanceled($temp, $this->rawPayload);

                return;
            }

            return;
        }

        if (Reservation::where('merchant_transaction_id', $txId)->exists()) {
            Log::channel('payments')->info('Payment callback job duplicate: reservation already exists', [
                'merchant_transaction_id' => $txId,
                'temp_data_id' => $temp->id,
                'reservation_id' => $this->reservationIdForMerchantTx($txId),
                'status' => $callbackStatus,
            ]);
            return;
        }

        if ($callbackStatus === 'success') {
            app(PaymentSuccessHandler::class)->handle($temp, $this->rawPayload, true, $this->deferFakeBankFiscalPipeline);
            return;
        }

        if ($callbackStatus === 'timeout') {
            app(PaymentSuccessHandler::class)->applyLateSuccess($temp, $this->rawPayload, true);
            return;
        }

        $this->handleCanceled($temp);
    }

    private function normalizeStatus(?string $status): ?string
    {
        if ($status === null) {
            return null;
        }
        if (in_array($status, ['CANCEL', 'ERROR'], true)) {
            return 'failed';
        }

        return in_array($status, ['success', 'failed', 'timeout'], true) ? $status : null;
    }

    private function handleCanceled(TempData $temp): void
    {
        DB::transaction(function () use ($temp): void {
            $temp = TempData::where('merchant_transaction_id', $temp->merchant_transaction_id)->lockForUpdate()->first();
            if (! $temp || $temp->isTerminal()) {
                return;
            }
            $from = $temp->status;

            $rawCode = $this->payload['error_code']
                ?? ($this->payload['code'] ?? ($this->payload['errorCode'] ?? null))
                ?? ($this->rawPayload['code'] ?? ($this->rawPayload['errorCode'] ?? null));

            $rawMessage = $this->payload['error_reason']
                ?? ($this->payload['message'] ?? ($this->payload['errorMessage'] ?? null))
                ?? ($this->rawPayload['message'] ?? ($this->rawPayload['errorMessage'] ?? null));

            $classified = app(ErrorClassifier::class)->classify(
                'bankart',
                $rawCode,
                $rawMessage,
                is_array($this->rawPayload) ? $this->rawPayload : null,
                ['stage' => 'payment_callback'],
            );

            $temp->update([
                'status' => TempData::STATUS_CANCELED,
                'raw_callback_payload' => $this->rawPayload,
                'callback_error_code' => $rawCode,
                'callback_error_reason' => $rawMessage,
                'resolution_reason' => $classified['resolution_reason'],
            ]);
            TempData::logStateTransition($temp->merchant_transaction_id, $from, TempData::STATUS_CANCELED, 'CANCEL/ERROR/failed');
            app(PaymentSuccessHandler::class)->releaseSoftLock($temp, false);
            app(BlockZoneWorklistService::class)->onTempDataFailedOrExpired($temp, 'canceled');
            event(new PaymentFailed($temp));
        });
    }

    public function uniqueId(): string
    {
        return (string) ($this->payload['merchant_transaction_id'] ?? $this->job?->getJobId() ?? 'payment-callback');
    }

    private function reservationIdForMerchantTx(string $merchantTransactionId): ?int
    {
        $id = Reservation::query()->where('merchant_transaction_id', $merchantTransactionId)->value('id');

        return $id !== null ? (int) $id : null;
    }

    public function failed(?Throwable $e): void
    {
        $mtid = $this->payload['merchant_transaction_id'] ?? null;
        $mtid = is_string($mtid) ? $mtid : null;
        $reservationId = $mtid !== null && $mtid !== ''
            ? Reservation::query()->where('merchant_transaction_id', $mtid)->value('id')
            : null;

        Log::channel('payments')->error('payment_callback_job_exhausted', [
            'merchant_transaction_id' => $mtid,
            'reservation_id' => $reservationId,
            'message' => $e?->getMessage(),
            'exception' => $e !== null ? $e::class : null,
        ]);
    }
}
