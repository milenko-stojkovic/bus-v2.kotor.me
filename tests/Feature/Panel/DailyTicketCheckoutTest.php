<?php

namespace Tests\Feature\Panel;

use App\Contracts\PaymentService;
use App\Contracts\PaymentSessionResult;
use App\Models\DailyParkingData;
use App\Models\ListOfTimeSlot;
use App\Models\Reservation;
use App\Models\TempData;
use App\Models\User;
use App\Models\Vehicle;
use App\Models\VehicleType;
use App\Models\AgencyAdvanceTransaction;
use App\Services\Payment\PaymentSuccessHandler;
use App\Support\ReservationKind;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Mockery;
use Tests\TestCase;

final class DailyTicketCheckoutTest extends TestCase
{
    use RefreshDatabase;

    private function agencyWithVehicle(): array
    {
        $user = User::factory()->create(['email_verified_at' => now(), 'country' => 'ME']);
        $vt = VehicleType::query()->create(['price' => 12.50]);
        $vehicle = Vehicle::query()->create([
            'user_id' => $user->id,
            'license_plate' => 'KO999ZZ',
            'vehicle_type_id' => $vt->id,
        ]);

        return [$user, $vehicle, $vt];
    }

    private function mockPaymentRedirect(): void
    {
        $mock = Mockery::mock(PaymentService::class);
        $mock->shouldReceive('createSession')
            ->once()
            ->andReturn(new PaymentSessionResult(true, 'https://bank.example/pay', null));
        $this->app->instance(PaymentService::class, $mock);
    }

    public function test_agency_reservation_page_shows_kind_radio_and_explanation(): void
    {
        [$user] = $this->agencyWithVehicle();
        $this->actingAs($user);

        $html = $this->get(route('panel.reservations', [], false))->assertOk()->getContent();

        $this->assertStringContainsString('name="reservation_kind"', $html);
        $this->assertStringContainsString('value="' . ReservationKind::TIME_SLOTS . '"', $html);
        $this->assertStringContainsString('value="' . ReservationKind::DAILY_TICKET . '"', $html);
        $this->assertStringContainsString('id="panelBookingKindExplanation"', $html);
        $this->assertStringNotContainsString('id="panelKindExplTimeSlots"', $html);
        $this->assertStringContainsString('maps.app.goo.gl/5Mp6LFS1gNLYFrSQA', $html);
        $this->assertStringContainsString('maps.app.goo.gl/BqfQWnYqy8mjTo1D8', $html);
        $this->assertStringContainsString('maps.app.goo.gl/1XKocEMgyYi7YoD99', $html);
    }

    public function test_agency_reservation_page_shows_both_explanations_when_daily_ticket_selected(): void
    {
        $user = User::factory()->create([
            'email_verified_at' => now(),
            'country' => 'ME',
            'lang' => 'cg',
        ]);
        $vt = VehicleType::query()->create(['price' => 12.50]);
        Vehicle::query()->create([
            'user_id' => $user->id,
            'license_plate' => 'KO999ZZ',
            'vehicle_type_id' => $vt->id,
        ]);
        $this->actingAs($user);
        app()->setLocale('cg');

        $html = $this->get(route('panel.reservations', [
            'reservation_kind' => ReservationKind::DAILY_TICKET,
        ], false))->assertOk()->getContent();

        $this->assertStringContainsString('id="panelBookingKindExplanation"', $html);
        $this->assertStringContainsString('unaprijed rezervisano', $html);
        $this->assertStringContainsString('Stari grad', $html);
        $this->assertStringContainsString('maps.app.goo.gl/5Mp6LFS1gNLYFrSQA', $html);
        $this->assertStringContainsString('maps.app.goo.gl/BqfQWnYqy8mjTo1D8', $html);
        $this->assertStringNotContainsString('panelKindExplDaily', $html);
    }

    public function test_agency_reservation_page_renders_map_links_in_english(): void
    {
        $user = User::factory()->create([
            'email_verified_at' => now(),
            'country' => 'ME',
            'lang' => 'en',
        ]);
        VehicleType::query()->create(['price' => 12.50]);
        Vehicle::query()->create([
            'user_id' => $user->id,
            'license_plate' => 'EN999ZZ',
            'vehicle_type_id' => VehicleType::query()->first()->id,
        ]);

        $this->actingAs($user);
        app()->setLocale('en');

        $html = $this->get(route('panel.reservations', [], false))->assertOk()->getContent();

        $this->assertStringContainsString('Time slots', $html);
        $this->assertStringContainsString('Daily fee', $html);
        foreach ([
            'maps.app.goo.gl/5Mp6LFS1gNLYFrSQA',
            'maps.app.goo.gl/BqfQWnYqy8mjTo1D8',
            'maps.app.goo.gl/1XKocEMgyYi7YoD99',
        ] as $url) {
            $this->assertStringContainsString($url, $html);
        }
    }

    public function test_daily_ticket_submit_does_not_require_slot_ids(): void
    {
        $this->mockPaymentRedirect();
        [$user, $vehicle] = $this->agencyWithVehicle();
        $date = now()->addDays(4)->toDateString();

        $this->actingAs($user)->post(route('checkout.store', [], false), [
            'auth_panel_booking' => 1,
            'reservation_kind' => ReservationKind::DAILY_TICKET,
            'reservation_date' => $date,
            'vehicle_id' => $vehicle->id,
            'accept_terms' => 1,
            'confirmed_amount' => '12.50',
        ])->assertRedirect('https://bank.example/pay');
    }

    public function test_daily_ticket_creates_temp_data_with_null_slots(): void
    {
        $this->mockPaymentRedirect();
        [$user, $vehicle] = $this->agencyWithVehicle();
        $date = now()->addDays(5)->toDateString();

        $this->actingAs($user)->post(route('checkout.store', [], false), [
            'auth_panel_booking' => 1,
            'reservation_kind' => ReservationKind::DAILY_TICKET,
            'reservation_date' => $date,
            'vehicle_id' => $vehicle->id,
            'accept_terms' => 1,
            'confirmed_amount' => '12.50',
        ]);

        $temp = TempData::query()->latest('id')->firstOrFail();
        $this->assertSame(ReservationKind::DAILY_TICKET, $temp->reservation_kind);
        $this->assertTrue($temp->isDailyTicket());
        $this->assertNull($temp->drop_off_time_slot_id);
        $this->assertNull($temp->pick_up_time_slot_id);
        $this->assertSame('12.50', (string) $temp->invoice_amount_snapshot);
    }

    public function test_daily_ticket_advance_payment_creates_paid_reservation_and_advance_usage_ledger_without_temp_data(): void
    {
        config(['features.advance_payments' => true]);
        config(['services.bank.driver' => 'bankart']); // ensure we are not using fake bank path

        [$user, $vehicle, $vt] = $this->agencyWithVehicle();
        $date = now()->addDays(7)->toDateString();

        AgencyAdvanceTransaction::query()->create([
            'agency_user_id' => $user->id,
            'amount' => number_format(((float) $vt->price) + 10.00, 2, '.', ''),
            'type' => AgencyAdvanceTransaction::TYPE_TOPUP,
            'reference_type' => 'seed',
            'reference_id' => 1,
            'merchant_transaction_id' => 'mtid_seed_dt_adv',
            'note' => 'seed',
        ]);

        $this->actingAs($user)->post(route('checkout.store', [], false), [
            'auth_panel_booking' => 1,
            'reservation_kind' => ReservationKind::DAILY_TICKET,
            'payment_method' => 'advance',
            'reservation_date' => $date,
            'vehicle_id' => $vehicle->id,
            'accept_terms' => 1,
            'merchant_transaction_id' => 'mtid_dt_adv_1',
            'confirmed_amount' => '12.50',
        ])->assertRedirect(route('panel.reservations', [], false));

        $res = Reservation::query()->where('merchant_transaction_id', 'mtid_dt_adv_1')->firstOrFail();
        $this->assertSame(ReservationKind::DAILY_TICKET, (string) $res->reservation_kind);
        $this->assertSame('paid', (string) $res->status);
        $this->assertSame('advance', (string) $res->payment_method);

        $this->assertFalse(TempData::query()->where('merchant_transaction_id', 'mtid_dt_adv_1')->exists());

        $usage = AgencyAdvanceTransaction::query()
            ->where('type', AgencyAdvanceTransaction::TYPE_USAGE)
            ->where('reference_type', 'reservation')
            ->where('reference_id', $res->id)
            ->firstOrFail();
        $this->assertSame('-' . number_format((float) $res->invoice_amount, 2, '.', ''), (string) $usage->amount);
    }

    public function test_daily_ticket_checkout_does_not_change_daily_parking_counters(): void
    {
        $this->mockPaymentRedirect();
        [$user, $vehicle] = $this->agencyWithVehicle();
        $drop = ListOfTimeSlot::query()->create(['time_slot' => '08:00 - 08:20']);
        $date = now()->addDays(6)->toDateString();
        $daily = DailyParkingData::query()->create([
            'date' => $date,
            'time_slot_id' => $drop->id,
            'capacity' => 5,
            'reserved' => 1,
            'pending' => 2,
            'is_blocked' => false,
        ]);

        $this->actingAs($user)->post(route('checkout.store', [], false), [
            'auth_panel_booking' => 1,
            'reservation_kind' => ReservationKind::DAILY_TICKET,
            'reservation_date' => $date,
            'vehicle_id' => $vehicle->id,
            'accept_terms' => 1,
            'confirmed_amount' => '12.50',
        ]);

        $daily->refresh();
        $this->assertSame(1, (int) $daily->reserved);
        $this->assertSame(2, (int) $daily->pending);
    }

    public function test_time_slots_flow_still_requires_both_slots(): void
    {
        [$user, $vehicle] = $this->agencyWithVehicle();
        $date = now()->addDays(3)->toDateString();

        $this->actingAs($user)->post(route('checkout.store', [], false), [
            'auth_panel_booking' => 1,
            'reservation_kind' => ReservationKind::TIME_SLOTS,
            'reservation_date' => $date,
            'vehicle_id' => $vehicle->id,
            'accept_terms' => 1,
            'accept_privacy' => 1,
        ])->assertSessionHasErrors(['drop_off_time_slot_id', 'pick_up_time_slot_id']);
    }

    public function test_payment_success_creates_daily_ticket_reservation_with_null_slots(): void
    {
        [$user, $vehicle, $vt] = $this->agencyWithVehicle();
        $date = now()->addDays(7)->toDateString();

        $temp = TempData::query()->create([
            'merchant_transaction_id' => 'mtid_daily_success',
            'retry_token' => 'retry-daily-1',
            'user_id' => $user->id,
            'vehicle_id' => $vehicle->id,
            'reservation_kind' => ReservationKind::DAILY_TICKET,
            'drop_off_time_slot_id' => null,
            'pick_up_time_slot_id' => null,
            'reservation_date' => $date,
            'user_name' => $user->name,
            'country' => 'ME',
            'license_plate' => $vehicle->license_plate,
            'vehicle_type_id' => $vt->id,
            'invoice_amount_snapshot' => '12.50',
            'email' => $user->email,
            'preferred_locale' => 'en',
            'status' => TempData::STATUS_PENDING,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $created = app(PaymentSuccessHandler::class)->handle($temp, ['source' => 'test'], true, true);
        $this->assertTrue($created);

        $reservation = Reservation::query()->where('merchant_transaction_id', 'mtid_daily_success')->firstOrFail();
        $this->assertSame(ReservationKind::DAILY_TICKET, $reservation->reservation_kind);
        $this->assertNull($reservation->drop_off_time_slot_id);
        $this->assertNull($reservation->pick_up_time_slot_id);
    }

    public function test_expire_pending_daily_ticket_does_not_touch_daily_parking_data(): void
    {
        config(['reservations.pending_expire_minutes' => 15]);

        $drop = ListOfTimeSlot::query()->create(['time_slot' => '12:00 - 12:20']);
        $date = now()->addDays(2)->toDateString();
        $daily = DailyParkingData::query()->create([
            'date' => $date,
            'time_slot_id' => $drop->id,
            'capacity' => 5,
            'reserved' => 0,
            'pending' => 3,
            'is_blocked' => false,
        ]);

        [$user, $vehicle, $vt] = $this->agencyWithVehicle();
        $temp = TempData::query()->create([
            'merchant_transaction_id' => 'mtid_daily_expire',
            'retry_token' => 'retry-daily-exp',
            'user_id' => $user->id,
            'vehicle_id' => $vehicle->id,
            'reservation_kind' => ReservationKind::DAILY_TICKET,
            'drop_off_time_slot_id' => null,
            'pick_up_time_slot_id' => null,
            'reservation_date' => $date,
            'user_name' => $user->name,
            'country' => 'ME',
            'license_plate' => $vehicle->license_plate,
            'vehicle_type_id' => $vt->id,
            'invoice_amount_snapshot' => '12.50',
            'email' => $user->email,
            'preferred_locale' => 'en',
            'status' => TempData::STATUS_PENDING,
        ]);
        $temp->forceFill([
            'created_at' => now()->subMinutes(30),
            'updated_at' => now()->subMinutes(30),
        ])->saveQuietly();

        Artisan::call('reservations:expire-pending');

        $daily->refresh();
        $this->assertSame(3, (int) $daily->pending);
        $this->assertSame(TempData::STATUS_EXPIRED, TempData::query()->where('merchant_transaction_id', 'mtid_daily_expire')->value('status'));
    }

    public function test_same_plate_and_date_can_have_time_slots_and_daily_ticket(): void
    {
        $this->mockPaymentRedirect();
        [$user, $vehicle, $vt] = $this->agencyWithVehicle();
        $drop = ListOfTimeSlot::query()->create(['time_slot' => '10:00 - 10:20']);
        $pick = ListOfTimeSlot::query()->create(['time_slot' => '11:00 - 11:20']);
        $date = now()->addDays(8)->toDateString();

        DailyParkingData::query()->create([
            'date' => $date,
            'time_slot_id' => $drop->id,
            'capacity' => 9,
            'reserved' => 0,
            'pending' => 0,
            'is_blocked' => false,
        ]);
        DailyParkingData::query()->create([
            'date' => $date,
            'time_slot_id' => $pick->id,
            'capacity' => 9,
            'reserved' => 0,
            'pending' => 0,
            'is_blocked' => false,
        ]);

        Reservation::query()->create([
            'user_id' => $user->id,
            'vehicle_id' => $vehicle->id,
            'merchant_transaction_id' => 'mtid_existing_slots',
            'reservation_kind' => ReservationKind::TIME_SLOTS,
            'drop_off_time_slot_id' => $drop->id,
            'pick_up_time_slot_id' => $pick->id,
            'reservation_date' => $date,
            'user_name' => $user->name,
            'country' => 'ME',
            'license_plate' => $vehicle->license_plate,
            'vehicle_type_id' => $vt->id,
            'email' => $user->email,
            'status' => 'paid',
            'invoice_amount' => '12.50',
            'email_sent' => Reservation::EMAIL_NOT_SENT,
            'created_by_admin' => false,
        ]);

        $this->actingAs($user)->post(route('checkout.store', [], false), [
            'auth_panel_booking' => 1,
            'reservation_kind' => ReservationKind::DAILY_TICKET,
            'reservation_date' => $date,
            'vehicle_id' => $vehicle->id,
            'accept_terms' => 1,
            'confirmed_amount' => '12.50',
        ])->assertRedirect('https://bank.example/pay');

        $this->assertSame(1, TempData::query()->where('reservation_kind', ReservationKind::DAILY_TICKET)->count());
    }

    public function test_agency_termini_page_shows_fallback_parking_checkbox(): void
    {
        $user = User::factory()->create(['email_verified_at' => now(), 'country' => 'ME', 'lang' => 'cg']);
        $vt = VehicleType::query()->create(['price' => 12.50]);
        $vehicle = Vehicle::query()->create([
            'user_id' => $user->id,
            'license_plate' => 'KO999ZZ',
            'vehicle_type_id' => $vt->id,
        ]);
        app()->setLocale('cg');
        $date = now()->addDays(3)->toDateString();
        $drop = ListOfTimeSlot::query()->create(['time_slot' => '10:00 - 10:20']);
        $pick = ListOfTimeSlot::query()->create(['time_slot' => '11:00 - 11:20']);

        $html = $this->actingAs($user)->get(route('panel.reservations', [
            'reservation_kind' => ReservationKind::TIME_SLOTS,
            'reservation_date' => $date,
            'drop_off_time_slot_id' => $drop->id,
            'pick_up_time_slot_id' => $pick->id,
            'vehicle_id' => $vehicle->id,
        ], false))->assertOk()->getContent();

        $this->assertStringContainsString('id="panelAcceptPrivacy"', $html);
        $this->assertStringContainsString('nije u mogućnosti da izvrši iskrcaj', $html);
        $this->assertStringNotContainsString('id="panelAcceptPrivacyRow" class="flex items-start gap-2 text-sm hidden"', $html);
    }

    public function test_agency_daily_fee_page_hides_fallback_parking_checkbox(): void
    {
        [$user, $vehicle] = $this->agencyWithVehicle();
        $date = now()->addDays(3)->toDateString();

        $html = $this->actingAs($user)->get(route('panel.reservations', [
            'reservation_kind' => ReservationKind::DAILY_TICKET,
            'reservation_date' => $date,
            'vehicle_id' => $vehicle->id,
        ], false))->assertOk()->getContent();

        $this->assertStringContainsString('id="panelAcceptPrivacyRow" class="flex items-start gap-2 text-sm hidden"', $html);
    }

    public function test_agency_daily_fee_submit_succeeds_without_accept_privacy(): void
    {
        $this->mockPaymentRedirect();
        [$user, $vehicle] = $this->agencyWithVehicle();
        $date = now()->addDays(4)->toDateString();

        $this->actingAs($user)->post(route('checkout.store', [], false), [
            'auth_panel_booking' => 1,
            'reservation_kind' => ReservationKind::DAILY_TICKET,
            'reservation_date' => $date,
            'vehicle_id' => $vehicle->id,
            'accept_terms' => 1,
            'confirmed_amount' => '12.50',
        ])->assertRedirect('https://bank.example/pay');
    }

    public function test_agency_termini_submit_without_accept_privacy_fails(): void
    {
        [$user, $vehicle] = $this->agencyWithVehicle();
        $drop = ListOfTimeSlot::query()->create(['time_slot' => '09:00 - 09:20']);
        $pick = ListOfTimeSlot::query()->create(['time_slot' => '10:00 - 10:20']);
        $date = now()->addDays(3)->toDateString();

        $this->actingAs($user)->post(route('checkout.store', [], false), [
            'auth_panel_booking' => 1,
            'reservation_kind' => ReservationKind::TIME_SLOTS,
            'reservation_date' => $date,
            'drop_off_time_slot_id' => $drop->id,
            'pick_up_time_slot_id' => $pick->id,
            'vehicle_id' => $vehicle->id,
            'accept_terms' => 1,
        ])->assertSessionHasErrors('accept_privacy');
    }
}
