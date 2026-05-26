<?php

namespace App\Services\AgencyAdvance;

use App\Models\AgencyAdvanceTopup;
use App\Models\AgencyAdvanceTransaction;
use App\Models\TempData;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

final class AgencyLateSuccessAdvanceConversionService
{
    /**
     * @return 'converted'|'skipped_guest'|'skipped_feature_disabled'|'skipped_not_late_success'|'skipped_already_converted'|'failed'
     */
    public function convertIfEligible(TempData $tempData, array $payload = []): string
    {
        if (! (bool) config('features.advance_payments')) {
            Log::channel('payments')->info('late_success_advance_conversion_skipped_feature_disabled', [
                'temp_data_id' => $tempData->id,
                'merchant_transaction_id' => $tempData->merchant_transaction_id,
                'user_id' => $tempData->user_id,
            ]);
            return 'skipped_feature_disabled';
        }

        if ($tempData->user_id === null) {
            Log::channel('payments')->info('late_success_advance_conversion_skipped_guest', [
                'temp_data_id' => $tempData->id,
                'merchant_transaction_id' => $tempData->merchant_transaction_id,
                'user_id' => null,
            ]);
            return 'skipped_guest';
        }

        try {
            $result = DB::transaction(function () use ($tempData, $payload): string {
                /** @var TempData|null $locked */
                $locked = TempData::query()
                    ->where('merchant_transaction_id', $tempData->merchant_transaction_id)
                    ->lockForUpdate()
                    ->first();

                if (! $locked) {
                    return 'failed';
                }

                if ($locked->status !== TempData::STATUS_LATE_SUCCESS) {
                    Log::channel('payments')->info('late_success_advance_conversion_skipped_not_late_success', [
                        'temp_data_id' => $locked->id,
                        'merchant_transaction_id' => $locked->merchant_transaction_id,
                        'user_id' => $locked->user_id,
                        'status' => $locked->status,
                    ]);
                    return 'skipped_not_late_success';
                }

                if ((string) ($locked->resolution_reason ?? '') === 'converted_to_advance') {
                    Log::channel('payments')->info('late_success_advance_conversion_skipped_already_converted', [
                        'temp_data_id' => $locked->id,
                        'merchant_transaction_id' => $locked->merchant_transaction_id,
                        'user_id' => $locked->user_id,
                    ]);
                    return 'skipped_already_converted';
                }

                $amountFloat = null;
                if ($locked->invoice_amount_snapshot !== null && is_numeric((string) $locked->invoice_amount_snapshot)) {
                    $amountFloat = (float) $locked->invoice_amount_snapshot;
                }

                if ($amountFloat === null) {
                    // Fallback: legacy rows before invoice_amount_snapshot existed.
                    $amountFloat = (float) \App\Support\ReservationInvoiceAmount::snapshotForNewReservation('paid', (int) $locked->vehicle_type_id);
                    Log::channel('payments')->warning('late_success_advance_amount_snapshot_missing', [
                        'temp_data_id' => $locked->id,
                        'merchant_transaction_id' => $locked->merchant_transaction_id,
                        'user_id' => $locked->user_id,
                        'vehicle_type_id' => $locked->vehicle_type_id,
                        'fallback_amount' => number_format((float) $amountFloat, 2, '.', ''),
                    ]);
                }

                // Idempotency: topup by MTID.
                /** @var AgencyAdvanceTopup|null $topup */
                $topup = AgencyAdvanceTopup::query()
                    ->where('merchant_transaction_id', $locked->merchant_transaction_id)
                    ->lockForUpdate()
                    ->first();

                if (! $topup) {
                    $topup = AgencyAdvanceTopup::query()->create([
                        'agency_user_id' => (int) $locked->user_id,
                        'merchant_transaction_id' => (string) $locked->merchant_transaction_id,
                        'amount' => number_format($amountFloat, 2, '.', ''),
                        'status' => AgencyAdvanceTopup::STATUS_PAID,
                        'bank_payload' => $payload,
                        'paid_at' => now(),
                        'failed_at' => null,
                        'confirmation_sent_at' => null,
                        'confirmation_email' => null,
                        'confirmation_sending_at' => null,
                    ]);
                }

                // Idempotency: ledger topup by reference (late_success temp_data).
                $existsLedger = AgencyAdvanceTransaction::query()
                    ->where('type', AgencyAdvanceTransaction::TYPE_TOPUP)
                    ->where('reference_type', 'late_success_temp_data')
                    ->where('reference_id', (int) $locked->id)
                    ->lockForUpdate()
                    ->exists();

                if (! $existsLedger) {
                    AgencyAdvanceTransaction::query()->create([
                        'agency_user_id' => (int) $locked->user_id,
                        'amount' => number_format($amountFloat, 2, '.', ''),
                        'type' => AgencyAdvanceTransaction::TYPE_TOPUP,
                        'reference_type' => 'late_success_temp_data',
                        'reference_id' => (int) $locked->id,
                        'merchant_transaction_id' => (string) $locked->merchant_transaction_id,
                        'note' => \App\Support\AdvanceLedgerNote::KEY_LATE_SUCCESS_CONVERSION,
                        'created_by_admin_id' => null,
                    ]);
                }

                $locked->update([
                    'resolution_reason' => 'converted_to_advance',
                ]);

                Log::channel('payments')->info('late_success_converted_to_advance', [
                    'temp_data_id' => $locked->id,
                    'merchant_transaction_id' => $locked->merchant_transaction_id,
                    'user_id' => $locked->user_id,
                    'amount' => number_format($amountFloat, 2, '.', ''),
                    'topup_id' => $topup->id,
                ]);

                return 'converted';
            });

            if ($result !== 'converted') {
                return $result;
            }

            // Confirmation email is best-effort (idempotent inside service).
            try {
                app(AdvanceTopupConfirmationService::class)->sendIfNeeded((string) $tempData->merchant_transaction_id);
            } catch (Throwable $e) {
                Log::channel('payments')->warning('late_success_advance_confirmation_failed', [
                    'temp_data_id' => $tempData->id,
                    'merchant_transaction_id' => $tempData->merchant_transaction_id,
                    'user_id' => $tempData->user_id,
                    'message' => $e->getMessage(),
                    'exception' => $e::class,
                ]);
            }

            return 'converted';
        } catch (Throwable $e) {
            Log::channel('payments')->error('late_success_advance_conversion_failed', [
                'temp_data_id' => $tempData->id,
                'merchant_transaction_id' => $tempData->merchant_transaction_id,
                'user_id' => $tempData->user_id,
                'message' => $e->getMessage(),
                'exception' => $e::class,
            ]);

            return 'failed';
        }
    }
}

