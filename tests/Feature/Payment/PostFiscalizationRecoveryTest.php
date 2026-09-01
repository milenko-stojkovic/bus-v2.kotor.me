<?php

namespace Tests\Feature\Payment;

use App\Jobs\PostFiscalizationRecoveryJob;
use App\Jobs\ProcessReservationAfterPaymentJob;
use App\Jobs\SendInvoiceEmailJob;
use App\Models\ListOfTimeSlot;
use App\Models\PostFiscalizationData;
use App\Models\Reservation;
use App\Models\VehicleType;
use App\Models\VehicleTypeTranslation;
use App\Services\FiscalizationService;
use App\Services\Payment\PostFiscalizationRecoveryService;
use App\Services\Payment\PostFiscalizationRetryProcessor;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Cache;
use Tests\TestCase;

final class PostFiscalizationRecoveryTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Cache::flush();
        config()->set('services.fiscalization.post_fiscalization_recovery.enabled', true);
        config()->set('services.fiscalization.post_fiscalization_recovery.batch_size', 5);
        config()->set('services.fiscalization.post_fiscalization_recovery.cooldown_minutes', 15);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    public function test_a_successful_payment_fiscalization_dispatches_recovery_job(): void
    {
        Bus::fake([PostFiscalizationRecoveryJob::class, SendInvoiceEmailJob::class]);

        $reservation = $this->createPaidReservation('tx-recovery-trigger');

        $this->mock(FiscalizationService::class, function ($mock): void {
            $mock->shouldReceive('tryFiscalize')
                ->once()
                ->andReturn([
                    'fiscal_jir' => 'JIR-TRIGGER',
                    'fiscal_ikof' => 'IKOF-TRIGGER',
                    'fiscal_date' => now(),
                ]);
        });

        (new ProcessReservationAfterPaymentJob($reservation->id))->handle();

        Bus::assertDispatched(PostFiscalizationRecoveryJob::class, function (PostFiscalizationRecoveryJob $job) use ($reservation): bool {
            return $job->triggerReservationId === $reservation->id;
        });
    }

    public function test_b_recovery_selects_only_null_scheduled_unfiscalized_rows(): void
    {
        $eligible = $this->seedPostRow('tx-eligible', attempts: 2, nextRetryAt: null, updatedAt: now()->subHours(1));
        $this->seedPostRow('tx-scheduled', attempts: 1, nextRetryAt: now()->addHour(), updatedAt: now()->subHours(1));
        $this->seedPostRow('tx-recent', attempts: 1, nextRetryAt: null, updatedAt: now()->subMinutes(5));
        $fiscalized = $this->seedPostRow('tx-fiscalized', attempts: 1, nextRetryAt: null, updatedAt: now()->subHours(1));
        Reservation::query()->whereKey($fiscalized->reservation_id)->update(['fiscal_jir' => 'JIR-EXISTS']);

        $ids = app(PostFiscalizationRecoveryService::class)
            ->selectCandidates()
            ->pluck('id')
            ->all();

        $this->assertSame([$eligible->id], $ids);
    }

    public function test_c_batch_limit_is_respected(): void
    {
        config()->set('services.fiscalization.post_fiscalization_recovery.batch_size', 2);

        for ($i = 1; $i <= 4; $i++) {
            $this->seedPostRow('tx-batch-'.$i, attempts: 1, nextRetryAt: null, updatedAt: now()->subHours(2));
        }

        $this->assertCount(2, app(PostFiscalizationRecoveryService::class)->selectCandidates());
    }

    public function test_d_oldest_candidates_are_selected_first(): void
    {
        config()->set('services.fiscalization.post_fiscalization_recovery.batch_size', 3);

        $oldest = $this->seedPostRow('tx-old', attempts: 1, nextRetryAt: null, updatedAt: now()->subHours(3), createdAt: now()->subDays(5));
        $middle = $this->seedPostRow('tx-mid', attempts: 1, nextRetryAt: null, updatedAt: now()->subHours(3), createdAt: now()->subDays(3));
        $newest = $this->seedPostRow('tx-new', attempts: 1, nextRetryAt: null, updatedAt: now()->subHours(3), createdAt: now()->subDay());
        $this->seedPostRow('tx-extra', attempts: 1, nextRetryAt: null, updatedAt: now()->subHours(3), createdAt: now());

        $ids = app(PostFiscalizationRecoveryService::class)
            ->selectCandidates()
            ->pluck('id')
            ->all();

        $this->assertSame([$oldest->id, $middle->id, $newest->id], $ids);
    }

    public function test_e_cooldown_prevents_second_sweep(): void
    {
        Bus::fake([SendInvoiceEmailJob::class]);
        $this->seedPostRow('tx-cooldown', attempts: 1, nextRetryAt: null, updatedAt: now()->subHours(2));

        $this->mock(FiscalizationService::class, function ($mock): void {
            $mock->shouldReceive('tryFiscalize')->once()->andReturn([
                'fiscal_jir' => 'JIR-1',
                'fiscal_ikof' => 'IKOF-1',
                'fiscal_date' => now(),
            ]);
        });

        $first = app(PostFiscalizationRecoveryService::class)->runSweep(100);
        $this->assertSame('completed', $first['status']);
        $this->assertSame(1, $first['succeeded']);

        $this->mock(FiscalizationService::class, function ($mock): void {
            $mock->shouldReceive('tryFiscalize')->never();
        });

        $second = app(PostFiscalizationRecoveryService::class)->runSweep(101);
        $this->assertSame('cooldown', $second['status']);
        $this->assertSame(0, $second['processed']);
    }

    public function test_f_concurrent_sweep_is_skipped_when_lock_held(): void
    {
        Cache::lock(PostFiscalizationRecoveryService::LOCK_KEY, 600)->get();

        $result = app(PostFiscalizationRecoveryService::class)->runSweep(200);

        $this->assertSame('concurrent', $result['status']);
        $this->assertSame(0, $result['processed']);
    }

    public function test_g_recovery_success_applies_fiscal_data_deletes_post_and_dispatches_email(): void
    {
        Bus::fake([SendInvoiceEmailJob::class, PostFiscalizationRecoveryJob::class]);

        $row = $this->seedPostRow('tx-recovery-success', attempts: 3, nextRetryAt: null, updatedAt: now()->subHours(2));

        $this->mock(FiscalizationService::class, function ($mock): void {
            $mock->shouldReceive('tryFiscalize')->once()->andReturn([
                'fiscal_jir' => 'JIR-REC',
                'fiscal_ikof' => 'IKOF-REC',
                'fiscal_date' => now(),
            ]);
        });

        $summary = app(PostFiscalizationRecoveryService::class)->runSweep($row->reservation_id);

        $this->assertSame('completed', $summary['status']);
        $this->assertSame(1, $summary['succeeded']);
        $this->assertNull(PostFiscalizationData::query()->find($row->id));
        $this->assertSame('JIR-REC', (string) Reservation::query()->find($row->reservation_id)?->fiscal_jir);
        Bus::assertDispatched(SendInvoiceEmailJob::class, fn (SendInvoiceEmailJob $job) => $job->reservationId === $row->reservation_id && $job->isFiscal);
        Bus::assertNotDispatched(PostFiscalizationRecoveryJob::class);
    }

    public function test_h_recovery_failure_retryable_schedules_next_retry_at(): void
    {
        $row = $this->seedPostRow('tx-recovery-retryable', attempts: 2, nextRetryAt: null, updatedAt: now()->subHours(2));

        $this->mock(FiscalizationService::class, function ($mock): void {
            $mock->shouldReceive('tryFiscalize')->once()->andReturn([
                'error' => 'Provider down',
                'retryable' => true,
            ]);
        });

        app(PostFiscalizationRecoveryService::class)->runSweep();

        $fresh = $row->fresh();
        $this->assertSame(3, (int) $fresh->attempts);
        $this->assertNotNull($fresh->next_retry_at);
    }

    public function test_i_recovery_failure_non_retryable_keeps_next_retry_at_null(): void
    {
        $row = $this->seedPostRow('tx-recovery-non-retryable', attempts: 2, nextRetryAt: null, updatedAt: now()->subHours(2));

        $this->mock(FiscalizationService::class, function ($mock): void {
            $mock->shouldReceive('tryFiscalize')->once()->andReturn([
                'error' => 'Client time differs from server time more than 30 minutes',
                'retryable' => false,
            ]);
        });

        app(PostFiscalizationRecoveryService::class)->runSweep();

        $fresh = $row->fresh();
        $this->assertSame(3, (int) $fresh->attempts);
        $this->assertNull($fresh->next_retry_at);
    }

    public function test_j_one_candidate_failure_does_not_stop_processing_others(): void
    {
        Bus::fake([SendInvoiceEmailJob::class, PostFiscalizationRecoveryJob::class]);
        config()->set('services.fiscalization.post_fiscalization_recovery.batch_size', 5);

        $fail = $this->seedPostRow('tx-recovery-fail', attempts: 1, nextRetryAt: null, updatedAt: now()->subHours(2), createdAt: now()->subDays(2));
        $ok = $this->seedPostRow('tx-recovery-ok', attempts: 1, nextRetryAt: null, updatedAt: now()->subHours(2), createdAt: now()->subDay());

        $this->mock(FiscalizationService::class, function ($mock) use ($fail, $ok): void {
            $mock->shouldReceive('tryFiscalize')
                ->twice()
                ->andReturnUsing(function (Reservation $reservation) use ($fail, $ok) {
                    if ($reservation->id === $fail->reservation_id) {
                        return ['error' => 'still broken', 'retryable' => false];
                    }
                    if ($reservation->id === $ok->reservation_id) {
                        return [
                            'fiscal_jir' => 'JIR-OK2',
                            'fiscal_ikof' => 'IKOF-OK2',
                            'fiscal_date' => now(),
                        ];
                    }

                    return ['error' => 'unexpected', 'retryable' => false];
                });
        });

        $summary = app(PostFiscalizationRecoveryService::class)->runSweep();

        $this->assertSame(2, $summary['processed']);
        $this->assertSame(1, $summary['succeeded']);
        $this->assertSame(1, $summary['failed']);
        $this->assertNotNull(PostFiscalizationData::query()->find($fail->id));
        $this->assertNull(PostFiscalizationData::query()->find($ok->id));
    }

    public function test_k_already_fiscalized_reservation_is_never_retried(): void
    {
        $row = $this->seedPostRow('tx-recovery-already', attempts: 1, nextRetryAt: null, updatedAt: now()->subHours(2));
        Reservation::query()->whereKey($row->reservation_id)->update(['fiscal_jir' => 'JIR-DONE']);

        $this->mock(FiscalizationService::class, function ($mock): void {
            $mock->shouldReceive('tryFiscalize')->never();
        });

        $ids = app(PostFiscalizationRecoveryService::class)->selectCandidates()->pluck('id')->all();
        $this->assertSame([], $ids);
    }

    private function createPaidReservation(string $merchantTx): Reservation
    {
        $drop = ListOfTimeSlot::query()->create(['time_slot' => '10:00 - 10:20']);
        $pick = ListOfTimeSlot::query()->create(['time_slot' => '11:00 - 11:20']);
        $vt = VehicleType::query()->create(['price' => 12.5]);
        foreach (['en', 'cg'] as $locale) {
            VehicleTypeTranslation::query()->create([
                'vehicle_type_id' => $vt->id,
                'locale' => $locale,
                'name' => 'Car',
                'description' => null,
            ]);
        }

        return Reservation::query()->create([
            'drop_off_time_slot_id' => $drop->id,
            'pick_up_time_slot_id' => $pick->id,
            'reservation_date' => '2026-07-15',
            'user_name' => 'Guest',
            'country' => 'ME',
            'license_plate' => 'KO 123',
            'vehicle_type_id' => $vt->id,
            'email' => 'guest@test.me',
            'merchant_transaction_id' => $merchantTx,
            'status' => 'paid',
            'invoice_amount' => '12.50',
            'email_sent' => Reservation::EMAIL_NOT_SENT,
        ]);
    }

    private function seedPostRow(
        string $merchantTx,
        int $attempts,
        ?\DateTimeInterface $nextRetryAt,
        \DateTimeInterface $updatedAt,
        ?\DateTimeInterface $createdAt = null,
    ): PostFiscalizationData {
        $reservation = $this->createPaidReservation($merchantTx);

        $post = PostFiscalizationData::query()->create([
            'reservation_id' => $reservation->id,
            'merchant_transaction_id' => $merchantTx,
            'error' => 'initial failure',
            'attempts' => $attempts,
            'next_retry_at' => $nextRetryAt,
            'resolved_at' => null,
        ]);

        $post->forceFill([
            'created_at' => $createdAt ?? $updatedAt,
            'updated_at' => $updatedAt,
        ])->saveQuietly();

        return $post->fresh();
    }
}
