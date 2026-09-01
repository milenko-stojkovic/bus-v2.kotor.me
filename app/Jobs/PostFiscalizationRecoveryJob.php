<?php

namespace App\Jobs;

use App\Services\Payment\PostFiscalizationRecoveryService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

/**
 * Best-effort recovery sweep for previously non-retryable unresolved post_fiscalization_data rows.
 */
class PostFiscalizationRecoveryJob implements ShouldQueue, ShouldBeUnique
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $timeout = 300;

    public int $uniqueFor = 900;

    public function __construct(
        public ?int $triggerReservationId = null,
    ) {}

    public function uniqueId(): string
    {
        return 'post_fiscalization_recovery_sweep';
    }

    public function handle(PostFiscalizationRecoveryService $recovery): void
    {
        $recovery->runSweep($this->triggerReservationId);
    }
}
