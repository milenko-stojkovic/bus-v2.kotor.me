<?php

namespace Tests\Feature\Payment;

use App\Jobs\PaymentCallbackJob;
use App\Jobs\ProcessReservationAfterPaymentJob;
use App\Jobs\SendInvoiceEmailJob;
use App\Models\ListOfTimeSlot;
use App\Models\Reservation;
use App\Models\TempData;
use App\Models\VehicleType;
use App\Services\AdminFiscalizationAlertService;
use App\Services\Payment\PaymentSuccessHandler;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Queue;
use Mockery;
use Tests\TestCase;

class PaymentCallbackDuplicateTerminalTest extends TestCase
{
    use RefreshDatabase;

    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }

    private function useFakeBankDriver(): void
    {
        Config::set('services.bank.driver', 'fake');
    }

    private function postCallback(string $rawBody): \Illuminate\Testing\TestResponse
    {
        return $this->call('POST', '/api/payment/callback', [], [], [], [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_ACCEPT' => 'application/json',
        ], $rawBody);
    }

    private function assertBankartAck(\Illuminate\Testing\TestResponse $response): void
    {
        $response->assertOk();
        $this->assertSame('OK', $response->getContent());
        $this->assertStringContainsString('text/plain', (string) $response->headers->get('Content-Type'));
    }

    /**
     * @return array{temp: TempData, reservation: Reservation}
     */
    private function seedProcessedPayment(string $mtid): array
    {
        $slot = ListOfTimeSlot::query()->create(['time_slot' => '08:00 - 08:20']);
        $vt = VehicleType::query()->create(['price' => 15]);

        $temp = TempData::query()->create([
            'merchant_transaction_id' => $mtid,
            'retry_token' => 'rt-'.$mtid,
            'drop_off_time_slot_id' => $slot->id,
            'pick_up_time_slot_id' => $slot->id,
            'reservation_date' => now()->addDay()->toDateString(),
            'user_name' => 'Paid Guest',
            'country' => 'ME',
            'license_plate' => 'KO1234',
            'vehicle_type_id' => $vt->id,
            'invoice_amount_snapshot' => '15.00',
            'email' => 'paid@example.com',
            'status' => TempData::STATUS_PROCESSED,
        ]);

        $reservation = Reservation::query()->create([
            'merchant_transaction_id' => $mtid,
            'drop_off_time_slot_id' => $slot->id,
            'pick_up_time_slot_id' => $slot->id,
            'reservation_date' => $temp->reservation_date,
            'user_name' => $temp->user_name,
            'country' => $temp->country,
            'license_plate' => $temp->license_plate,
            'vehicle_type_id' => $vt->id,
            'email' => $temp->email,
            'status' => 'paid',
            'invoice_amount' => '15.00',
            'email_sent' => Reservation::EMAIL_SENT,
        ]);

        return ['temp' => $temp->fresh(), 'reservation' => $reservation];
    }

    private function seedPendingPayment(string $mtid): TempData
    {
        $slot = ListOfTimeSlot::query()->create(['time_slot' => '09:00 - 09:20']);
        $vt = VehicleType::query()->create(['price' => 10]);

        return TempData::query()->create([
            'merchant_transaction_id' => $mtid,
            'retry_token' => 'rt-pending-'.$mtid,
            'drop_off_time_slot_id' => $slot->id,
            'pick_up_time_slot_id' => $slot->id,
            'reservation_date' => now()->addDay()->toDateString(),
            'user_name' => 'Pending Guest',
            'country' => 'ME',
            'license_plate' => 'KOPEND1',
            'vehicle_type_id' => $vt->id,
            'invoice_amount_snapshot' => '10.00',
            'email' => 'pending@example.com',
            'status' => TempData::STATUS_PENDING,
        ]);
    }

    public function test_first_success_callback_for_pending_dispatches_job(): void
    {
        Queue::fake();
        $this->useFakeBankDriver();
        $this->seedPendingPayment('mt-dup-first');

        $rawBody = json_encode([
            'merchant_transaction_id' => 'mt-dup-first',
            'status' => 'success',
        ], JSON_THROW_ON_ERROR);

        $this->assertBankartAck($this->postCallback($rawBody));

        Queue::assertPushed(PaymentCallbackJob::class, function (PaymentCallbackJob $job): bool {
            return ($job->payload['merchant_transaction_id'] ?? null) === 'mt-dup-first';
        });
    }

    public function test_first_success_callback_creates_reservation_when_job_runs(): void
    {
        $this->useFakeBankDriver();
        $temp = $this->seedPendingPayment('mt-dup-create');

        $created = app(PaymentSuccessHandler::class)->handle($temp, ['result' => 'OK'], true, true);
        $this->assertTrue($created);
        $this->assertSame(1, Reservation::query()->where('merchant_transaction_id', 'mt-dup-create')->count());
    }

    public function test_repeated_success_for_processed_temp_returns_200_ok_ack(): void
    {
        Queue::fake();
        $this->useFakeBankDriver();
        $this->seedProcessedPayment('mt-dup-repeat');

        $rawBody = json_encode([
            'merchant_transaction_id' => 'mt-dup-repeat',
            'result' => 'OK',
            'merchantTransactionId' => 'mt-dup-repeat',
        ], JSON_THROW_ON_ERROR);

        $this->assertBankartAck($this->postCallback($rawBody));
    }

    public function test_repeated_success_for_processed_temp_does_not_dispatch_job(): void
    {
        Queue::fake();
        $this->useFakeBankDriver();
        $this->seedProcessedPayment('mt-dup-no-job');

        $rawBody = json_encode([
            'merchant_transaction_id' => 'mt-dup-no-job',
            'status' => 'success',
        ], JSON_THROW_ON_ERROR);

        $this->assertBankartAck($this->postCallback($rawBody));

        Queue::assertNothingPushed();
    }

    public function test_repeated_success_does_not_create_duplicate_reservation(): void
    {
        Queue::fake();
        $this->useFakeBankDriver();
        $seed = $this->seedProcessedPayment('mt-dup-no-res');

        $rawBody = json_encode([
            'merchant_transaction_id' => 'mt-dup-no-res',
            'status' => 'success',
        ], JSON_THROW_ON_ERROR);

        $this->assertBankartAck($this->postCallback($rawBody));

        $this->assertSame(1, Reservation::query()->where('merchant_transaction_id', 'mt-dup-no-res')->count());
        $this->assertSame((int) $seed['reservation']->id, (int) Reservation::query()->where('merchant_transaction_id', 'mt-dup-no-res')->value('id'));
    }

    public function test_repeated_success_does_not_dispatch_post_payment_or_email_jobs(): void
    {
        Queue::fake();
        $this->useFakeBankDriver();
        $this->seedProcessedPayment('mt-dup-no-email');

        $rawBody = json_encode([
            'merchant_transaction_id' => 'mt-dup-no-email',
            'status' => 'success',
        ], JSON_THROW_ON_ERROR);

        $this->assertBankartAck($this->postCallback($rawBody));

        Queue::assertNotPushed(ProcessReservationAfterPaymentJob::class);
        Queue::assertNotPushed(SendInvoiceEmailJob::class);
    }

    public function test_invalid_signature_still_rejected_before_duplicate_shortcut(): void
    {
        Queue::fake();
        Config::set('services.bank.driver', 'bankart');
        Config::set('services.bankart.shared_secret', 'secret-for-dup-test');
        $this->seedProcessedPayment('mt-dup-bad-sig');

        $rawBody = json_encode([
            'merchant_transaction_id' => 'mt-dup-bad-sig',
            'status' => 'success',
        ], JSON_THROW_ON_ERROR);

        $this->postCallback($rawBody)
            ->assertStatus(400)
            ->assertJsonPath('message', 'Invalid callback signature.');

        Queue::assertNothingPushed();
    }

    public function test_failed_callback_for_pending_still_dispatches_job(): void
    {
        Queue::fake();
        $this->useFakeBankDriver();
        $this->seedPendingPayment('mt-dup-failed');

        $rawBody = json_encode([
            'merchant_transaction_id' => 'mt-dup-failed',
            'status' => 'ERROR',
        ], JSON_THROW_ON_ERROR);

        $this->assertBankartAck($this->postCallback($rawBody));

        Queue::assertPushed(PaymentCallbackJob::class, function (PaymentCallbackJob $job): bool {
            return ($job->payload['merchant_transaction_id'] ?? null) === 'mt-dup-failed';
        });
    }

    public function test_success_on_canceled_temp_still_dispatches_job_for_late_flow(): void
    {
        Queue::fake();
        $this->useFakeBankDriver();

        $slot = ListOfTimeSlot::query()->create(['time_slot' => '10:00 - 10:20']);
        $vt = VehicleType::query()->create(['price' => 10]);
        TempData::query()->create([
            'merchant_transaction_id' => 'mt-dup-canceled',
            'retry_token' => 'rt-c',
            'drop_off_time_slot_id' => $slot->id,
            'pick_up_time_slot_id' => $slot->id,
            'reservation_date' => now()->addDay()->toDateString(),
            'user_name' => 'X',
            'country' => 'ME',
            'license_plate' => 'KOCAN1',
            'vehicle_type_id' => $vt->id,
            'email' => 'canceled@example.com',
            'status' => TempData::STATUS_CANCELED,
        ]);

        $rawBody = json_encode([
            'merchant_transaction_id' => 'mt-dup-canceled',
            'status' => 'success',
        ], JSON_THROW_ON_ERROR);

        $this->assertBankartAck($this->postCallback($rawBody));

        Queue::assertPushed(PaymentCallbackJob::class);
    }

    public function test_success_on_canceled_temp_job_still_notifies_admin(): void
    {
        $this->useFakeBankDriver();

        $slot = ListOfTimeSlot::query()->create(['time_slot' => '11:00 - 11:20']);
        $vt = VehicleType::query()->create(['price' => 10]);
        $temp = TempData::query()->create([
            'merchant_transaction_id' => 'mt-dup-canceled-job',
            'retry_token' => 'rt-cj',
            'drop_off_time_slot_id' => $slot->id,
            'pick_up_time_slot_id' => $slot->id,
            'reservation_date' => now()->addDay()->toDateString(),
            'user_name' => 'X',
            'country' => 'ME',
            'license_plate' => 'KOCAN2',
            'vehicle_type_id' => $vt->id,
            'email' => 'canceled2@example.com',
            'status' => TempData::STATUS_CANCELED,
        ]);

        $mock = Mockery::mock(AdminFiscalizationAlertService::class);
        $mock->shouldReceive('notifyPaymentSuccessAfterCanceled')->once();
        $this->app->instance(AdminFiscalizationAlertService::class, $mock);

        (new PaymentCallbackJob(
            ['merchant_transaction_id' => $temp->merchant_transaction_id, 'status' => 'success'],
            ['result' => 'OK'],
        ))->handle();

        $this->assertSame(TempData::STATUS_CANCELED, $temp->fresh()->status);
    }

    public function test_processed_without_reservation_still_dispatches_job(): void
    {
        Queue::fake();
        $this->useFakeBankDriver();

        $slot = ListOfTimeSlot::query()->create(['time_slot' => '12:00 - 12:20']);
        $vt = VehicleType::query()->create(['price' => 10]);
        TempData::query()->create([
            'merchant_transaction_id' => 'mt-dup-no-res-yet',
            'retry_token' => 'rt-nr',
            'drop_off_time_slot_id' => $slot->id,
            'pick_up_time_slot_id' => $slot->id,
            'reservation_date' => now()->addDay()->toDateString(),
            'user_name' => 'X',
            'country' => 'ME',
            'license_plate' => 'KONR1',
            'vehicle_type_id' => $vt->id,
            'email' => 'nr@example.com',
            'status' => TempData::STATUS_PROCESSED,
        ]);

        $rawBody = json_encode([
            'merchant_transaction_id' => 'mt-dup-no-res-yet',
            'status' => 'success',
        ], JSON_THROW_ON_ERROR);

        $this->assertBankartAck($this->postCallback($rawBody));

        Queue::assertPushed(PaymentCallbackJob::class);
    }

    public function test_duplicate_terminal_callback_logs_single_ack_event(): void
    {
        $this->useFakeBankDriver();
        $seed = $this->seedProcessedPayment('mt-dup-log');

        Log::shouldReceive('channel')
            ->with('payments')
            ->andReturnSelf();

        Log::shouldReceive('info')
            ->once()
            ->withArgs(function (string $message, array $context) use ($seed): bool {
                return $message === 'payment_callback_duplicate_terminal_acknowledged'
                    && ($context['merchant_transaction_id'] ?? '') === 'mt-dup-log'
                    && (int) ($context['temp_data_id'] ?? 0) === (int) $seed['temp']->id
                    && (int) ($context['reservation_id'] ?? 0) === (int) $seed['reservation']->id
                    && ($context['temp_status'] ?? '') === TempData::STATUS_PROCESSED
                    && ($context['callback_status'] ?? '') === 'success';
            });

        Log::shouldReceive('info')->with('Payment callback received', Mockery::any());

        $rawBody = json_encode([
            'merchant_transaction_id' => 'mt-dup-log',
            'status' => 'success',
        ], JSON_THROW_ON_ERROR);

        $this->assertBankartAck($this->postCallback($rawBody));
    }
}
