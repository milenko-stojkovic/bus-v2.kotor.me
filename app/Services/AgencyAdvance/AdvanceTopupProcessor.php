<?php

namespace App\Services\AgencyAdvance;

use App\Models\AgencyAdvanceTopup;
use App\Models\AgencyAdvanceTransaction;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

final class AdvanceTopupProcessor
{
    public function markPaid(string $merchantTransactionId, array $rawPayload = []): void
    {
        if (! (bool) config('features.advance_payments')) {
            return;
        }

        DB::transaction(function () use ($merchantTransactionId, $rawPayload): void {
            /** @var AgencyAdvanceTopup|null $topup */
            $topup = AgencyAdvanceTopup::query()
                ->where('merchant_transaction_id', $merchantTransactionId)
                ->lockForUpdate()
                ->first();

            if (! $topup) {
                return;
            }

            if ($topup->status === AgencyAdvanceTopup::STATUS_PAID) {
                Log::channel('payments')->info('advance_topup_duplicate_callback_ignored', [
                    'merchant_transaction_id' => $merchantTransactionId,
                    'topup_id' => $topup->id,
                    'agency_user_id' => $topup->agency_user_id,
                    'status' => 'paid_already',
                ]);
                return;
            }

            if ($topup->status !== AgencyAdvanceTopup::STATUS_PENDING) {
                // Do not resurrect failed/expired here.
                return;
            }

            $topup->status = AgencyAdvanceTopup::STATUS_PAID;
            $topup->paid_at = now();
            $topup->bank_payload = $rawPayload !== [] ? $rawPayload : ($topup->bank_payload ?? null);
            $topup->save();

            // Idempotency: ensure we never create duplicate ledger rows for same topup.
            $exists = AgencyAdvanceTransaction::query()
                ->where('type', AgencyAdvanceTransaction::TYPE_TOPUP)
                ->where('reference_type', 'advance_topup')
                ->where('reference_id', $topup->id)
                ->exists();

            if ($exists) {
                Log::channel('payments')->info('advance_topup_duplicate_callback_ignored', [
                    'merchant_transaction_id' => $merchantTransactionId,
                    'topup_id' => $topup->id,
                    'agency_user_id' => $topup->agency_user_id,
                    'status' => 'ledger_exists',
                ]);
                return;
            }

            AgencyAdvanceTransaction::query()->create([
                'agency_user_id' => $topup->agency_user_id,
                'amount' => (string) $topup->amount,
                'type' => AgencyAdvanceTransaction::TYPE_TOPUP,
                'reference_type' => 'advance_topup',
                'reference_id' => $topup->id,
                'merchant_transaction_id' => $topup->merchant_transaction_id,
                'note' => \App\Support\AdvanceLedgerNote::KEY_TOPUP,
                'created_by_admin_id' => null,
            ]);

            Log::channel('payments')->info('advance_topup_paid', [
                'merchant_transaction_id' => $merchantTransactionId,
                'topup_id' => $topup->id,
                'agency_user_id' => $topup->agency_user_id,
                'amount' => (string) $topup->amount,
            ]);
        });

        try {
            app(AdvanceTopupConfirmationService::class)->sendIfNeeded($merchantTransactionId);
        } catch (Throwable $e) {
            Log::channel('payments')->warning('advance_topup_confirmation_failed', [
                'merchant_transaction_id' => $merchantTransactionId,
                'message' => $e->getMessage(),
                'exception' => $e::class,
            ]);
        }
    }

    public function markFailed(string $merchantTransactionId, array $rawPayload = []): void
    {
        if (! (bool) config('features.advance_payments')) {
            return;
        }

        DB::transaction(function () use ($merchantTransactionId, $rawPayload): void {
            /** @var AgencyAdvanceTopup|null $topup */
            $topup = AgencyAdvanceTopup::query()
                ->where('merchant_transaction_id', $merchantTransactionId)
                ->lockForUpdate()
                ->first();

            if (! $topup) {
                return;
            }

            if ($topup->status === AgencyAdvanceTopup::STATUS_PAID) {
                // Do not downgrade paid to failed.
                return;
            }

            if ($topup->status === AgencyAdvanceTopup::STATUS_FAILED) {
                return;
            }

            $topup->status = AgencyAdvanceTopup::STATUS_FAILED;
            $topup->failed_at = now();
            $topup->bank_payload = $rawPayload !== [] ? $rawPayload : ($topup->bank_payload ?? null);
            $topup->save();

            Log::channel('payments')->info('advance_topup_failed', [
                'merchant_transaction_id' => $merchantTransactionId,
                'topup_id' => $topup->id,
                'agency_user_id' => $topup->agency_user_id,
                'amount' => (string) $topup->amount,
            ]);
        });
    }
}

