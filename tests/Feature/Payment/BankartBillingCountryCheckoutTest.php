<?php

namespace Tests\Feature\Payment;

use App\Contracts\PaymentService;
use App\Contracts\PaymentSessionResult;
use App\Models\AdminAlert;
use App\Models\AgencyAdvanceTopup;
use App\Models\DailyParkingData;
use App\Models\ListOfTimeSlot;
use App\Models\TempData;
use App\Models\User;
use App\Models\Vehicle;
use App\Models\VehicleType;
use App\Services\Payment\BankartBillingCountryAlertService;
use App\Services\Payment\RealPaymentProvider;
use App\Support\BankartBillingCountry;
use App\Support\ReservationKind;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Mockery;
use Tests\TestCase;

final class BankartBillingCountryCheckoutTest extends TestCase
{
    use RefreshDatabase;

    private const MSG_EN = 'Billing country is not configured correctly. Please contact support at bus@kotor.me.';

    private const MSG_CG = 'Država za naplatu nije ispravno podešena. Molimo kontaktirajte podršku na bus@kotor.me.';

    private const ADMIN_MSG_EN = 'If your country is not listed, contact the administrator at bus@kotor.me.';

    private const SELECT_MSG_CG = 'Ako Vaša država nije na spisku, kontaktirajte administratora na bus@kotor.me.';

    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }

    /**
     * @return array{drop: ListOfTimeSlot, pick: ListOfTimeSlot, date: string, vt: VehicleType}
     */
    private function seedTerminiFixtures(): array
    {
        $drop = ListOfTimeSlot::query()->create(['time_slot' => '10:00 - 10:20']);
        $pick = ListOfTimeSlot::query()->create(['time_slot' => '11:00 - 11:20']);
        $date = now()->addDays(3)->toDateString();
        $vt = VehicleType::query()->create(['price' => 50.00]);

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

        return compact('drop', 'pick', 'date', 'vt');
    }

    public function test_bankart_billing_country_normalizes_and_rejects_unknown_codes(): void
    {
        $this->assertNull(BankartBillingCountry::normalize('OTHER'));
        $this->assertNull(BankartBillingCountry::resolveForPayload('OTHER'));
        $this->assertNull(BankartBillingCountry::resolveForPayload(''));
        $this->assertNull(BankartBillingCountry::resolveForPayload('ZZ'));
        $this->assertFalse(BankartBillingCountry::isValidForBankart('OTHER'));
        $this->assertFalse(BankartBillingCountry::isValidForBankart('ZZ'));
        $this->assertTrue(BankartBillingCountry::isValidForBankart('HR'));
        $this->assertSame('ME', BankartBillingCountry::normalize('me'));
    }

    public function test_agency_with_country_other_is_blocked_before_bankart_create_session(): void
    {
        $fixtures = $this->seedTerminiFixtures();
        $user = User::factory()->create([
            'email_verified_at' => now(),
            'country' => 'OTHER',
            'lang' => 'en',
            'email' => 'agency-other@example.com',
        ]);
        $vehicle = Vehicle::query()->create([
            'user_id' => $user->id,
            'license_plate' => 'KOOTHER1',
            'vehicle_type_id' => $fixtures['vt']->id,
        ]);

        $payment = Mockery::mock(PaymentService::class);
        $payment->shouldNotReceive('createSession');
        $this->app->instance(PaymentService::class, $payment);

        $this->actingAs($user)
            ->from('/panel/reservations')
            ->post(route('checkout.store', [], false), [
                'auth_panel_booking' => 1,
                'payment_method' => 'card',
                'reservation_kind' => ReservationKind::TIME_SLOTS,
                'reservation_date' => $fixtures['date'],
                'drop_off_time_slot_id' => $fixtures['drop']->id,
                'pick_up_time_slot_id' => $fixtures['pick']->id,
                'vehicle_id' => $vehicle->id,
                'accept_terms' => 1,
                'accept_privacy' => 1,
            ])
            ->assertRedirect('/panel/reservations')
            ->assertSessionHas('error', self::MSG_EN)
            ->assertSessionHasErrors('country');

        $this->assertSame(0, TempData::query()->count());
    }

    public function test_no_pending_temp_data_remains_after_billing_country_block(): void
    {
        $fixtures = $this->seedTerminiFixtures();
        $user = User::factory()->create(['country' => 'OTHER', 'lang' => 'en']);
        $vehicle = Vehicle::query()->create([
            'user_id' => $user->id,
            'license_plate' => 'KOPEND2',
            'vehicle_type_id' => $fixtures['vt']->id,
        ]);

        Mockery::mock(PaymentService::class);
        $this->app->instance(PaymentService::class, Mockery::mock(PaymentService::class)->shouldNotReceive('createSession')->getMock());

        $this->actingAs($user)->post(route('checkout.store', [], false), [
            'auth_panel_booking' => 1,
            'payment_method' => 'card',
            'reservation_date' => $fixtures['date'],
            'drop_off_time_slot_id' => $fixtures['drop']->id,
            'pick_up_time_slot_id' => $fixtures['pick']->id,
            'vehicle_id' => $vehicle->id,
            'accept_terms' => 1,
            'accept_privacy' => 1,
        ]);

        $this->assertSame(0, TempData::query()->where('status', TempData::STATUS_PENDING)->count());
        $this->assertSame(0, DailyParkingData::query()->where('pending', '>', 0)->count());
    }

    public function test_real_payment_provider_never_sends_billing_country_other(): void
    {
        config([
            'services.bankart' => [
                'api_url' => 'https://bankart.test',
                'api_key' => 'api-key-1',
                'username' => 'user',
                'password' => 'pass',
                'shared_secret' => '',
                'signature_enabled' => false,
                'send_customer' => true,
            ],
        ]);

        Http::fake();

        $fixtures = $this->seedTerminiFixtures();
        $temp = TempData::query()->create([
            'merchant_transaction_id' => 'mt-other-country',
            'drop_off_time_slot_id' => $fixtures['drop']->id,
            'pick_up_time_slot_id' => $fixtures['pick']->id,
            'reservation_date' => $fixtures['date'],
            'user_name' => 'Agency',
            'country' => 'OTHER',
            'license_plate' => 'KO123',
            'vehicle_type_id' => $fixtures['vt']->id,
            'email' => 't@example.com',
            'status' => TempData::STATUS_PENDING,
        ]);
        $temp->load('vehicleType');

        $result = app(RealPaymentProvider::class)->createSession($temp);

        $this->assertFalse($result->success);
        $this->assertSame('invalid_billing_country', $result->failureReason);
        Http::assertNothingSent();
    }

    public function test_valid_iso_country_passes_checkout_to_bankart(): void
    {
        $fixtures = $this->seedTerminiFixtures();
        $user = User::factory()->create(['country' => 'HR', 'lang' => 'en', 'email_verified_at' => now()]);
        $vehicle = Vehicle::query()->create([
            'user_id' => $user->id,
            'license_plate' => 'KOVALID1',
            'vehicle_type_id' => $fixtures['vt']->id,
        ]);

        $payment = Mockery::mock(PaymentService::class);
        $payment->shouldReceive('createSession')
            ->once()
            ->andReturn(new PaymentSessionResult(true, 'https://bank.test/pay', null));
        $this->app->instance(PaymentService::class, $payment);

        $this->actingAs($user)->post(route('checkout.store', [], false), [
            'auth_panel_booking' => 1,
            'payment_method' => 'card',
            'reservation_date' => $fixtures['date'],
            'drop_off_time_slot_id' => $fixtures['drop']->id,
            'pick_up_time_slot_id' => $fixtures['pick']->id,
            'vehicle_id' => $vehicle->id,
            'accept_terms' => 1,
            'accept_privacy' => 1,
        ])->assertRedirect('https://bank.test/pay');

        $this->assertSame(1, TempData::query()->where('country', 'HR')->count());
    }

    public function test_guest_reserve_page_does_not_offer_other(): void
    {
        $html = $this->get(route('guest.reserve', [], false))->assertOk()->getContent();

        $this->assertStringNotContainsString('value="OTHER"', $html);
    }

    public function test_guest_with_other_country_is_blocked_with_support_message(): void
    {
        $fixtures = $this->seedTerminiFixtures();

        $payment = Mockery::mock(PaymentService::class);
        $payment->shouldNotReceive('createSession');
        $this->app->instance(PaymentService::class, $payment);

        $this->get('/locale/cg')->assertRedirect();

        $this->from('/guest/reserve')
            ->post(route('checkout.store', [], false), [
                'reservation_kind' => ReservationKind::TIME_SLOTS,
                'reservation_date' => $fixtures['date'],
                'drop_off_time_slot_id' => $fixtures['drop']->id,
                'pick_up_time_slot_id' => $fixtures['pick']->id,
                'vehicle_type_id' => $fixtures['vt']->id,
                'name' => 'Guest',
                'country' => 'OTHER',
                'license_plate' => 'PGOTHER1',
                'email' => 'guest-other@example.com',
                'accept_terms' => 1,
                'accept_privacy' => 1,
            ])
            ->assertRedirect('/guest/reserve')
            ->assertSessionHasErrors('country');

        $errors = session('errors')->get('country');
        $this->assertStringContainsString('bus@kotor.me', (string) ($errors[0] ?? ''));
        $this->assertSame(self::SELECT_MSG_CG, (string) ($errors[0] ?? ''));

        $this->assertSame(0, TempData::query()->count());
    }

    public function test_advance_topup_with_other_country_is_blocked_before_topup_record(): void
    {
        config([
            'features.advance_payments' => true,
            'services.bank.driver' => 'bankart',
            'services.bankart.api_url' => 'https://bankart.test',
            'services.bankart.api_key' => 'key',
            'services.bankart.username' => 'u',
            'services.bankart.password' => 'p',
        ]);

        $user = User::factory()->create([
            'email_verified_at' => now(),
            'country' => 'OTHER',
            'lang' => 'en',
            'email' => 'topup-other@example.com',
        ]);

        Http::fake();

        $this->actingAs($user)
            ->post(route('panel.advance.topup.store', [], false), ['amount' => '100.00'])
            ->assertRedirect(route('panel.advance.index', [], false))
            ->assertSessionHas('error', self::MSG_EN);

        $this->assertSame(0, AgencyAdvanceTopup::query()->count());
        Http::assertNothingSent();
    }

    public function test_billing_country_block_creates_admin_alert_with_user_context(): void
    {
        $fixtures = $this->seedTerminiFixtures();
        $user = User::factory()->create([
            'country' => 'OTHER',
            'lang' => 'en',
            'email' => 'fortuna@example.com',
        ]);
        $vehicle = Vehicle::query()->create([
            'user_id' => $user->id,
            'license_plate' => 'KOALERT1',
            'vehicle_type_id' => $fixtures['vt']->id,
        ]);

        $this->app->instance(PaymentService::class, Mockery::mock(PaymentService::class)->shouldNotReceive('createSession')->getMock());

        $this->actingAs($user)->post(route('checkout.store', [], false), [
            'auth_panel_booking' => 1,
            'payment_method' => 'card',
            'reservation_date' => $fixtures['date'],
            'drop_off_time_slot_id' => $fixtures['drop']->id,
            'pick_up_time_slot_id' => $fixtures['pick']->id,
            'vehicle_id' => $vehicle->id,
            'accept_terms' => 1,
            'accept_privacy' => 1,
        ]);

        $this->assertDatabaseHas('admin_alerts', [
            'type' => BankartBillingCountryAlertService::TYPE,
            'status' => AdminAlert::STATUS_UNREAD,
        ]);

        $alert = AdminAlert::query()->where('type', BankartBillingCountryAlertService::TYPE)->firstOrFail();
        $payload = $alert->payload_json ?? [];
        $this->assertSame($user->id, $payload['user_id'] ?? null);
        $this->assertSame('fortuna@example.com', $payload['email'] ?? null);
        $this->assertSame('OTHER', $payload['selected_country'] ?? null);
    }

    public function test_real_payment_provider_sends_valid_billing_country_in_payload(): void
    {
        config([
            'services.bankart' => [
                'api_url' => 'https://bankart.test',
                'api_key' => 'api-key-1',
                'username' => 'user',
                'password' => 'pass',
                'shared_secret' => '',
                'signature_enabled' => false,
                'send_customer' => true,
            ],
        ]);

        $capturedBody = null;
        Http::fake(function ($request) use (&$capturedBody) {
            $capturedBody = (string) $request->body();

            return Http::response(['redirectUrl' => 'https://bank.test/pay'], 200);
        });

        $fixtures = $this->seedTerminiFixtures();
        $temp = TempData::query()->create([
            'merchant_transaction_id' => 'mt-valid-me',
            'drop_off_time_slot_id' => $fixtures['drop']->id,
            'pick_up_time_slot_id' => $fixtures['pick']->id,
            'reservation_date' => $fixtures['date'],
            'user_name' => 'Agency',
            'country' => 'ME',
            'license_plate' => 'KO123',
            'vehicle_type_id' => $fixtures['vt']->id,
            'email' => 't@example.com',
            'status' => TempData::STATUS_PENDING,
        ]);
        $temp->load('vehicleType');

        $result = app(RealPaymentProvider::class)->createSession($temp);

        $this->assertTrue($result->success);
        $this->assertIsString($capturedBody);
        $this->assertStringContainsString('"billingCountry":"ME"', $capturedBody);
        $this->assertStringNotContainsString('"billingCountry":"OTHER"', $capturedBody);
    }
}
