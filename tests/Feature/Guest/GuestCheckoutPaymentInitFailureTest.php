<?php

namespace Tests\Feature\Guest;

use App\Contracts\PaymentService;
use App\Contracts\PaymentSessionResult;
use App\Models\AdminAlert;
use App\Models\DailyParkingData;
use App\Models\ListOfTimeSlot;
use App\Models\Reservation;
use App\Models\TempData;
use App\Models\User;
use App\Models\Vehicle;
use App\Models\VehicleType;
use App\Models\VehicleTypeTranslation;
use App\Services\Payment\CheckoutPaymentInitAlertService;
use App\Services\Payment\PaymentInitFailureService;
use App\Services\Reservation\DuplicateReservationAttemptService;
use App\Support\ReservationKind;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Log\Events\MessageLogged;
use Illuminate\Support\Facades\Log;
use Mockery;
use Tests\TestCase;

final class GuestCheckoutPaymentInitFailureTest extends TestCase
{
    use RefreshDatabase;

    /** @var list<MessageLogged> */
    private array $logEvents = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->logEvents = [];
        Log::listen(function (MessageLogged $event): void {
            $this->logEvents[] = $event;
        });
    }

    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }

    /** @param  array<string, mixed>  $contextSubset */
    private function assertLogEvent(string $level, string $message, array $contextSubset = []): void
    {
        $matched = false;
        foreach ($this->logEvents as $event) {
            if ($event->level !== $level || $event->message !== $message) {
                continue;
            }
            $ok = true;
            foreach ($contextSubset as $key => $value) {
                if (($event->context[$key] ?? null) !== $value) {
                    $ok = false;
                    break;
                }
            }
            if ($ok) {
                $matched = true;
                break;
            }
        }

        $this->assertTrue($matched, 'Expected log ['.$level.'] '.$message.' '.json_encode($contextSubset));
    }

    /**
     * @return array{drop: ListOfTimeSlot, pick: ListOfTimeSlot, date: string, vt: VehicleType}
     */
    private function seedCheckoutFixtures(): array
    {
        $drop = ListOfTimeSlot::query()->create(['time_slot' => '10:00 - 10:20']);
        $pick = ListOfTimeSlot::query()->create(['time_slot' => '11:00 - 11:20']);
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

        $vt = VehicleType::query()->create(['price' => 15.00]);

        return compact('drop', 'pick', 'date', 'vt');
    }

    /**
     * @return array<string, mixed>
     */
    private function guestPayload(array $fixtures, string $plate = 'KO555AA'): array
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

    private function mockCreateSessionFailure(): void
    {
        $mock = Mockery::mock(PaymentService::class);
        $mock->shouldReceive('createSession')
            ->once()
            ->andReturn(PaymentSessionResult::unavailable(
                'legacy message',
                503,
                'invalid_json',
            ));
        $this->app->instance(PaymentService::class, $mock);
    }

    public function test_guest_bankart_create_session_failure_redirects_with_clear_message(): void
    {
        config(['services.bank.driver' => 'bankart']);

        $fixtures = $this->seedCheckoutFixtures();
        $this->mockCreateSessionFailure();

        $this->from('/guest/reserve')
            ->post(route('checkout.store', [], false), $this->guestPayload($fixtures))
            ->assertRedirect(route('guest.reserve', [], false))
            ->assertSessionHas(
                'error',
                fn (string $error) => str_contains(strtolower($error), 'payment window cannot be opened')
                    && ! str_contains(strtolower($error), 'problem processing the payment'),
            );
    }

    public function test_guest_create_session_failure_cancels_temp_data_and_releases_pending(): void
    {
        config(['services.bank.driver' => 'bankart']);

        $fixtures = $this->seedCheckoutFixtures();
        $this->mockCreateSessionFailure();

        $this->from('/guest/reserve')
            ->post(route('checkout.store', [], false), $this->guestPayload($fixtures));

        $temp = TempData::query()->firstOrFail();
        $this->assertSame(TempData::STATUS_CANCELED, (string) $temp->status);
        $this->assertSame(PaymentInitFailureService::RESOLUTION_REASON, (string) $temp->resolution_reason);

        $this->assertSame(0, (int) DailyParkingData::query()
            ->whereDate('date', $fixtures['date'])
            ->where('time_slot_id', $fixtures['drop']->id)
            ->value('pending'));
    }

    public function test_guest_can_retry_immediately_after_create_session_failure(): void
    {
        config(['services.bank.driver' => 'bankart']);

        $fixtures = $this->seedCheckoutFixtures();
        $payload = $this->guestPayload($fixtures);

        $mock = Mockery::mock(PaymentService::class);
        $mock->shouldReceive('createSession')
            ->twice()
            ->andReturn(
                PaymentSessionResult::unavailable('x', 503, 'invalid_json'),
                PaymentSessionResult::ok('https://bank.example/pay'),
            );
        $this->app->instance(PaymentService::class, $mock);

        $this->from('/guest/reserve')
            ->post(route('checkout.store', [], false), $payload)
            ->assertRedirect(route('guest.reserve', [], false));

        $this->from('/guest/reserve')
            ->post(route('checkout.store', [], false), $payload)
            ->assertRedirect('https://bank.example/pay');
    }

    public function test_structured_logs_contain_checkout_context_without_secrets(): void
    {
        config(['services.bank.driver' => 'bankart']);

        $fixtures = $this->seedCheckoutFixtures();
        $this->mockCreateSessionFailure();

        $this->from('/guest/reserve')
            ->post(route('checkout.store', [], false), $this->guestPayload($fixtures));

        $this->assertLogEvent('info', 'checkout_started', [
            'guest' => true,
            'email' => 'guest@example.com',
            'license_plate' => 'KO555AA',
        ]);

        $temp = TempData::query()->firstOrFail();
        $this->assertLogEvent('info', 'checkout_temp_data_created', [
            'merchant_transaction_id' => $temp->merchant_transaction_id,
            'temp_data_id' => $temp->id,
            'email' => 'guest@example.com',
            'license_plate' => 'KO555AA',
        ]);

        $this->assertLogEvent('warning', 'checkout_bankart_create_session_failed', [
            'failure_reason' => 'invalid_json',
        ]);

        foreach ($this->logEvents as $event) {
            $encoded = json_encode($event->context ?? []);
            $this->assertIsString($encoded);
            $this->assertStringNotContainsString('password', strtolower($encoded));
        }
    }

    public function test_create_session_failure_creates_deduped_admin_alert(): void
    {
        config(['services.bank.driver' => 'bankart']);

        $fixtures = $this->seedCheckoutFixtures();

        $mock = Mockery::mock(PaymentService::class);
        $mock->shouldReceive('createSession')
            ->twice()
            ->andReturn(PaymentSessionResult::unavailable('x', 503, 'invalid_json'));
        $this->app->instance(PaymentService::class, $mock);

        $this->from('/guest/reserve')
            ->post(route('checkout.store', [], false), $this->guestPayload($fixtures));

        $this->assertSame(
            1,
            AdminAlert::query()->where('type', CheckoutPaymentInitAlertService::TYPE)->count(),
        );

        $this->from('/guest/reserve')
            ->post(route('checkout.store', [], false), $this->guestPayload($fixtures, 'KO556BB'));

        $this->assertSame(
            1,
            AdminAlert::query()->where('type', CheckoutPaymentInitAlertService::TYPE)->count(),
        );
    }

    public function test_validation_failure_does_not_show_generic_payment_error(): void
    {
        $fixtures = $this->seedCheckoutFixtures();
        $payload = $this->guestPayload($fixtures);
        unset($payload['email']);

        $this->from('/guest/reserve')
            ->post(route('checkout.store', [], false), $payload)
            ->assertRedirect(route('guest.reserve', [], false))
            ->assertSessionHasErrors('email')
            ->assertSessionMissing('error');

        $this->assertLogEvent('warning', 'checkout_validation_failed');
    }

    public function test_guest_lower_category_block_shows_category_message_not_payment_error(): void
    {
        $fixtures = $this->seedCheckoutFixtures();
        $low = VehicleType::query()->create(['price' => 10.00]);
        $high = VehicleType::query()->create(['price' => 40.00]);
        foreach ([[$low, 'Niža'], [$high, 'Viša']] as [$type, $name]) {
            VehicleTypeTranslation::query()->create([
                'vehicle_type_id' => $type->id,
                'locale' => 'en',
                'name' => $name,
                'description' => null,
            ]);
        }

        Reservation::query()->create([
            'user_id' => null,
            'merchant_transaction_id' => 'mt-hist-guest-pay',
            'reservation_kind' => ReservationKind::TIME_SLOTS,
            'drop_off_time_slot_id' => $fixtures['drop']->id,
            'pick_up_time_slot_id' => $fixtures['pick']->id,
            'reservation_date' => '2026-06-01',
            'user_name' => 'Hist',
            'country' => 'ME',
            'license_plate' => DuplicateReservationAttemptService::normalizeLicensePlate('KO555AA'),
            'vehicle_type_id' => $high->id,
            'email' => 'hist@example.com',
            'status' => 'paid',
            'invoice_amount' => 40.00,
        ]);

        $payload = $this->guestPayload($fixtures);
        $payload['vehicle_type_id'] = $low->id;

        $response = $this->from('/guest/reserve')
            ->post(route('checkout.store', [], false), $payload)
            ->assertRedirect(route('guest.reserve', [], false))
            ->assertSessionHas('guest_lower_category_block')
            ->assertSessionHas('error');

        $error = strtolower((string) $response->getSession()->get('error'));
        $this->assertStringNotContainsString('payment window cannot be opened', $error);
        $this->assertStringContainsString('license plate', $error);
        $this->assertNull(TempData::query()->first());
    }

    public function test_agency_panel_card_checkout_still_redirects_to_bank_on_success(): void
    {
        config(['services.bank.driver' => 'bankart']);

        $fixtures = $this->seedCheckoutFixtures();
        $user = User::factory()->create(['lang' => 'en', 'email_verified_at' => now(), 'country' => 'ME']);
        $vehicle = Vehicle::query()->create([
            'user_id' => $user->id,
            'license_plate' => 'AG123',
            'vehicle_type_id' => $fixtures['vt']->id,
        ]);

        $mock = Mockery::mock(PaymentService::class);
        $mock->shouldReceive('createSession')
            ->once()
            ->andReturn(PaymentSessionResult::ok('https://bank.example/agency-pay'));
        $this->app->instance(PaymentService::class, $mock);

        $this->actingAs($user)
            ->from('/panel/reservations')
            ->post(route('checkout.store', [], false), [
                'auth_panel_booking' => 1,
                'payment_method' => 'card',
                'merchant_transaction_id' => 'mt-agency-1',
                'reservation_date' => $fixtures['date'],
                'drop_off_time_slot_id' => $fixtures['drop']->id,
                'pick_up_time_slot_id' => $fixtures['pick']->id,
                'vehicle_id' => $vehicle->id,
                'accept_terms' => 1,
                'accept_privacy' => 1,
            ])
            ->assertRedirect('https://bank.example/agency-pay');
    }
}
