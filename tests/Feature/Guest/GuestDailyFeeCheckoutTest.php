<?php

namespace Tests\Feature\Guest;

use App\Contracts\PaymentService;
use App\Contracts\PaymentSessionResult;
use App\Models\Admin;
use App\Models\ListOfTimeSlot;
use App\Models\Reservation;
use App\Models\TempData;
use App\Models\VehicleType;
use App\Models\VehicleTypeTranslation;
use App\Services\Payment\PaymentSuccessHandler;
use App\Services\Reservation\ReservationVehicleEligibilityService;
use App\Support\ReservationKind;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Mockery;
use Tests\TestCase;

final class GuestDailyFeeCheckoutTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        ReservationVehicleEligibilityService::clearCache();
    }

    protected function tearDown(): void
    {
        ReservationVehicleEligibilityService::clearCache();
        parent::tearDown();
    }

    private function mockPaymentRedirect(): void
    {
        $mock = Mockery::mock(PaymentService::class);
        $mock->shouldReceive('createSession')
            ->once()
            ->andReturn(new PaymentSessionResult(true, 'https://bank.example/pay', null));
        $this->app->instance(PaymentService::class, $mock);
    }

    private function createLimoPassengerType(): VehicleType
    {
        $vt = VehicleType::query()->create(['price' => 15.00]);
        VehicleTypeTranslation::query()->create([
            'vehicle_type_id' => $vt->id,
            'locale' => 'cg',
            'name' => 'Putničko vozilo',
            'description' => '4+1 do 7+1 sjedišta',
        ]);
        VehicleTypeTranslation::query()->create([
            'vehicle_type_id' => $vt->id,
            'locale' => 'en',
            'name' => 'Personal vehicle',
            'description' => 'Passenger car (4+1 to 7+1 seats)',
        ]);

        return $vt->load('translations');
    }

    private function createBusType(): VehicleType
    {
        $vt = VehicleType::query()->create(['price' => 40.00]);
        VehicleTypeTranslation::query()->create([
            'vehicle_type_id' => $vt->id,
            'locale' => 'cg',
            'name' => 'Srednji autobus',
            'description' => '9–23 sjedišta',
        ]);
        VehicleTypeTranslation::query()->create([
            'vehicle_type_id' => $vt->id,
            'locale' => 'en',
            'name' => 'Medium bus',
            'description' => '9–23 seats',
        ]);

        return $vt->load('translations');
    }

    /**
     * @return array{0: ListOfTimeSlot, 1: ListOfTimeSlot}
     */
    private function seedPaidSlots(): array
    {
        $drop = ListOfTimeSlot::query()->create(['time_slot' => '10:00 - 10:20']);
        $pick = ListOfTimeSlot::query()->create(['time_slot' => '11:00 - 11:20']);

        return [$drop, $pick];
    }

    public function test_guest_termini_page_shows_fallback_parking_checkbox(): void
    {
        $this->get('/locale/cg')->assertRedirect();

        $html = $this->get('/guest/reserve')->assertOk()->getContent();

        $this->assertStringContainsString('id="guestAcceptPrivacy"', $html);
        $this->assertStringContainsString('nije u mogućnosti da izvrši iskrcaj', $html);
        $this->assertStringNotContainsString('id="guestAcceptPrivacyRow" class="flex items-start gap-2 text-sm hidden"', $html);
    }

    public function test_guest_daily_fee_page_hides_fallback_parking_checkbox(): void
    {
        $this->get('/locale/cg')->assertRedirect();
        $date = now()->addDays(2)->toDateString();

        $html = $this->get('/guest/reserve?' . http_build_query([
            'reservation_kind' => ReservationKind::DAILY_TICKET,
            'reservation_date' => $date,
        ]))->assertOk()->getContent();

        $this->assertStringContainsString('id="guestAcceptPrivacyRow" class="flex items-start gap-2 text-sm hidden"', $html);
    }

    public function test_guest_reservation_page_shows_termini_and_daily_fee_selector(): void
    {
        $this->get('/locale/cg')->assertRedirect();

        $html = $this->get('/guest/reserve')->assertOk()->getContent();

        $this->assertStringContainsString('name="reservation_kind"', $html);
        $this->assertStringContainsString('value="' . ReservationKind::TIME_SLOTS . '"', $html);
        $this->assertStringContainsString('value="' . ReservationKind::DAILY_TICKET . '"', $html);
        $this->assertStringContainsString('id="guestBookingKindExplanation"', $html);
        $this->assertStringContainsString('maps.app.goo.gl/5Mp6LFS1gNLYFrSQA', $html);
        $this->assertStringContainsString('maps.app.goo.gl/BqfQWnYqy8mjTo1D8', $html);
    }

    public function test_guest_termini_hides_passenger_limo_category(): void
    {
        $this->get('/locale/cg')->assertRedirect();
        $limo = $this->createLimoPassengerType();
        $bus = $this->createBusType();
        [$drop, $pick] = $this->seedPaidSlots();
        $date = now()->addDays(2)->toDateString();

        $html = $this->get('/guest/reserve?' . http_build_query([
            'reservation_kind' => ReservationKind::TIME_SLOTS,
            'reservation_date' => $date,
            'drop_off_time_slot_id' => $drop->id,
            'pick_up_time_slot_id' => $pick->id,
        ]))->assertOk()->getContent();

        $this->assertStringContainsString($bus->formatLabel('cg', 'EUR'), $html);
        $this->assertStringNotContainsString($limo->formatLabel('cg', 'EUR'), $html);
    }

    public function test_guest_daily_fee_shows_passenger_limo_category(): void
    {
        $this->get('/locale/cg')->assertRedirect();
        $limo = $this->createLimoPassengerType();
        $this->createBusType();
        $date = now()->addDays(2)->toDateString();

        $html = $this->get('/guest/reserve?' . http_build_query([
            'reservation_kind' => ReservationKind::DAILY_TICKET,
            'reservation_date' => $date,
        ]))->assertOk()->getContent();

        $this->assertStringContainsString($limo->formatLabel('cg', 'EUR'), $html);
    }

    public function test_guest_termini_still_requires_arrival_and_departure_slots(): void
    {
        $vt = VehicleType::query()->create(['price' => 10]);
        $date = now()->addDays(3)->toDateString();

        $this->from('/guest/reserve')
            ->post(route('checkout.store', [], false), [
                'reservation_kind' => ReservationKind::TIME_SLOTS,
                'reservation_date' => $date,
                'vehicle_type_id' => $vt->id,
                'name' => 'Guest',
                'country' => 'ME',
                'license_plate' => 'KO111AA',
                'email' => 'g@example.com',
                'accept_terms' => 1,
                'accept_privacy' => 1,
            ])
            ->assertSessionHasErrors(['drop_off_time_slot_id', 'pick_up_time_slot_id']);
    }

    public function test_guest_daily_fee_does_not_require_arrival_and_departure_slots(): void
    {
        $this->mockPaymentRedirect();
        $vt = VehicleType::query()->create(['price' => 22.00]);
        $date = now()->addDays(4)->toDateString();

        $this->from('/guest/reserve')
            ->post(route('checkout.store', [], false), [
                'reservation_kind' => ReservationKind::DAILY_TICKET,
                'reservation_date' => $date,
                'vehicle_type_id' => $vt->id,
                'name' => 'Guest Daily',
                'country' => 'ME',
                'license_plate' => 'GUESTDN1',
                'email' => 'guest-daily@example.com',
                'accept_terms' => 1,
                'confirmed_amount' => '22.00',
            ])
            ->assertRedirect('https://bank.example/pay');
    }

    public function test_guest_daily_fee_creates_temp_data_with_null_slots(): void
    {
        $this->mockPaymentRedirect();
        $vt = VehicleType::query()->create(['price' => 18.50]);
        $date = now()->addDays(5)->toDateString();

        $this->post(route('checkout.store', [], false), [
            'reservation_kind' => ReservationKind::DAILY_TICKET,
            'reservation_date' => $date,
            'vehicle_type_id' => $vt->id,
            'name' => 'Guest Temp',
            'country' => 'ME',
            'license_plate' => 'GUESTDN2',
            'email' => 'guest-temp@example.com',
            'accept_terms' => 1,
            'confirmed_amount' => '18.50',
        ]);

        $temp = TempData::query()->latest('id')->firstOrFail();
        $this->assertSame(ReservationKind::DAILY_TICKET, $temp->reservation_kind);
        $this->assertNull($temp->drop_off_time_slot_id);
        $this->assertNull($temp->pick_up_time_slot_id);
        $this->assertSame('18.50', (string) $temp->invoice_amount_snapshot);
        $this->assertNull($temp->user_id);
    }

    public function test_guest_daily_fee_uses_card_payment_path_only(): void
    {
        $vt = VehicleType::query()->create(['price' => 12.00]);
        $date = now()->addDays(6)->toDateString();

        $this->post(route('checkout.store', [], false), [
            'reservation_kind' => ReservationKind::DAILY_TICKET,
            'payment_method' => 'advance',
            'reservation_date' => $date,
            'vehicle_type_id' => $vt->id,
            'name' => 'Guest Card',
            'country' => 'ME',
            'license_plate' => 'GUESTDN3',
            'email' => 'guest-card@example.com',
            'accept_terms' => 1,
        ])->assertSessionHasErrors('payment_method');

        $this->mockPaymentRedirect();
        $this->post(route('checkout.store', [], false), [
            'reservation_kind' => ReservationKind::DAILY_TICKET,
            'reservation_date' => $date,
            'vehicle_type_id' => $vt->id,
            'name' => 'Guest Card',
            'country' => 'ME',
            'license_plate' => 'GUESTDN3',
            'email' => 'guest-card@example.com',
            'accept_terms' => 1,
            'confirmed_amount' => '12.00',
        ])->assertRedirect('https://bank.example/pay');
    }

    public function test_guest_forged_time_slots_with_limo_category_is_rejected(): void
    {
        [$drop, $pick] = $this->seedPaidSlots();
        $limo = $this->createLimoPassengerType();
        $date = now()->addDays(2)->toDateString();

        $this->from('/guest/reserve')
            ->post(route('checkout.store', [], false), [
                'reservation_kind' => ReservationKind::TIME_SLOTS,
                'reservation_date' => $date,
                'drop_off_time_slot_id' => $drop->id,
                'pick_up_time_slot_id' => $pick->id,
                'vehicle_type_id' => $limo->id,
                'name' => 'Guest Test',
                'country' => 'ME',
                'license_plate' => 'PGTEST1',
                'email' => 'guest@test.local',
                'accept_terms' => 1,
                'accept_privacy' => 1,
            ])
            ->assertSessionHasErrors('vehicle_type_id');
    }

    public function test_guest_daily_fee_payment_success_creates_paid_reservation(): void
    {
        $vt = VehicleType::query()->create(['price' => 25.00]);
        $date = now()->addDays(7)->toDateString();

        $temp = TempData::query()->create([
            'merchant_transaction_id' => 'mtid_guest_daily_success',
            'retry_token' => 'retry-guest-daily-1',
            'user_id' => null,
            'vehicle_id' => null,
            'reservation_kind' => ReservationKind::DAILY_TICKET,
            'drop_off_time_slot_id' => null,
            'pick_up_time_slot_id' => null,
            'reservation_date' => $date,
            'user_name' => 'Guest Payer',
            'country' => 'ME',
            'license_plate' => 'GUESTOK1',
            'vehicle_type_id' => $vt->id,
            'invoice_amount_snapshot' => '25.00',
            'email' => 'guest-paid@example.com',
            'preferred_locale' => 'cg',
            'status' => TempData::STATUS_PENDING,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $created = app(PaymentSuccessHandler::class)->handle($temp, ['source' => 'test'], true, true);
        $this->assertTrue($created);

        $reservation = Reservation::query()->where('merchant_transaction_id', 'mtid_guest_daily_success')->firstOrFail();
        $this->assertSame(ReservationKind::DAILY_TICKET, $reservation->reservation_kind);
        $this->assertSame('paid', (string) $reservation->status);
        $this->assertNull($reservation->user_id);
        $this->assertNull($reservation->drop_off_time_slot_id);
        $this->assertNull($reservation->pick_up_time_slot_id);
    }

    public function test_control_page_finds_guest_daily_fee_by_plate_for_today(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-06-10 10:00:00', 'Europe/Podgorica'));

        $admin = Admin::query()->create([
            'username' => 'control_guest_dn',
            'email' => 'control-guest-dn@test.local',
            'password' => bcrypt('secret'),
            'control_access' => true,
        ]);
        $vt = VehicleType::query()->create(['price' => '30.00']);

        Reservation::query()->create([
            'reservation_kind' => ReservationKind::DAILY_TICKET,
            'drop_off_time_slot_id' => null,
            'pick_up_time_slot_id' => null,
            'reservation_date' => '2026-06-10',
            'user_name' => 'Guest Visitor',
            'country' => 'ME',
            'license_plate' => 'PGGUEST1',
            'vehicle_type_id' => $vt->id,
            'email' => 'guest-control@example.com',
            'status' => 'paid',
            'invoice_amount' => '30.00',
            'email_sent' => Reservation::EMAIL_NOT_SENT,
        ]);

        $this->actingAs($admin, 'control')
            ->post(route('control.daily_fee.check', [], false), [
                'license_plate' => 'PGGUEST1',
            ])
            ->assertOk()
            ->assertSee('Plaćena dnevna naknada: DA', false)
            ->assertSee('Guest Visitor', false);

        Carbon::setTestNow();
    }
}
