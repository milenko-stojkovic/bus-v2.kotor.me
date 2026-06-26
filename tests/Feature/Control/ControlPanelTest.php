<?php

namespace Tests\Feature\Control;

use App\Models\Admin;
use App\Models\ListOfTimeSlot;
use App\Models\Reservation;
use App\Models\VehicleType;
use App\Models\VehicleTypeTranslation;
use App\Support\ReservationKind;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ControlPanelTest extends TestCase
{
    use RefreshDatabase;

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    public function test_guest_is_redirected_from_dashboard_to_control_login(): void
    {
        $this->get('/control')->assertRedirect(route('control.login', [], false));
    }

    public function test_control_login_screen_renders(): void
    {
        $this->get('/control/login')->assertOk();
    }

    public function test_control_user_can_login_and_see_dashboard(): void
    {
        $this->createControlAdmin();

        $response = $this->post('/control/login', [
            'email' => 'field@example.test',
            'password' => 'secret-pass',
        ]);

        $response->assertRedirect(route('control.dashboard', [], false));
        $this->assertAuthenticatedAs(Admin::where('email', 'field@example.test')->first(), 'control');
        $this->get('/control')->assertOk();
    }

    public function test_termini_search_uses_native_date_input_for_ios_reliability(): void
    {
        $this->createControlAdmin();
        $this->actingAs(Admin::where('email', 'field@example.test')->first(), 'control');

        $html = $this->get(route('control.dashboard', [], false))
            ->assertOk()
            ->getContent();

        $this->assertStringContainsString('name="date"', $html);
        $this->assertStringContainsString('id="c_date"', $html);
        $this->assertStringContainsString('type="date"', $html);
        $this->assertStringNotContainsString('data-iso-date-input', $html);
        $this->assertStringNotContainsString('data-iso-date-display', $html);
    }

    public function test_termini_search_by_date_reaches_controller_as_yyyy_mm_dd(): void
    {
        $drop = ListOfTimeSlot::query()->create(['time_slot' => '09:00 - 09:20']);
        $pick = ListOfTimeSlot::query()->create(['time_slot' => '17:00 - 17:20']);
        $vehicleType = $this->createVehicleType('Car');
        $this->createControlAdmin();
        $date = now()->addDays(5)->format('Y-m-d');

        Reservation::query()->create([
            'drop_off_time_slot_id' => $drop->id,
            'pick_up_time_slot_id' => $pick->id,
            'reservation_date' => $date,
            'user_name' => 'iOS Date Search',
            'country' => 'ME',
            'license_plate' => 'PG IOS1',
            'vehicle_type_id' => $vehicleType->id,
            'email' => 'ios-date@example.test',
            'status' => 'paid',
        ]);

        $this->actingAs(Admin::where('email', 'field@example.test')->first(), 'control');

        $this->get('/control?search=1&date='.$date)
            ->assertOk()
            ->assertSee('PG IOS1', false)
            ->assertSee('iOS Date Search', false);
    }

    public function test_admin_without_control_access_cannot_login_via_control(): void
    {
        Admin::query()->create([
            'username' => 'nope',
            'email' => 'nope@example.test',
            'password' => bcrypt('x'),
            'control_access' => false,
        ]);

        $this->post('/control/login', [
            'email' => 'nope@example.test',
            'password' => 'x',
        ]);

        $this->assertGuest('control');
    }

    public function test_arrivals_shows_vehicle_type_without_duplicate_name_or_price(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-06-10 12:00:00', 'Europe/Belgrade'));

        $drop = ListOfTimeSlot::query()->create(['time_slot' => '13:00 - 13:20']);
        $pick = ListOfTimeSlot::query()->create(['time_slot' => '20:00 - 20:20']);
        $vehicleType = VehicleType::query()->create(['price' => 15]);
        VehicleTypeTranslation::query()->create([
            'vehicle_type_id' => $vehicleType->id,
            'locale' => 'cg',
            'name' => 'Putničko vozilo',
            'description' => 'Putničko vozilo (4+1, 5+1, 6+1 i 7+1 mjesta)',
        ]);
        $this->createControlAdmin();

        Reservation::query()->create([
            'drop_off_time_slot_id' => $drop->id,
            'pick_up_time_slot_id' => $pick->id,
            'reservation_date' => '2026-06-10',
            'user_name' => 'Test User',
            'country' => 'ME',
            'license_plate' => 'PG AA 123',
            'vehicle_type_id' => $vehicleType->id,
            'email' => 'guest@example.test',
            'status' => 'paid',
        ]);

        $this->actingAs(Admin::where('email', 'field@example.test')->first(), 'control');
        $response = $this->get('/control');

        $response->assertOk();
        $response->assertSee('13:00 - 13:20', false);
        $response->assertSee('PG AA 123', false);
        $response->assertSee('Putničko vozilo (4+1, 5+1, 6+1 i 7+1 mjesta)', false);
        $response->assertDontSee('Putničko vozilo (Putničko vozilo', false);
        $response->assertDontSee('15.00 EUR', false);
    }

    public function test_arrivals_lists_reservation_in_next_one_hour_window(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-06-10 12:00:00', 'Europe/Belgrade'));

        $drop = ListOfTimeSlot::query()->create(['time_slot' => '13:00 - 13:20']);
        $pick = ListOfTimeSlot::query()->create(['time_slot' => '20:00 - 20:20']);
        $vehicleType = $this->createVehicleType('Van');
        $this->createControlAdmin();

        Reservation::query()->create([
            'drop_off_time_slot_id' => $drop->id,
            'pick_up_time_slot_id' => $pick->id,
            'reservation_date' => '2026-06-10',
            'user_name' => 'Test User',
            'country' => 'ME',
            'license_plate' => 'PG AA 123',
            'vehicle_type_id' => $vehicleType->id,
            'email' => 'guest@example.test',
            'status' => 'paid',
        ]);

        $this->actingAs(Admin::where('email', 'field@example.test')->first(), 'control');
        $response = $this->get('/control');

        $response->assertOk();
        $response->assertSee('13:00 - 13:20', false);
        $response->assertSee('PG AA 123', false);
        $response->assertSee('Van', false);
    }

    public function test_arrivals_hides_reservation_more_than_one_hour_before_slot_start(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-06-10 11:00:00', 'Europe/Belgrade'));

        $drop = ListOfTimeSlot::query()->create(['time_slot' => '13:00 - 13:20']);
        $pick = ListOfTimeSlot::query()->create(['time_slot' => '20:00 - 20:20']);
        $vehicleType = $this->createVehicleType('Van');
        $this->createControlAdmin();

        Reservation::query()->create([
            'drop_off_time_slot_id' => $drop->id,
            'pick_up_time_slot_id' => $pick->id,
            'reservation_date' => '2026-06-10',
            'user_name' => 'Too Early',
            'country' => 'ME',
            'license_plate' => 'PG EARLY1',
            'vehicle_type_id' => $vehicleType->id,
            'email' => 'early@example.test',
            'status' => 'paid',
        ]);

        $this->actingAs(Admin::where('email', 'field@example.test')->first(), 'control')
            ->get('/control')
            ->assertOk()
            ->assertDontSee('PG EARLY1', false);
    }

    public function test_search_finds_future_reservation_by_email(): void
    {
        $drop = ListOfTimeSlot::query()->create(['time_slot' => '09:00 - 09:20']);
        $pick = ListOfTimeSlot::query()->create(['time_slot' => '17:00 - 17:20']);
        $vehicleType = $this->createVehicleType('Car');
        $this->createControlAdmin();

        Reservation::query()->create([
            'drop_off_time_slot_id' => $drop->id,
            'pick_up_time_slot_id' => $pick->id,
            'reservation_date' => now()->addDays(2)->format('Y-m-d'),
            'user_name' => 'Search Me',
            'country' => 'ME',
            'license_plate' => 'PG BB 999',
            'vehicle_type_id' => $vehicleType->id,
            'email' => 'unique-find@example.test',
            'status' => 'paid',
        ]);

        $this->actingAs(Admin::where('email', 'field@example.test')->first(), 'control');
        $response = $this->get('/control?search=1&email=unique-find');

        $response->assertOk();
        $response->assertSee('unique-find@example.test', false);
        $response->assertSee('Search Me', false);
    }

    public function test_search_excludes_daily_ticket_reservations(): void
    {
        $drop = ListOfTimeSlot::query()->create(['time_slot' => '09:00 - 09:20']);
        $pick = ListOfTimeSlot::query()->create(['time_slot' => '17:00 - 17:20']);
        $vehicleType = $this->createVehicleType('Car');
        $this->createControlAdmin();
        $date = now()->addDays(4)->format('Y-m-d');

        Reservation::query()->create([
            'drop_off_time_slot_id' => $drop->id,
            'pick_up_time_slot_id' => $pick->id,
            'reservation_date' => $date,
            'user_name' => 'Termini User',
            'country' => 'ME',
            'license_plate' => 'PG SLOT1',
            'vehicle_type_id' => $vehicleType->id,
            'email' => 'shared-search@example.test',
            'status' => 'paid',
            'reservation_kind' => ReservationKind::TIME_SLOTS,
        ]);

        Reservation::query()->create([
            'drop_off_time_slot_id' => null,
            'pick_up_time_slot_id' => null,
            'reservation_date' => $date,
            'user_name' => 'Daily Fee User',
            'country' => 'ME',
            'license_plate' => 'PG DAILY1',
            'vehicle_type_id' => $vehicleType->id,
            'email' => 'shared-search@example.test',
            'status' => 'paid',
            'reservation_kind' => ReservationKind::DAILY_TICKET,
        ]);

        $this->actingAs(Admin::where('email', 'field@example.test')->first(), 'control');
        $response = $this->get('/control?search=1&email=shared-search&date='.$date);

        $response->assertOk();
        $response->assertSee('Termini User', false);
        $response->assertSee('PG SLOT1', false);
        $response->assertDontSee('Daily Fee User', false);
        $response->assertDontSee('PG DAILY1', false);
    }

    public function test_search_with_empty_criteria_shows_validation_error(): void
    {
        $this->createControlAdmin();
        $this->actingAs(Admin::where('email', 'field@example.test')->first(), 'control');

        $response = $this->get('/control?search=1');

        $response->assertSessionHasErrors('search');
    }

    public function test_search_filters_by_paid_or_free_status(): void
    {
        $drop = ListOfTimeSlot::query()->create(['time_slot' => '09:00 - 09:20']);
        $pick = ListOfTimeSlot::query()->create(['time_slot' => '17:00 - 17:20']);
        $vehicleType = $this->createVehicleType('Car');
        $this->createControlAdmin();
        $date = now()->addDays(3)->format('Y-m-d');

        Reservation::query()->create([
            'drop_off_time_slot_id' => $drop->id,
            'pick_up_time_slot_id' => $pick->id,
            'reservation_date' => $date,
            'user_name' => 'Paid User',
            'country' => 'ME',
            'license_plate' => 'PG PAID1',
            'vehicle_type_id' => $vehicleType->id,
            'email' => 'paid@example.test',
            'status' => 'paid',
        ]);

        Reservation::query()->create([
            'drop_off_time_slot_id' => $drop->id,
            'pick_up_time_slot_id' => $pick->id,
            'reservation_date' => $date,
            'user_name' => 'Free User',
            'country' => 'ME',
            'license_plate' => 'PG FREE1',
            'vehicle_type_id' => $vehicleType->id,
            'email' => 'free@example.test',
            'status' => 'free',
        ]);

        $admin = Admin::where('email', 'field@example.test')->first();
        $this->actingAs($admin, 'control');

        $this->get('/control?search=1&status=paid&date='.$date)
            ->assertOk()
            ->assertSee('PG PAID1', false)
            ->assertDontSee('PG FREE1', false);

        $this->get('/control?search=1&status=free&date='.$date)
            ->assertOk()
            ->assertSee('PG FREE1', false)
            ->assertDontSee('PG PAID1', false);
    }

    public function test_search_by_status_alone_is_valid_criteria(): void
    {
        $this->createControlAdmin();
        $this->actingAs(Admin::where('email', 'field@example.test')->first(), 'control');

        $this->get('/control?search=1&status=free')
            ->assertOk()
            ->assertSessionDoesntHaveErrors('search');
    }

    private function createControlAdmin(): Admin
    {
        return Admin::query()->create([
            'username' => 'field1',
            'email' => 'field@example.test',
            'password' => 'secret-pass',
            'control_access' => true,
        ]);
    }

    private function createVehicleType(string $nameEn): VehicleType
    {
        $vt = VehicleType::query()->create(['price' => 10]);
        VehicleTypeTranslation::query()->create([
            'vehicle_type_id' => $vt->id,
            'locale' => 'en',
            'name' => $nameEn,
            'description' => null,
        ]);
        VehicleTypeTranslation::query()->create([
            'vehicle_type_id' => $vt->id,
            'locale' => 'cg',
            'name' => $nameEn,
            'description' => null,
        ]);

        return $vt;
    }
}
