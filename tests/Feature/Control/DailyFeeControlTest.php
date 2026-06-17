<?php

namespace Tests\Feature\Control;

use App\Models\Admin;
use App\Models\ListOfTimeSlot;
use App\Models\Reservation;
use App\Models\TempData;
use App\Models\VehicleType;
use App\Models\VehicleTypeTranslation;
use App\Services\Reservation\ReservationVehicleEligibilityService;
use App\Support\ReservationKind;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

final class DailyFeeControlTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        ReservationVehicleEligibilityService::clearCache();
        Carbon::setTestNow(Carbon::parse('2026-06-10 14:30:00', 'Europe/Podgorica'));
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        ReservationVehicleEligibilityService::clearCache();
        parent::tearDown();
    }

    public function test_guest_cannot_access_control_page(): void
    {
        $this->get(route('control.daily_fee.index', [], false))
            ->assertRedirect(route('control.login', [], false));
    }

    public function test_guest_cannot_submit_check(): void
    {
        $this->post(route('control.daily_fee.check', [], false), [
            'license_plate' => 'PG123AB',
        ])->assertRedirect(route('control.login', [], false));
    }

    public function test_authorized_control_user_can_open_page(): void
    {
        $admin = $this->createControlAdmin();

        $this->actingAs($admin, 'control')
            ->get(route('control.daily_fee.index', [], false))
            ->assertOk()
            ->assertSee('Kontrola dnevne naknade', false)
            ->assertSee('Registarska tablica', false)
            ->assertSee('Provjeri', false);
    }

    public function test_plate_input_is_normalized_to_uppercase(): void
    {
        $admin = $this->createControlAdmin();
        $vt = $this->createVehicleType('Autobus');
        $this->createPaidDailyFee('PG123AB', '2026-06-10', $vt);

        $this->actingAs($admin, 'control')
            ->post(route('control.daily_fee.check', [], false), [
                'license_plate' => ' pg 123 ab ',
            ])
            ->assertOk()
            ->assertSee('Plaćena dnevna naknada: DA', false)
            ->assertSee('PG123AB', false);
    }

    public function test_paid_daily_fee_for_today_returns_da(): void
    {
        $admin = $this->createControlAdmin();
        $vt = $this->createVehicleType('Minibus');
        $this->createPaidDailyFee('KO999ZZ', '2026-06-10', $vt, [
            'user_name' => 'Agencija Test',
            'email' => 'agency@test.local',
        ]);

        $this->actingAs($admin, 'control')
            ->post(route('control.daily_fee.check', [], false), [
                'license_plate' => 'KO999ZZ',
            ])
            ->assertOk()
            ->assertSee('Plaćena dnevna naknada: DA', false)
            ->assertSee('Agencija Test', false)
            ->assertSee('agency@test.local', false)
            ->assertSee('Minibus', false);
    }

    public function test_paid_daily_fee_for_another_day_returns_ne(): void
    {
        $admin = $this->createControlAdmin();
        $vt = $this->createVehicleType('Van');
        $this->createPaidDailyFee('PG111AA', '2026-06-11', $vt);

        $this->actingAs($admin, 'control')
            ->post(route('control.daily_fee.check', [], false), [
                'license_plate' => 'PG111AA',
            ])
            ->assertOk()
            ->assertSee('Plaćena dnevna naknada: NE', false)
            ->assertSee('PG111AA', false)
            ->assertSee('10.06.2026', false);
    }

    public function test_time_slots_reservation_for_today_returns_ne(): void
    {
        $admin = $this->createControlAdmin();
        $vt = $this->createVehicleType('Bus');
        $drop = ListOfTimeSlot::query()->create(['time_slot' => '10:00 - 10:20']);
        $pick = ListOfTimeSlot::query()->create(['time_slot' => '18:00 - 18:20']);

        Reservation::query()->create([
            'reservation_kind' => ReservationKind::TIME_SLOTS,
            'drop_off_time_slot_id' => $drop->id,
            'pick_up_time_slot_id' => $pick->id,
            'reservation_date' => '2026-06-10',
            'user_name' => 'Slot User',
            'country' => 'ME',
            'license_plate' => 'PGSLOT1',
            'vehicle_type_id' => $vt->id,
            'email' => 'slot@test.local',
            'status' => 'paid',
            'invoice_amount' => '40.00',
            'email_sent' => Reservation::EMAIL_NOT_SENT,
        ]);

        $this->actingAs($admin, 'control')
            ->post(route('control.daily_fee.check', [], false), [
                'license_plate' => 'PGSLOT1',
            ])
            ->assertOk()
            ->assertSee('Plaćena dnevna naknada: NE', false);
    }

    public function test_unpaid_daily_fee_reservation_returns_ne(): void
    {
        $admin = $this->createControlAdmin();
        $vt = $this->createVehicleType('Bus');

        Reservation::query()->create([
            'reservation_kind' => ReservationKind::DAILY_TICKET,
            'drop_off_time_slot_id' => null,
            'pick_up_time_slot_id' => null,
            'reservation_date' => '2026-06-10',
            'user_name' => 'Free Daily',
            'country' => 'ME',
            'license_plate' => 'PGFREE1',
            'vehicle_type_id' => $vt->id,
            'email' => 'free@test.local',
            'status' => 'free',
            'invoice_amount' => '0.00',
            'email_sent' => Reservation::EMAIL_NOT_SENT,
        ]);

        $this->actingAs($admin, 'control')
            ->post(route('control.daily_fee.check', [], false), [
                'license_plate' => 'PGFREE1',
            ])
            ->assertOk()
            ->assertSee('Plaćena dnevna naknada: NE', false);
    }

    public function test_multiple_paid_daily_fees_for_same_plate_and_day_are_listed(): void
    {
        $admin = $this->createControlAdmin();
        $vt = $this->createVehicleType('Bus');

        $this->createPaidDailyFee('PGMULTI', '2026-06-10', $vt, [
            'user_name' => 'Agencija A',
            'email' => 'a@test.local',
            'created_at' => Carbon::parse('2026-06-10 09:00:00', 'Europe/Podgorica'),
        ]);
        $this->createPaidDailyFee('PGMULTI', '2026-06-10', $vt, [
            'user_name' => 'Agencija B',
            'email' => 'b@test.local',
            'created_at' => Carbon::parse('2026-06-10 11:00:00', 'Europe/Podgorica'),
        ]);

        $html = $this->actingAs($admin, 'control')
            ->post(route('control.daily_fee.check', [], false), [
                'license_plate' => 'PGMULTI',
            ])
            ->assertOk()
            ->assertSee('Plaćena dnevna naknada: DA', false)
            ->getContent();

        $this->assertStringContainsString('Agencija A', $html);
        $this->assertStringContainsString('Agencija B', $html);
    }

    public function test_check_does_not_trigger_payment_fiscal_or_email_side_effects(): void
    {
        Mail::fake();
        Queue::fake();

        $admin = $this->createControlAdmin();
        $vt = $this->createVehicleType('Bus');
        $this->createPaidDailyFee('PGSIDE1', '2026-06-10', $vt);
        $countAfterSetup = Reservation::query()->count();

        $this->actingAs($admin, 'control')
            ->post(route('control.daily_fee.check', [], false), [
                'license_plate' => 'PGSIDE1',
            ])
            ->assertOk();

        $this->assertSame($countAfterSetup, Reservation::query()->count());
        Mail::assertNothingSent();
        Queue::assertNothingPushed();
    }

    public function test_today_list_shows_paid_daily_fee_passenger_limo_vehicle(): void
    {
        $admin = $this->createControlAdmin();
        $limo = $this->createLimoPassengerType();
        $this->createPaidDailyFee('LIMO001', '2026-06-10', $limo, [
            'user_name' => 'Limo Agency',
            'email' => 'limo@test.local',
        ]);

        $this->actingAs($admin, 'control')
            ->get(route('control.daily_fee.index', [], false))
            ->assertOk()
            ->assertSee('Vozila sa plaćenom dnevnom naknadom za danas', false)
            ->assertSee('LIMO001', false)
            ->assertSee('Limo Agency', false)
            ->assertSee('Putničko vozilo', false);
    }

    public function test_today_list_shows_paid_daily_fee_minibus_eight_plus_one(): void
    {
        $admin = $this->createControlAdmin();
        $minibus = $this->createMinibusType();
        $this->createPaidDailyFee('MINI801', '2026-06-10', $minibus, [
            'user_name' => 'Minibus Agency',
        ]);

        $html = $this->actingAs($admin, 'control')
            ->get(route('control.daily_fee.index', [], false))
            ->assertOk()
            ->getContent();

        $this->assertStringContainsString('MINI801', $html);
        $this->assertStringContainsString('Minibus Agency', $html);
        $this->assertStringContainsString('Mini bus', $html);
    }

    public function test_today_list_does_not_show_termini_reservation(): void
    {
        $admin = $this->createControlAdmin();
        $limo = $this->createLimoPassengerType();
        $drop = ListOfTimeSlot::query()->create(['time_slot' => '10:00 - 10:20']);
        $pick = ListOfTimeSlot::query()->create(['time_slot' => '18:00 - 18:20']);

        Reservation::query()->create([
            'reservation_kind' => ReservationKind::TIME_SLOTS,
            'drop_off_time_slot_id' => $drop->id,
            'pick_up_time_slot_id' => $pick->id,
            'reservation_date' => '2026-06-10',
            'user_name' => 'Termini User',
            'country' => 'ME',
            'license_plate' => 'PGTERM1',
            'vehicle_type_id' => $limo->id,
            'email' => 'termini@test.local',
            'status' => 'paid',
            'invoice_amount' => '40.00',
            'email_sent' => Reservation::EMAIL_NOT_SENT,
        ]);

        $this->actingAs($admin, 'control')
            ->get(route('control.daily_fee.index', [], false))
            ->assertOk()
            ->assertDontSee('PGTERM1', false);
    }

    public function test_today_list_does_not_show_unpaid_daily_fee(): void
    {
        $admin = $this->createControlAdmin();
        $limo = $this->createLimoPassengerType();

        Reservation::query()->create([
            'reservation_kind' => ReservationKind::DAILY_TICKET,
            'reservation_date' => '2026-06-10',
            'user_name' => 'Unpaid',
            'country' => 'ME',
            'license_plate' => 'PGUNPAID',
            'vehicle_type_id' => $limo->id,
            'email' => 'unpaid@test.local',
            'status' => 'free',
            'invoice_amount' => '0.00',
            'email_sent' => Reservation::EMAIL_NOT_SENT,
        ]);

        $this->actingAs($admin, 'control')
            ->get(route('control.daily_fee.index', [], false))
            ->assertOk()
            ->assertDontSee('PGUNPAID', false);
    }

    public function test_today_list_does_not_show_daily_fee_for_another_date(): void
    {
        $admin = $this->createControlAdmin();
        $limo = $this->createLimoPassengerType();
        $this->createPaidDailyFee('PGTOMOR', '2026-06-11', $limo);

        $this->actingAs($admin, 'control')
            ->get(route('control.daily_fee.index', [], false))
            ->assertOk()
            ->assertDontSee('PGTOMOR', false);
    }

    public function test_today_list_does_not_show_other_vehicle_categories(): void
    {
        $admin = $this->createControlAdmin();
        $bus = $this->createMediumBusType();
        $this->createPaidDailyFee('PGBIGBUS', '2026-06-10', $bus);

        $this->actingAs($admin, 'control')
            ->get(route('control.daily_fee.index', [], false))
            ->assertOk()
            ->assertDontSee('PGBIGBUS', false);
    }

    public function test_today_list_includes_guest_and_agency_daily_fee_reservations(): void
    {
        $admin = $this->createControlAdmin();
        $limo = $this->createLimoPassengerType();
        $minibus = $this->createMinibusType();

        $this->createPaidDailyFee('GUESTDN1', '2026-06-10', $limo, [
            'user_name' => 'Guest Driver',
            'email' => 'guest@test.local',
            'user_id' => null,
            'preferred_locale' => 'en',
        ]);
        $this->createPaidDailyFee('AGENCYDN1', '2026-06-10', $minibus, [
            'user_name' => 'Agency Driver',
            'email' => 'agency@test.local',
            'user_id' => 99,
        ]);

        $html = $this->actingAs($admin, 'control')
            ->get(route('control.daily_fee.index', [], false))
            ->assertOk()
            ->getContent();

        $this->assertStringContainsString('GUESTDN1', $html);
        $this->assertStringContainsString('AGENCYDN1', $html);
        $this->assertStringContainsString('Guest Driver', $html);
        $this->assertStringContainsString('Agency Driver', $html);
    }

    public function test_today_list_empty_state_when_no_matching_rows(): void
    {
        $admin = $this->createControlAdmin();

        $this->actingAs($admin, 'control')
            ->get(route('control.daily_fee.index', [], false))
            ->assertOk()
            ->assertSee('Nema vozila sa plaćenom dnevnom naknadom za danas.', false);
    }

    public function test_today_list_does_not_include_pending_temp_data(): void
    {
        $admin = $this->createControlAdmin();
        $limo = $this->createLimoPassengerType();

        TempData::query()->create([
            'merchant_transaction_id' => 'mt-pending-daily',
            'reservation_kind' => ReservationKind::DAILY_TICKET,
            'reservation_date' => '2026-06-10',
            'user_name' => 'Pending Guest',
            'country' => 'ME',
            'license_plate' => 'PGPEND1',
            'vehicle_type_id' => $limo->id,
            'email' => 'pending@test.local',
            'status' => TempData::STATUS_PENDING,
        ]);

        $this->actingAs($admin, 'control')
            ->get(route('control.daily_fee.index', [], false))
            ->assertOk()
            ->assertDontSee('PGPEND1', false);
    }

    public function test_manual_plate_check_still_works_with_today_list_present(): void
    {
        $admin = $this->createControlAdmin();
        $limo = $this->createLimoPassengerType();
        $this->createPaidDailyFee('PGMANUAL', '2026-06-10', $limo, [
            'user_name' => 'Manual Check Agency',
        ]);

        $this->actingAs($admin, 'control')
            ->post(route('control.daily_fee.check', [], false), [
                'license_plate' => 'PGMANUAL',
            ])
            ->assertOk()
            ->assertSee('Plaćena dnevna naknada: DA', false)
            ->assertSee('Manual Check Agency', false)
            ->assertSee('Vozila sa plaćenom dnevnom naknadom za danas', false)
            ->assertSee('PGMANUAL', false);
    }

    public function test_control_daily_fee_list_vehicle_type_ids_resolve_from_translations(): void
    {
        $limo = $this->createLimoPassengerType();
        $minibus = $this->createMinibusType();
        $bus = $this->createMediumBusType();

        $ids = app(ReservationVehicleEligibilityService::class)->controlDailyFeeListVehicleTypeIds();

        $this->assertContains((int) $limo->id, $ids);
        $this->assertContains((int) $minibus->id, $ids);
        $this->assertNotContains((int) $bus->id, $ids);
    }

    private function createControlAdmin(): Admin
    {
        return Admin::query()->create([
            'username' => 'control_dn',
            'email' => 'control-dn@test.local',
            'password' => bcrypt('secret'),
            'control_access' => true,
        ]);
    }

    private function createVehicleType(string $name): VehicleType
    {
        $vt = VehicleType::query()->create(['price' => '40.00']);
        foreach (['cg', 'en'] as $locale) {
            VehicleTypeTranslation::query()->create([
                'vehicle_type_id' => $vt->id,
                'locale' => $locale,
                'name' => $name,
                'description' => null,
            ]);
        }

        return $vt;
    }

    private function createLimoPassengerType(): VehicleType
    {
        $vt = VehicleType::query()->create(['price' => '15.00']);
        foreach (['cg', 'en'] as $locale) {
            VehicleTypeTranslation::query()->create([
                'vehicle_type_id' => $vt->id,
                'locale' => $locale,
                'name' => $locale === 'cg' ? 'Putničko vozilo' : 'Personal vehicle',
                'description' => $locale === 'cg' ? '4+1 do 7+1 sjedišta' : 'Passenger car (4+1 to 7+1 seats)',
            ]);
        }

        return $vt;
    }

    private function createMinibusType(): VehicleType
    {
        $vt = VehicleType::query()->create(['price' => '25.00']);
        foreach (['cg', 'en'] as $locale) {
            VehicleTypeTranslation::query()->create([
                'vehicle_type_id' => $vt->id,
                'locale' => $locale,
                'name' => 'Mini bus',
                'description' => $locale === 'cg' ? 'Mini bus (8+1 sjedište)' : 'Mini bus (8+1 seats)',
            ]);
        }

        return $vt;
    }

    private function createMediumBusType(): VehicleType
    {
        $vt = VehicleType::query()->create(['price' => '40.00']);
        foreach (['cg', 'en'] as $locale) {
            VehicleTypeTranslation::query()->create([
                'vehicle_type_id' => $vt->id,
                'locale' => $locale,
                'name' => $locale === 'cg' ? 'Srednji autobus' : 'Medium bus',
                'description' => $locale === 'cg' ? 'Autobus (9–23 sjedišta)' : 'Bus (9–23 seats)',
            ]);
        }

        return $vt;
    }

    /**
     * @param  array<string, mixed>  $overrides
     */
    private function createPaidDailyFee(string $plate, string $date, VehicleType $vt, array $overrides = []): Reservation
    {
        return Reservation::query()->create(array_merge([
            'reservation_kind' => ReservationKind::DAILY_TICKET,
            'drop_off_time_slot_id' => null,
            'pick_up_time_slot_id' => null,
            'reservation_date' => $date,
            'user_name' => 'Agencija',
            'country' => 'ME',
            'license_plate' => $plate,
            'vehicle_type_id' => $vt->id,
            'email' => 'agency@example.test',
            'status' => 'paid',
            'invoice_amount' => '40.00',
            'email_sent' => Reservation::EMAIL_NOT_SENT,
        ], $overrides));
    }
}
