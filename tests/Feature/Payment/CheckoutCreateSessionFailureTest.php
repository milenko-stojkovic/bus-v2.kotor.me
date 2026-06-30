<?php

namespace Tests\Feature\Payment;

use App\Contracts\PaymentService;
use App\Contracts\PaymentSessionResult;
use App\Jobs\PaymentCallbackJob;
use App\Models\DailyParkingData;
use App\Models\ListOfTimeSlot;
use App\Models\Reservation;
use App\Models\TempData;
use App\Models\VehicleType;
use App\Services\Payment\PaymentInitFailureService;
use App\Services\Payment\RealPaymentProvider;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Mockery;
use Tests\TestCase;

class CheckoutCreateSessionFailureTest extends TestCase
{
    use RefreshDatabase;

    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }

    /**
     * @return array{drop: ListOfTimeSlot, pick: ListOfTimeSlot, date: string, vt: VehicleType}
     */
    private function seedCheckoutFixtures(): array
    {
        $drop = ListOfTimeSlot::query()->create(['time_slot' => '10:00 - 10:20']);
        $pick = ListOfTimeSlot::query()->create(['time_slot' => '11:00 - 11:20']);
        $date = now()->addDays(3)->toDateString();

        DailyParkingData::query()->create([
            'date' => $date,
            'time_slot_id' => $drop->id,
            'capacity' => 5,
            'reserved' => 0,
            'pending' => 0,
            'is_blocked' => false,
        ]);
        DailyParkingData::query()->create([
            'date' => $date,
            'time_slot_id' => $pick->id,
            'capacity' => 5,
            'reserved' => 0,
            'pending' => 0,
            'is_blocked' => false,
        ]);

        $vt = VehicleType::query()->create(['price' => 15.00]);

        return compact('drop', 'pick', 'date', 'vt');
    }

    /**
     * @return array<string, mixed>
     */
    private function guestCheckoutPayload(array $fixtures, string $plate = 'KO555AA'): array
    {
        return [
            'reservation_date' => $fixtures['date'],
            'drop_off_time_slot_id' => $fixtures['drop']->id,
            'pick_up_time_slot_id' => $fixtures['pick']->id,
            'vehicle_type_id' => $fixtures['vt']->id,
            'name' => 'Guest User',
            'country' => 'ME',
            'license_plate' => $plate,
            'email' => 'guest@example.com',
            'accept_terms' => 1,
            'accept_privacy' => 1,
        ];
    }

    private function mockCreateSessionFailure(int $httpStatus = 503, string $reason = 'invalid_json'): void
    {
        $mock = Mockery::mock(PaymentService::class);
        $mock->shouldReceive('createSession')
            ->once()
            ->andReturn(PaymentSessionResult::unavailable(
                'Payment temporarily unavailable.',
                $httpStatus,
                $reason,
            ));
        $this->app->instance(PaymentService::class, $mock);
    }

    public function test_create_session_503_marks_temp_data_as_canceled_non_blocking(): void
    {
        config(['services.bank.driver' => 'bankart']);

        $fixtures = $this->seedCheckoutFixtures();
        $this->mockCreateSessionFailure(503, 'invalid_json');

        $response = $this->from('/guest/reserve')
            ->post(route('checkout.store', [], false), $this->guestCheckoutPayload($fixtures))
            ->assertRedirect(route('guest.reserve', [], false))
            ->assertSessionHas('error');

        $temp = TempData::query()->firstOrFail();
        $this->assertSame(TempData::STATUS_CANCELED, (string) $temp->status);
        $this->assertSame(PaymentInitFailureService::RESOLUTION_REASON, (string) $temp->resolution_reason);
    }

    public function test_slot_pending_counters_are_released_after_create_session_failure(): void
    {
        config(['services.bank.driver' => 'bankart']);

        $fixtures = $this->seedCheckoutFixtures();
        $this->mockCreateSessionFailure();

        $this->from('/guest/reserve')
            ->post(route('checkout.store', [], false), $this->guestCheckoutPayload($fixtures))
            ->assertRedirect(route('guest.reserve', [], false));

        $dropPending = (int) DailyParkingData::query()
            ->whereDate('date', $fixtures['date'])
            ->where('time_slot_id', $fixtures['drop']->id)
            ->value('pending');
        $pickPending = (int) DailyParkingData::query()
            ->where('date', $fixtures['date'])
            ->where('time_slot_id', $fixtures['pick']->id)
            ->value('pending');

        $this->assertSame(0, $dropPending);
        $this->assertSame(0, $pickPending);
    }

    public function test_same_user_plate_slot_can_retry_immediately_after_create_session_failure(): void
    {
        config(['services.bank.driver' => 'bankart']);

        $fixtures = $this->seedCheckoutFixtures();
        $payload = $this->guestCheckoutPayload($fixtures);

        $mock = Mockery::mock(PaymentService::class);
        $mock->shouldReceive('createSession')
            ->twice()
            ->andReturn(
                PaymentSessionResult::unavailable('Payment temporarily unavailable.', 503, 'invalid_json'),
                PaymentSessionResult::ok('https://bank.example/pay'),
            );
        $this->app->instance(PaymentService::class, $mock);

        $this->from('/guest/reserve')
            ->post(route('checkout.store', [], false), $payload)
            ->assertRedirect(route('guest.reserve', [], false));

        $this->from('/guest/reserve')
            ->post(route('checkout.store', [], false), $payload)
            ->assertRedirect('https://bank.example/pay');

        $this->assertSame(2, TempData::query()->count());
        $this->assertSame(1, TempData::query()->where('status', TempData::STATUS_CANCELED)->count());
        $this->assertSame(1, TempData::query()->where('status', TempData::STATUS_PENDING)->count());
    }

    public function test_successful_create_session_keeps_pending_and_blocks_duplicate_while_pending(): void
    {
        config(['services.bank.driver' => 'bankart']);

        $fixtures = $this->seedCheckoutFixtures();
        $payload = $this->guestCheckoutPayload($fixtures);

        $mock = Mockery::mock(PaymentService::class);
        $mock->shouldReceive('createSession')
            ->once()
            ->andReturn(PaymentSessionResult::ok('https://bank.example/pay'));
        $this->app->instance(PaymentService::class, $mock);

        $this->from('/guest/reserve')
            ->post(route('checkout.store', [], false), $payload)
            ->assertRedirect('https://bank.example/pay');

        $temp = TempData::query()->firstOrFail();
        $this->assertSame(TempData::STATUS_PENDING, (string) $temp->status);

        $dropPending = (int) DailyParkingData::query()
            ->whereDate('date', $fixtures['date'])
            ->where('time_slot_id', $fixtures['drop']->id)
            ->value('pending');
        $this->assertSame(1, $dropPending);

        $mock2 = Mockery::mock(PaymentService::class);
        $mock2->shouldNotReceive('createSession');
        $this->app->instance(PaymentService::class, $mock2);

        $this->from('/guest/reserve')
            ->post(route('checkout.store', [], false), $payload)
            ->assertRedirect('https://bank.example/pay');

        $this->assertSame(1, TempData::query()->where('status', TempData::STATUS_PENDING)->count());
        $this->assertSame('https://bank.example/pay', (string) TempData::query()->firstOrFail()->payment_redirect_url);
    }

    public function test_late_success_callback_after_payment_init_failure_does_not_create_reservation(): void
    {
        $fixtures = $this->seedCheckoutFixtures();
        $temp = TempData::query()->create([
            'merchant_transaction_id' => 'mtid-late-after-init-fail',
            'drop_off_time_slot_id' => $fixtures['drop']->id,
            'pick_up_time_slot_id' => $fixtures['pick']->id,
            'reservation_date' => $fixtures['date'],
            'user_name' => 'Guest User',
            'country' => 'ME',
            'license_plate' => 'KO555AA',
            'vehicle_type_id' => $fixtures['vt']->id,
            'email' => 'guest@example.com',
            'status' => TempData::STATUS_CANCELED,
            'resolution_reason' => PaymentInitFailureService::RESOLUTION_REASON,
        ]);

        (new PaymentCallbackJob(
            ['merchant_transaction_id' => $temp->merchant_transaction_id, 'status' => 'success'],
            ['source' => 'test_late_after_init_fail'],
        ))->handle();

        $this->assertSame(TempData::STATUS_CANCELED, (string) $temp->fresh()->status);
        $this->assertSame(0, Reservation::query()->where('merchant_transaction_id', $temp->merchant_transaction_id)->count());
    }

    public function test_real_provider_invalid_json_503_returns_unavailable_with_reason(): void
    {
        config([
            'services.bankart' => [
                'api_url' => 'https://bankart.test',
                'api_key' => 'api-key-1',
                'username' => 'user',
                'password' => 'pass',
                'shared_secret' => '',
                'signature_enabled' => false,
                'send_customer' => false,
            ],
        ]);

        Http::fake([
            'https://bankart.test/*' => Http::response('<html>503</html>', 503),
        ]);

        $fixtures = $this->seedCheckoutFixtures();
        $temp = TempData::query()->create([
            'merchant_transaction_id' => 'mtid-real-503',
            'drop_off_time_slot_id' => $fixtures['drop']->id,
            'pick_up_time_slot_id' => $fixtures['pick']->id,
            'reservation_date' => $fixtures['date'],
            'user_name' => 'Guest User',
            'country' => 'ME',
            'license_plate' => 'KO555AA',
            'vehicle_type_id' => $fixtures['vt']->id,
            'email' => 'guest@example.com',
            'status' => TempData::STATUS_PENDING,
        ]);
        $temp->load('vehicleType');

        $result = app(RealPaymentProvider::class)->createSession($temp);

        $this->assertFalse($result->success);
        $this->assertSame(503, $result->httpStatus);
        $this->assertSame('invalid_json', $result->failureReason);
    }
}
