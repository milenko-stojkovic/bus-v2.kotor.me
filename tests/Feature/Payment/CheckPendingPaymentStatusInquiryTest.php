<?php

namespace Tests\Feature\Payment;

use App\Contracts\PaymentStatusInquiryService;
use App\Jobs\PaymentCallbackJob;
use App\Models\DailyParkingData;
use App\Models\ListOfTimeSlot;
use App\Models\TempData;
use App\Models\VehicleType;
use App\Models\VehicleTypeTranslation;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class CheckPendingPaymentStatusInquiryTest extends TestCase
{
    use RefreshDatabase;

    public function test_dispatches_payment_callback_job_when_inquiry_returns_success(): void
    {
        Queue::fake();

        $temp = $this->createOldPendingTemp('tx-inquiry-success-1');

        $inquiry = new class implements PaymentStatusInquiryService
        {
            public function isImplemented(): bool
            {
                return true;
            }

            public function inquire(string $merchantTransactionId): array
            {
                return [
                    'outcome' => 'success',
                    'raw' => ['success' => true, 'transactionStatus' => 'SUCCESS'],
                ];
            }
        };

        $this->app->instance(PaymentStatusInquiryService::class, $inquiry);

        $this->artisan('payment:check-pending-inquiry')->assertSuccessful();

        Queue::assertPushed(PaymentCallbackJob::class, function (PaymentCallbackJob $job) use ($temp): bool {
            return ($job->payload['merchant_transaction_id'] ?? null) === $temp->merchant_transaction_id
                && ($job->payload['status'] ?? null) === 'success';
        });
    }

    public function test_dispatches_failed_callback_when_inquiry_returns_failed(): void
    {
        Queue::fake();

        $temp = $this->createOldPendingTemp('tx-inquiry-fail-1');

        $inquiry = new class implements PaymentStatusInquiryService
        {
            public function isImplemented(): bool
            {
                return true;
            }

            public function inquire(string $merchantTransactionId): array
            {
                return [
                    'outcome' => 'failed',
                    'raw' => [
                        'success' => true,
                        'transactionStatus' => 'ERROR',
                        'errors' => [['code' => '1234', 'message' => 'Declined']],
                    ],
                ];
            }
        };

        $this->app->instance(PaymentStatusInquiryService::class, $inquiry);

        $this->artisan('payment:check-pending-inquiry')->assertSuccessful();

        Queue::assertPushed(PaymentCallbackJob::class, function (PaymentCallbackJob $job) use ($temp): bool {
            return ($job->payload['merchant_transaction_id'] ?? null) === $temp->merchant_transaction_id
                && ($job->payload['status'] ?? null) === 'failed'
                && ($job->payload['error_code'] ?? null) === '1234';
        });
    }

    public function test_releases_pending_when_inquiry_returns_transaction_not_found(): void
    {
        $temp = $this->createOldPendingTemp('tx-inquiry-not-found-1');

        DailyParkingData::query()->create([
            'date' => $temp->reservation_date?->toDateString(),
            'time_slot_id' => $temp->drop_off_time_slot_id,
            'capacity' => 5,
            'reserved' => 0,
            'pending' => 1,
            'is_blocked' => false,
        ]);
        DailyParkingData::query()->create([
            'date' => $temp->reservation_date?->toDateString(),
            'time_slot_id' => $temp->pick_up_time_slot_id,
            'capacity' => 5,
            'reserved' => 0,
            'pending' => 1,
            'is_blocked' => false,
        ]);

        $inquiry = new class implements PaymentStatusInquiryService
        {
            public function isImplemented(): bool
            {
                return true;
            }

            public function inquire(string $merchantTransactionId): array
            {
                return [
                    'outcome' => 'not_found',
                    'raw' => [
                        'success' => false,
                        'errorMessage' => 'Transaction not found',
                        'http_status' => 404,
                    ],
                ];
            }
        };

        $this->app->instance(PaymentStatusInquiryService::class, $inquiry);

        $this->artisan('payment:check-pending-inquiry')->assertSuccessful();

        $temp->refresh();
        $this->assertSame(TempData::STATUS_CANCELED, (string) $temp->status);
        $this->assertSame('payment_init_failed', (string) $temp->resolution_reason);

        $dropPending = (int) DailyParkingData::query()
            ->where('date', $temp->reservation_date?->toDateString())
            ->where('time_slot_id', $temp->drop_off_time_slot_id)
            ->value('pending');
        $this->assertSame(0, $dropPending);
    }

    public function test_not_found_within_grace_window_does_not_cancel_when_create_session_likely_succeeded(): void
    {
        config(['payment.status_inquiry_not_found_grace_minutes' => 15]);
        config(['payment.pending_inquiry_after_minutes' => 3]);

        $temp = $this->createOldPendingTemp('tx-inquiry-not-found-grace-1');
        $temp->forceFill(['created_at' => now()->subMinutes(4)])->saveQuietly();

        $inquiry = new class implements PaymentStatusInquiryService
        {
            public function isImplemented(): bool
            {
                return true;
            }

            public function inquire(string $merchantTransactionId): array
            {
                return [
                    'outcome' => 'not_found',
                    'raw' => [
                        'success' => false,
                        'errorMessage' => 'Transaction not found',
                        'http_status' => 404,
                    ],
                ];
            }
        };

        $this->app->instance(PaymentStatusInquiryService::class, $inquiry);

        $this->artisan('payment:check-pending-inquiry')->assertSuccessful();

        $temp->refresh();
        $this->assertSame(TempData::STATUS_PENDING, (string) $temp->status);
        $this->assertNull($temp->resolution_reason);
    }

    public function test_throttle_skips_second_inquiry_for_same_merchant_transaction(): void
    {
        Queue::fake();

        $this->createOldPendingTemp('tx-inquiry-throttle-1');

        $state = new \stdClass;
        $state->inquireCalls = 0;

        $inquiry = new class($state) implements PaymentStatusInquiryService
        {
            public function __construct(private \stdClass $s) {}

            public function isImplemented(): bool
            {
                return true;
            }

            public function inquire(string $merchantTransactionId): array
            {
                $this->s->inquireCalls++;

                return ['outcome' => null, 'raw' => []];
            }
        };

        $this->app->instance(PaymentStatusInquiryService::class, $inquiry);

        $this->artisan('payment:check-pending-inquiry')->assertSuccessful();
        $this->artisan('payment:check-pending-inquiry')->assertSuccessful();

        $this->assertSame(1, $state->inquireCalls);
    }

    private function createOldPendingTemp(string $merchantTx): TempData
    {
        $drop = ListOfTimeSlot::query()->create(['time_slot' => '10:00 - 10:20']);
        $pick = ListOfTimeSlot::query()->create(['time_slot' => '11:00 - 11:20']);
        $vt = VehicleType::query()->create(['price' => 5]);
        foreach (['en', 'cg'] as $locale) {
            VehicleTypeTranslation::query()->create([
                'vehicle_type_id' => $vt->id,
                'locale' => $locale,
                'name' => 'Car',
                'description' => null,
            ]);
        }

        $temp = TempData::query()->create([
            'merchant_transaction_id' => $merchantTx,
            'drop_off_time_slot_id' => $drop->id,
            'pick_up_time_slot_id' => $pick->id,
            'reservation_date' => now()->addDay()->toDateString(),
            'user_name' => 'Test',
            'country' => 'ME',
            'license_plate' => 'TEST123',
            'vehicle_type_id' => $vt->id,
            'email' => 't@example.com',
            'status' => TempData::STATUS_PENDING,
        ]);
        $temp->forceFill(['created_at' => now()->subHours(2)])->saveQuietly();

        return $temp->fresh();
    }
}
