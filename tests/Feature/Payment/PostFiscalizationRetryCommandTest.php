<?php

namespace Tests\Feature\Payment;

use App\Jobs\SendInvoiceEmailJob;
use App\Models\ListOfTimeSlot;
use App\Models\PostFiscalizationData;
use App\Models\Reservation;
use App\Models\VehicleType;
use App\Models\VehicleTypeTranslation;
use App\Services\FiscalizationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Tests\TestCase;

final class PostFiscalizationRetryCommandTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_scheduled_retry_processes_only_due_rows(): void
    {
        Bus::fake([SendInvoiceEmailJob::class]);

        $due = $this->seedPostRow('tx-due', nextRetryAt: now()->subMinute(), attempts: 2);
        $this->seedPostRow('tx-future', nextRetryAt: now()->addHour(), attempts: 1);
        $this->seedPostRow('tx-null', nextRetryAt: null, attempts: 1);

        $this->mock(FiscalizationService::class, function ($mock) use ($due): void {
            $mock->shouldReceive('tryFiscalize')
                ->once()
                ->withArgs(fn (Reservation $r) => $r->id === $due->reservation_id)
                ->andReturn([
                    'fiscal_jir' => 'JIR-DUE',
                    'fiscal_ikof' => 'IKOF-DUE',
                    'fiscal_date' => now(),
                ]);
        });

        $this->artisan('post-fiscalization:retry')
            ->assertSuccessful()
            ->expectsOutputToContain('Processed 1 post_fiscalization_data rows.');

        $this->assertNull(PostFiscalizationData::query()->find($due->id));
        $this->assertNotNull(PostFiscalizationData::query()->where('merchant_transaction_id', 'tx-future')->first());
        $this->assertNotNull(PostFiscalizationData::query()->where('merchant_transaction_id', 'tx-null')->first());
        Bus::assertDispatched(SendInvoiceEmailJob::class, fn (SendInvoiceEmailJob $job) => $job->reservationId === $due->reservation_id);
    }

    public function test_b_null_next_retry_at_is_not_processed_by_scheduled_retry(): void
    {
        $row = $this->seedPostRow('tx-null-scheduled', nextRetryAt: null, attempts: 3);

        $this->mock(FiscalizationService::class, function ($mock): void {
            $mock->shouldReceive('tryFiscalize')->never();
        });

        $this->artisan('post-fiscalization:retry')
            ->assertSuccessful()
            ->expectsOutputToContain('Processed 0 post_fiscalization_data rows.');

        $this->assertNotNull(PostFiscalizationData::query()->find($row->id));
        $this->assertSame(3, (int) $row->fresh()->attempts);
    }

    public function test_c_force_by_reservation_processes_null_next_retry_at_row(): void
    {
        Bus::fake([SendInvoiceEmailJob::class]);

        $row = $this->seedPostRow('tx-force-res', nextRetryAt: null, attempts: 2);
        $other = $this->seedPostRow('tx-force-other', nextRetryAt: null, attempts: 1);

        $this->mock(FiscalizationService::class, function ($mock): void {
            $mock->shouldReceive('tryFiscalize')
                ->once()
                ->andReturn([
                    'fiscal_jir' => 'JIR-FORCE',
                    'fiscal_ikof' => 'IKOF-FORCE',
                    'fiscal_date' => now(),
                ]);
        });

        $this->artisan('post-fiscalization:retry', [
            '--reservation' => $row->reservation_id,
            '--force' => true,
        ])
            ->assertSuccessful()
            ->expectsOutputToContain('Manual retry reservation #'.$row->reservation_id)
            ->expectsOutputToContain('SUCCESS:')
            ->expectsOutputToContain('post_fiscalization_data #'.$row->id.' removed.');

        $this->assertNull(PostFiscalizationData::query()->find($row->id));
        $this->assertNotNull(PostFiscalizationData::query()->find($other->id));
        $this->assertSame('JIR-FORCE', (string) Reservation::query()->find($row->reservation_id)?->fiscal_jir);
        Bus::assertDispatched(SendInvoiceEmailJob::class);
    }

    public function test_d_force_by_id_processes_null_next_retry_at_row(): void
    {
        Bus::fake([SendInvoiceEmailJob::class]);

        $row = $this->seedPostRow('tx-force-id', nextRetryAt: null, attempts: 1);

        $this->mock(FiscalizationService::class, function ($mock) use ($row): void {
            $mock->shouldReceive('tryFiscalize')
                ->once()
                ->andReturn([
                    'fiscal_jir' => 'JIR-ID',
                    'fiscal_ikof' => 'IKOF-ID',
                    'fiscal_date' => now(),
                ]);
        });

        $this->artisan('post-fiscalization:retry', [
            '--id' => $row->id,
            '--force' => true,
        ])
            ->assertSuccessful()
            ->expectsOutputToContain('Manual retry post_fiscalization_data #'.$row->id);

        $this->assertNull(PostFiscalizationData::query()->find($row->id));
        Bus::assertDispatched(SendInvoiceEmailJob::class);
    }

    public function test_e_reservation_without_force_is_rejected(): void
    {
        $row = $this->seedPostRow('tx-no-force-res', nextRetryAt: null, attempts: 1);

        $this->mock(FiscalizationService::class, function ($mock): void {
            $mock->shouldReceive('tryFiscalize')->never();
        });

        $this->artisan('post-fiscalization:retry', ['--reservation' => $row->reservation_id])
            ->assertFailed()
            ->expectsOutputToContain('--reservation='.$row->reservation_id.' requires --force');

        $this->assertNotNull(PostFiscalizationData::query()->find($row->id));
    }

    public function test_f_id_without_force_is_rejected(): void
    {
        $row = $this->seedPostRow('tx-no-force-id', nextRetryAt: null, attempts: 1);

        $this->mock(FiscalizationService::class, function ($mock): void {
            $mock->shouldReceive('tryFiscalize')->never();
        });

        $this->artisan('post-fiscalization:retry', ['--id' => $row->id])
            ->assertFailed()
            ->expectsOutputToContain('--id='.$row->id.' requires --force');

        $this->assertNotNull(PostFiscalizationData::query()->find($row->id));
    }

    public function test_force_without_target_is_rejected(): void
    {
        $this->artisan('post-fiscalization:retry', ['--force' => true])
            ->assertFailed()
            ->expectsOutputToContain('--force requires --reservation=ID or --id=ID');
    }

    public function test_g_forced_success_applies_fiscal_data_deletes_post_and_dispatches_email(): void
    {
        Bus::fake([SendInvoiceEmailJob::class]);

        $row = $this->seedPostRow('tx-force-success', nextRetryAt: null, attempts: 4);

        $this->mock(FiscalizationService::class, function ($mock): void {
            $mock->shouldReceive('tryFiscalize')
                ->once()
                ->andReturn([
                    'fiscal_jir' => 'JIR-OK',
                    'fiscal_ikof' => 'IKOF-OK',
                    'fiscal_qr' => 'QR-OK',
                    'fiscal_date' => now(),
                ]);
        });

        $this->artisan('post-fiscalization:retry', [
            '--reservation' => $row->reservation_id,
            '--force' => true,
        ])->assertSuccessful();

        $reservation = Reservation::query()->find($row->reservation_id);
        $this->assertSame('JIR-OK', (string) $reservation?->fiscal_jir);
        $this->assertSame('IKOF-OK', (string) $reservation?->fiscal_ikof);
        $this->assertNull(PostFiscalizationData::query()->where('reservation_id', $row->reservation_id)->first());
        Bus::assertDispatched(SendInvoiceEmailJob::class, function (SendInvoiceEmailJob $job) use ($row): bool {
            return $job->reservationId === $row->reservation_id && $job->isFiscal === true;
        });
    }

    public function test_h_forced_failure_retryable_schedules_next_retry_at(): void
    {
        $row = $this->seedPostRow('tx-force-retryable', nextRetryAt: null, attempts: 2);

        $this->mock(FiscalizationService::class, function ($mock): void {
            $mock->shouldReceive('tryFiscalize')
                ->once()
                ->andReturn([
                    'error' => 'Provider down',
                    'resolution_reason' => 'provider_down',
                    'retryable' => true,
                ]);
        });

        $this->artisan('post-fiscalization:retry', [
            '--reservation' => $row->reservation_id,
            '--force' => true,
        ])
            ->assertSuccessful()
            ->expectsOutputToContain('retryable: true');

        $fresh = $row->fresh();
        $this->assertSame(3, (int) $fresh->attempts);
        $this->assertNotNull($fresh->next_retry_at);
        $this->assertSame('Provider down', (string) $fresh->error);
    }

    public function test_i_forced_failure_non_retryable_keeps_next_retry_at_null(): void
    {
        $row = $this->seedPostRow('tx-force-non-retryable', nextRetryAt: null, attempts: 2);

        $this->mock(FiscalizationService::class, function ($mock): void {
            $mock->shouldReceive('tryFiscalize')
                ->once()
                ->andReturn([
                    'error' => 'Client time differs from server time more than 30 minutes',
                    'resolution_reason' => 'unknown_fiscal_error',
                    'retryable' => false,
                ]);
        });

        $this->artisan('post-fiscalization:retry', [
            '--reservation' => $row->reservation_id,
            '--force' => true,
        ])
            ->assertSuccessful()
            ->expectsOutputToContain('retryable: false')
            ->expectsOutputToContain('next_retry_at: NULL');

        $fresh = $row->fresh();
        $this->assertSame(3, (int) $fresh->attempts);
        $this->assertNull($fresh->next_retry_at);
    }

    public function test_j_already_fiscalized_reservation_is_not_fiscalized_again(): void
    {
        Bus::fake([SendInvoiceEmailJob::class]);

        $row = $this->seedPostRow('tx-already-fiscal', nextRetryAt: null, attempts: 1);
        Reservation::query()->whereKey($row->reservation_id)->update(['fiscal_jir' => 'JIR-EXISTING']);

        $this->mock(FiscalizationService::class, function ($mock): void {
            $mock->shouldReceive('tryFiscalize')->never();
        });

        $this->artisan('post-fiscalization:retry', [
            '--reservation' => $row->reservation_id,
            '--force' => true,
        ])
            ->assertSuccessful()
            ->expectsOutputToContain('already has fiscal_jir');

        $this->assertNull(PostFiscalizationData::query()->find($row->id));
        Bus::assertNotDispatched(SendInvoiceEmailJob::class);
    }

    public function test_k_targeted_force_does_not_touch_unrelated_unresolved_rows(): void
    {
        $target = $this->seedPostRow('tx-target', nextRetryAt: null, attempts: 1);
        $other = $this->seedPostRow('tx-unrelated', nextRetryAt: null, attempts: 5);

        $this->mock(FiscalizationService::class, function ($mock): void {
            $mock->shouldReceive('tryFiscalize')
                ->once()
                ->andReturn([
                    'error' => 'still failing',
                    'retryable' => false,
                ]);
        });

        $this->artisan('post-fiscalization:retry', [
            '--reservation' => $target->reservation_id,
            '--force' => true,
        ])->assertSuccessful();

        $this->assertSame(2, (int) $target->fresh()->attempts);
        $this->assertSame(5, (int) $other->fresh()->attempts);
        $this->assertNull($other->fresh()->next_retry_at);
    }

    private function seedPostRow(string $merchantTx, ?\DateTimeInterface $nextRetryAt, int $attempts): PostFiscalizationData
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

        $reservation = Reservation::query()->create([
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

        return PostFiscalizationData::query()->create([
            'reservation_id' => $reservation->id,
            'merchant_transaction_id' => $merchantTx,
            'error' => 'initial failure',
            'attempts' => $attempts,
            'next_retry_at' => $nextRetryAt,
            'resolved_at' => null,
        ]);
    }
}
