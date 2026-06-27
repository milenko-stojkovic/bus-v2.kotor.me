<?php

namespace App\Services\AgencyAdvance;

use App\Mail\AdvanceTopupConfirmationMail;
use App\Models\AgencyAdvanceTopup;
use App\Models\User;
use App\Services\AgencyAdvance\AgencyAdvanceService;
use App\Services\Pdf\AdvanceTopupConfirmationPdfGenerator;
use Illuminate\Contracts\Mail\Mailer;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

final class AdvanceTopupConfirmationService
{
    public function __construct(
        private readonly Mailer $mailer,
        private readonly AgencyAdvanceService $advance,
        private readonly AdvanceTopupConfirmationPdfGenerator $pdf,
    ) {}

    /**
     * @return 'sent'|'already_sent'|'not_paid'|'not_found'|'sending_in_progress'|'failed'|'no_email'
     */
    public function sendIfNeeded(string $merchantTransactionId): string
    {
        if (! (bool) config('features.advance_payments')) {
            return 'not_found';
        }

        $topupId = null;
        $agencyUserId = null;
        $amount = null;

        $claimed = DB::transaction(function () use ($merchantTransactionId, &$topupId, &$agencyUserId, &$amount): bool {
            /** @var AgencyAdvanceTopup|null $topup */
            $topup = AgencyAdvanceTopup::query()
                ->where('merchant_transaction_id', $merchantTransactionId)
                ->lockForUpdate()
                ->first();

            if (! $topup) {
                return false;
            }

            $topupId = (int) $topup->id;
            $agencyUserId = (int) $topup->agency_user_id;
            $amount = (string) $topup->amount;

            if ($topup->status !== AgencyAdvanceTopup::STATUS_PAID) {
                return false;
            }

            if ($topup->confirmation_sent_at !== null) {
                Log::channel('payments')->info('advance_topup_confirmation_duplicate_skipped', [
                    'agency_user_id' => $topup->agency_user_id,
                    'topup_id' => $topup->id,
                    'merchant_transaction_id' => $topup->merchant_transaction_id,
                    'amount' => (string) $topup->amount,
                    'confirmation_email' => $topup->confirmation_email,
                ]);
                return false;
            }

            if ($topup->confirmation_sending_at !== null && $topup->confirmation_sending_at->gt(now()->subMinutes(10))) {
                Log::channel('payments')->info('advance_topup_confirmation_duplicate_skipped', [
                    'agency_user_id' => $topup->agency_user_id,
                    'topup_id' => $topup->id,
                    'merchant_transaction_id' => $topup->merchant_transaction_id,
                    'amount' => (string) $topup->amount,
                    'confirmation_email' => $topup->confirmation_email,
                    'reason' => 'sending_claim_exists',
                ]);
                return false;
            }

            $topup->confirmation_sending_at = now();
            $topup->save();

            return true;
        });

        if (! $topupId || ! $agencyUserId) {
            return 'not_found';
        }

        if (! $claimed) {
            /** @var AgencyAdvanceTopup|null $t */
            $t = AgencyAdvanceTopup::query()->whereKey($topupId)->first();
            if (! $t) {
                return 'not_found';
            }
            if ($t->status !== AgencyAdvanceTopup::STATUS_PAID) {
                return 'not_paid';
            }
            if ($t->confirmation_sent_at !== null) {
                return 'already_sent';
            }
            if ($t->confirmation_sending_at !== null && $t->confirmation_sending_at->gt(now()->subMinutes(10))) {
                return 'sending_in_progress';
            }

            return 'failed';
        }

        /** @var AgencyAdvanceTopup $topup */
        $topup = AgencyAdvanceTopup::query()->findOrFail($topupId);
        /** @var User $agency */
        $agency = User::query()->findOrFail($agencyUserId);

        $email = (string) ($agency->email ?? '');
        if ($email === '') {
            AgencyAdvanceTopup::query()->whereKey($topupId)->update(['confirmation_sending_at' => null]);
            return 'no_email';
        }

        $attachmentFilename = sprintf('potvrda-avans-%d.pdf', $topupId);

        Log::channel('payments')->info('advance_topup_confirmation_started', [
            'event' => 'advance_topup_confirmation_started',
            'agency_user_id' => $agencyUserId,
            'topup_id' => $topupId,
            'merchant_transaction_id' => $merchantTransactionId,
            'recipient_email' => $email,
            'amount' => $amount,
            'attachment_filename' => $attachmentFilename,
        ]);

        try {
            $balanceAfter = $this->advance->balance($agencyUserId);
            $pdfBinary = $this->pdf->renderBinary($agency, $topup, $balanceAfter);

            $this->mailer->to($email)->send(new AdvanceTopupConfirmationMail($agency, $topup, $pdfBinary));

            AgencyAdvanceTopup::query()->whereKey($topupId)->update([
                'confirmation_sent_at' => now(),
                'confirmation_email' => $email,
                'confirmation_sending_at' => null,
            ]);

            Log::channel('payments')->info('advance_topup_confirmation_sent', [
                'event' => 'advance_topup_confirmation_sent',
                'agency_user_id' => $agencyUserId,
                'topup_id' => $topupId,
                'merchant_transaction_id' => $merchantTransactionId,
                'amount' => $amount,
                'recipient_email' => $email,
                'attachment_filename' => $attachmentFilename,
            ]);
            return 'sent';
        } catch (Throwable $e) {
            // Do not rollback paid topup / ledger. Just release claim.
            AgencyAdvanceTopup::query()->whereKey($topupId)->update(['confirmation_sending_at' => null]);

            Log::channel('payments')->warning('advance_topup_confirmation_failed', [
                'event' => 'advance_topup_confirmation_failed',
                'agency_user_id' => $agencyUserId,
                'topup_id' => $topupId,
                'merchant_transaction_id' => $merchantTransactionId,
                'amount' => $amount,
                'recipient_email' => $email,
                'attachment_filename' => $attachmentFilename,
                'message' => $e->getMessage(),
                'exception' => $e::class,
            ]);
            return 'failed';
        }
    }
}

