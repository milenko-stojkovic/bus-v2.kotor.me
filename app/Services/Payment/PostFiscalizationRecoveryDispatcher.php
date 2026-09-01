<?php

namespace App\Services\Payment;

use App\Jobs\PostFiscalizationRecoveryJob;

/**
 * Schedules a best-effort recovery sweep after evidence that fiscalization succeeded.
 */
final class PostFiscalizationRecoveryDispatcher
{
    public function signalFiscalChannelHealthy(?int $triggerReservationId = null): void
    {
        if (! (bool) config('services.fiscalization.post_fiscalization_recovery.enabled', true)) {
            return;
        }

        PostFiscalizationRecoveryJob::dispatch($triggerReservationId);
    }
}
