<?php

namespace Tests\Feature\Checkout;

use App\Contracts\PaymentService;
use App\Contracts\PaymentSessionResult;
use App\Jobs\PaymentCallbackJob;
use App\Models\DailyParkingData;
use App\Models\ListOfTimeSlot;
use App\Models\Reservation;
use App\Models\TempData;
use App\Models\VehicleType;
use App\Services\Payment\PaymentInitFailureService;
use App\Services\Payment\PendingBankartRedirectService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Mockery;
use Tests\TestCase;

final class CheckoutExistingPendingRedirectTest extends TestCase
{
    use RefreshDatabase;

    private const PAY_URL = 'https://bank.example/pay/7ad4f563';

    private const EXISTING_SESSION_MSG_EN = 'The payment window is already open or a session was already created.';

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
        $drop = ListOfTimeSlot::query()->create(['time_slot' => '09:00 - 09:20']);
        $pick = ListOfTimeSlot::query()->create(['time_slot' => '11:40 - 12:00']);
        $date = now()->addDays(3)->toDateString();

        foreach ([$drop->id, $pick->id] as $slotId) {
            DailyParkingData::query()->create([
                'date' => $date,
                'time_slot_id' => $slotId,
                'capacity' => 5,
                'reserved' => 0,
                'pending' => 0,
                'is_blocked' => false,
            ]);
        }

        $vt = VehicleType::query()->create(['price' => 50.00]);

        return compact('drop', 'pick', 'date', 'vt');
    }

    /**
     * @return array<string, mixed>
     */
    private function guestCheckoutPayload(array $fixtures, string $email = 'agency@example.com', string $plate = 'KOBP210'): array
    {
        return [
            'reservation_date' => $fixtures['date'],
            'drop_off_time_slot_id' => $fixtures['drop']->id,
            'pick_up_time_slot_id' => $fixtures['pick']->id,
            'vehicle_type_id' => $fixtures['vt']->id,
            'name' => 'Agency User',
            'country' => 'ME',
            'license_plate' => $plate,
            'email' => $email,
            'accept_terms' => 1,
            'accept_privacy' => 1,
        ];
    }

    public function test_first_create_session_success_stores_redirect_url(): void
    {
        config(['services.bank.driver' => 'bankart']);

        $fixtures = $this->seedCheckoutFixtures();
        $payload = $this->guestCheckoutPayload($fixtures, 'guest-store-url@example.com', 'KOURL01');

        $mock = Mockery::mock(PaymentService::class);
        $mock->shouldReceive('createSession')->once()->andReturn(PaymentSessionResult::ok(self::PAY_URL));
        $this->app->instance(PaymentService::class, $mock);

        $this->from('/guest/reserve')
            ->post(route('checkout.store', [], false), $payload)
            ->assertRedirect(self::PAY_URL);

        $temp = TempData::query()->firstOrFail();
        $this->assertSame(self::PAY_URL, (string) $temp->payment_redirect_url);
        $this->assertSame(TempData::STATUS_PENDING, (string) $temp->status);
    }

    public function test_second_click_during_pending_reuses_stored_redirect_without_create_session(): void
    {
        config(['services.bank.driver' => 'bankart']);

        $fixtures = $this->seedCheckoutFixtures();
        $payload = $this->guestCheckoutPayload($fixtures, 'retry-guest@example.com', 'KOBP210');

        $mock = Mockery::mock(PaymentService::class);
        $mock->shouldReceive('createSession')->once()->andReturn(PaymentSessionResult::ok(self::PAY_URL));
        $this->app->instance(PaymentService::class, $mock);

        $this->from('/guest/reserve')
            ->post(route('checkout.store', [], false), $payload)
            ->assertRedirect(self::PAY_URL);

        $mock2 = Mockery::mock(PaymentService::class);
        $mock2->shouldNotReceive('createSession');
        $this->app->instance(PaymentService::class, $mock2);

        $this->from('/guest/reserve')
            ->post(route('checkout.store', [], false), $payload)
            ->assertRedirect(self::PAY_URL);

        $this->assertSame(1, TempData::query()->count());
        $this->assertSame(TempData::STATUS_PENDING, (string) TempData::query()->firstOrFail()->status);
    }

    public function test_bankart_3004_on_existing_pending_does_not_cancel_temp_data(): void
    {
        config(['services.bank.driver' => 'bankart']);

        $fixtures = $this->seedCheckoutFixtures();
        $email = 'dup3004@example.com';

        TempData::query()->create([
            'merchant_transaction_id' => '7ad4f563-5a06-43a0-afcd-77e19cfd1a2f',
            'retry_token' => '7876d2d3-ce39-4a29-a5df-32e81eb5c36f',
            'reservation_kind' => 'time_slots',
            'drop_off_time_slot_id' => $fixtures['drop']->id,
            'pick_up_time_slot_id' => $fixtures['pick']->id,
            'reservation_date' => $fixtures['date'],
            'user_name' => 'Guest User',
            'country' => 'ME',
            'license_plate' => 'KOBP210',
            'vehicle_type_id' => $fixtures['vt']->id,
            'email' => $email,
            'status' => TempData::STATUS_PENDING,
        ]);

        $mock = Mockery::mock(PaymentService::class);
        $mock->shouldReceive('createSession')
            ->once()
            ->andReturn(PaymentSessionResult::unavailable(
                'Bad Request',
                400,
                'gateway_rejected',
                PendingBankartRedirectService::DUPLICATE_TRANSACTION_ERROR_CODE,
            ));
        $this->app->instance(PaymentService::class, $mock);

        $this->from('/guest/reserve')
            ->post(route('checkout.store', [], false), $this->guestCheckoutPayload($fixtures, $email, 'KOBP210'))
            ->assertRedirect(route('guest.reserve', [], false))
            ->assertSessionHas('error');

        $message = (string) session('error');
        $this->assertStringContainsString(self::EXISTING_SESSION_MSG_EN, $message);

        $temp = TempData::query()->firstOrFail();
        $this->assertSame(TempData::STATUS_PENDING, (string) $temp->status);
        $this->assertNull($temp->resolution_reason);
    }

    public function test_existing_pending_without_redirect_url_shows_friendly_message(): void
    {
        config(['services.bank.driver' => 'bankart']);

        $fixtures = $this->seedCheckoutFixtures();
        $email = 'no-url@example.com';

        TempData::query()->create([
            'merchant_transaction_id' => 'mtid-no-url',
            'retry_token' => 'retry-no-url',
            'reservation_kind' => 'time_slots',
            'drop_off_time_slot_id' => $fixtures['drop']->id,
            'pick_up_time_slot_id' => $fixtures['pick']->id,
            'reservation_date' => $fixtures['date'],
            'user_name' => 'Guest User',
            'country' => 'ME',
            'license_plate' => 'KOBP210',
            'vehicle_type_id' => $fixtures['vt']->id,
            'email' => $email,
            'status' => TempData::STATUS_PENDING,
            'payment_redirect_url' => null,
        ]);

        $mock = Mockery::mock(PaymentService::class);
        $mock->shouldReceive('createSession')
            ->once()
            ->andReturn(PaymentSessionResult::unavailable('Unavailable', 503, 'invalid_json'));
        $this->app->instance(PaymentService::class, $mock);

        $this->from('/guest/reserve')
            ->post(route('checkout.store', [], false), $this->guestCheckoutPayload($fixtures, $email, 'KOBP210'))
            ->assertRedirect(route('guest.reserve', [], false))
            ->assertSessionHas('error');

        $this->assertStringContainsString(self::EXISTING_SESSION_MSG_EN, (string) session('error'));

        $this->assertSame(TempData::STATUS_PENDING, (string) TempData::query()->firstOrFail()->status);
    }

    public function test_failed_callback_cancels_pending_temp_data(): void
    {
        $fixtures = $this->seedCheckoutFixtures();
        $temp = TempData::query()->create([
            'merchant_transaction_id' => 'mtid-failed-callback',
            'retry_token' => 'retry-failed',
            'reservation_kind' => 'time_slots',
            'drop_off_time_slot_id' => $fixtures['drop']->id,
            'pick_up_time_slot_id' => $fixtures['pick']->id,
            'reservation_date' => $fixtures['date'],
            'user_name' => 'Guest',
            'country' => 'ME',
            'license_plate' => 'KOFAIL1',
            'vehicle_type_id' => $fixtures['vt']->id,
            'email' => 'fail@example.com',
            'status' => TempData::STATUS_PENDING,
            'payment_redirect_url' => self::PAY_URL,
        ]);

        (new PaymentCallbackJob(
            ['merchant_transaction_id' => $temp->merchant_transaction_id, 'status' => 'failed'],
            ['source' => 'test_failed_callback'],
        ))->handle();

        $temp->refresh();
        $this->assertSame(TempData::STATUS_CANCELED, (string) $temp->status);
        $this->assertSame(0, Reservation::query()->where('merchant_transaction_id', $temp->merchant_transaction_id)->count());
    }

    public function test_expire_pending_releases_lock(): void
    {
        config(['reservations.pending_expire_minutes' => 1]);

        $fixtures = $this->seedCheckoutFixtures();
        $temp = TempData::query()->create([
            'merchant_transaction_id' => 'mtid-expire',
            'retry_token' => 'retry-expire',
            'reservation_kind' => 'time_slots',
            'drop_off_time_slot_id' => $fixtures['drop']->id,
            'pick_up_time_slot_id' => $fixtures['pick']->id,
            'reservation_date' => $fixtures['date'],
            'user_name' => 'Guest',
            'country' => 'ME',
            'license_plate' => 'KOEXP2',
            'vehicle_type_id' => $fixtures['vt']->id,
            'email' => 'expire@example.com',
            'status' => TempData::STATUS_PENDING,
            'payment_redirect_url' => self::PAY_URL,
        ]);
        $temp->forceFill([
            'created_at' => now()->subMinutes(10),
            'updated_at' => now()->subMinutes(10),
        ])->save();

        foreach ([$fixtures['drop']->id, $fixtures['pick']->id] as $slotId) {
            DailyParkingData::query()
                ->whereDate('date', $fixtures['date'])
                ->where('time_slot_id', $slotId)
                ->update(['pending' => 1]);
        }

        Artisan::call('reservations:expire-pending');

        $temp->refresh();
        $this->assertSame(TempData::STATUS_EXPIRED, (string) $temp->status);

        $dropPending = (int) DailyParkingData::query()
            ->whereDate('date', $fixtures['date'])
            ->where('time_slot_id', $fixtures['drop']->id)
            ->value('pending');
        $this->assertSame(0, $dropPending);
    }

    public function test_new_checkout_after_canceled_creates_new_mtid_and_session(): void
    {
        config(['services.bank.driver' => 'bankart']);

        $fixtures = $this->seedCheckoutFixtures();
        $payload = $this->guestCheckoutPayload($fixtures, 'retry-after-cancel@example.com', 'KONEW01');

        $mock = Mockery::mock(PaymentService::class);
        $mock->shouldReceive('createSession')
            ->twice()
            ->andReturn(
                PaymentSessionResult::unavailable('Unavailable', 503, 'invalid_json'),
                PaymentSessionResult::ok('https://bank.example/pay-new'),
            );
        $this->app->instance(PaymentService::class, $mock);

        $this->from('/guest/reserve')
            ->post(route('checkout.store', [], false), $payload)
            ->assertRedirect(route('guest.reserve', [], false));

        $this->assertSame(TempData::STATUS_CANCELED, (string) TempData::query()->orderBy('id')->firstOrFail()->status);

        $this->from('/guest/reserve')
            ->post(route('checkout.store', [], false), $payload)
            ->assertRedirect('https://bank.example/pay-new');

        $this->assertSame(2, TempData::query()->count());
        $pending = TempData::query()->where('status', TempData::STATUS_PENDING)->firstOrFail();
        $this->assertNotSame('mtid-expire', (string) $pending->merchant_transaction_id);
        $this->assertSame('https://bank.example/pay-new', (string) $pending->payment_redirect_url);
    }

    public function test_first_create_session_failure_still_cancels_temp_data(): void
    {
        config(['services.bank.driver' => 'bankart']);

        $fixtures = $this->seedCheckoutFixtures();
        $payload = $this->guestCheckoutPayload($fixtures, 'first-fail@example.com', 'KOFIRST');

        $mock = Mockery::mock(PaymentService::class);
        $mock->shouldReceive('createSession')
            ->once()
            ->andReturn(PaymentSessionResult::unavailable('Unavailable', 503, 'invalid_json'));
        $this->app->instance(PaymentService::class, $mock);

        $this->from('/guest/reserve')
            ->post(route('checkout.store', [], false), $payload)
            ->assertRedirect(route('guest.reserve', [], false));

        $temp = TempData::query()->firstOrFail();
        $this->assertSame(TempData::STATUS_CANCELED, (string) $temp->status);
        $this->assertSame(PaymentInitFailureService::RESOLUTION_REASON, (string) $temp->resolution_reason);
        $this->assertNull($temp->payment_redirect_url);
    }
}
