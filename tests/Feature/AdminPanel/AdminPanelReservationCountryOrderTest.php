<?php

namespace Tests\Feature\AdminPanel;

use App\Models\Admin;
use App\Models\DailyParkingData;
use App\Models\ListOfTimeSlot;
use App\Models\Reservation;
use App\Models\VehicleType;
use App\Models\VehicleTypeTranslation;
use App\Support\BankartBillingCountry;
use App\Support\ReservationKind;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

final class AdminPanelReservationCountryOrderTest extends TestCase
{
    use RefreshDatabase;

    private function seedAdmin(): Admin
    {
        return Admin::query()->create([
            'username' => 'countryorderadmin',
            'email' => 'country-order@example.com',
            'password' => bcrypt('secret-password-co'),
            'control_access' => false,
            'admin_access' => true,
        ]);
    }

    /**
     * @return array{s1: ListOfTimeSlot, s2: ListOfTimeSlot, vt: VehicleType}
     */
    private function seedSlotsAndVehicle(): array
    {
        $s1 = ListOfTimeSlot::query()->create(['time_slot' => '10:00 - 10:20']);
        $s2 = ListOfTimeSlot::query()->create(['time_slot' => '11:00 - 11:20']);
        $vt = VehicleType::query()->create(['price' => 15]);
        foreach (['en', 'cg'] as $locale) {
            VehicleTypeTranslation::query()->create([
                'vehicle_type_id' => $vt->id,
                'locale' => $locale,
                'name' => 'Bus',
                'description' => null,
            ]);
        }

        return ['s1' => $s1, 's2' => $s2, 'vt' => $vt];
    }

    public function test_admin_reservation_search_country_dropdown_shows_preferred_order_first(): void
    {
        $admin = $this->seedAdmin();
        $this->actingAs($admin, 'panel_admin');

        $html = $this->get(route('panel_admin.reservations', [], false))
            ->assertOk()
            ->getContent();

        $this->assertCountrySelectStartsWithPreferredOrder($html);
    }

    public function test_admin_reservation_edit_country_dropdown_uses_helper_order(): void
    {
        $admin = $this->seedAdmin();
        $d = Carbon::now()->addDays(3)->toDateString();
        $slots = $this->seedSlotsAndVehicle();
        DailyParkingData::query()->create([
            'date' => $d,
            'time_slot_id' => $slots['s1']->id,
            'capacity' => 5,
            'reserved' => 0,
            'pending' => 0,
            'is_blocked' => false,
        ]);
        DailyParkingData::query()->create([
            'date' => $d,
            'time_slot_id' => $slots['s2']->id,
            'capacity' => 5,
            'reserved' => 0,
            'pending' => 0,
            'is_blocked' => false,
        ]);

        $reservation = Reservation::query()->create([
            'merchant_transaction_id' => 'mt-admin-country-order-'.uniqid(),
            'drop_off_time_slot_id' => $slots['s1']->id,
            'pick_up_time_slot_id' => $slots['s2']->id,
            'reservation_date' => $d,
            'user_name' => 'Order Test',
            'country' => 'ME',
            'license_plate' => 'KO999OT',
            'vehicle_type_id' => $slots['vt']->id,
            'email' => 'order@test.local',
            'status' => 'paid',
            'invoice_amount' => '15.00',
            'email_sent' => Reservation::EMAIL_NOT_SENT,
        ]);

        $this->actingAs($admin, 'panel_admin');

        $html = $this->get(route('panel_admin.reservations.edit', ['reservation' => $reservation], false))
            ->assertOk()
            ->getContent();

        $this->assertCountrySelectStartsWithPreferredOrder($html);
    }

    public function test_admin_daily_ticket_edit_country_dropdown_uses_helper_order(): void
    {
        $admin = $this->seedAdmin();
        $d = Carbon::now()->addDays(4)->toDateString();
        $vt = VehicleType::query()->create(['price' => 20]);

        $reservation = Reservation::query()->create([
            'merchant_transaction_id' => 'mt-admin-daily-country-order',
            'reservation_kind' => ReservationKind::DAILY_TICKET,
            'drop_off_time_slot_id' => null,
            'pick_up_time_slot_id' => null,
            'reservation_date' => $d,
            'user_name' => 'Daily Order',
            'country' => 'ME',
            'license_plate' => 'KO888DT',
            'vehicle_type_id' => $vt->id,
            'email' => 'daily-order@test.local',
            'status' => 'paid',
            'invoice_amount' => '20.00',
            'email_sent' => Reservation::EMAIL_NOT_SENT,
        ]);

        $this->actingAs($admin, 'panel_admin');

        $html = $this->get(route('panel_admin.reservations.edit', ['reservation' => $reservation], false))
            ->assertOk()
            ->getContent();

        $this->assertCountrySelectStartsWithPreferredOrder($html);
    }

    public function test_guest_dropdown_still_uses_same_preferred_order(): void
    {
        $html = $this->get(route('guest.reserve', [], false))->assertOk()->getContent();
        $this->assertCountrySelectStartsWithPreferredOrder($html);
    }

    public function test_countries_config_remains_alphabetical_by_iso_code(): void
    {
        $codes = array_keys((array) config('countries', []));

        $this->assertNotEmpty($codes);
        $this->assertSame('AD', $codes[0]);
        $this->assertSame(
            ['ME', 'RS', 'HR'],
            array_slice(array_keys(BankartBillingCountry::selectableCountries('en')), 0, 3),
        );
    }

    private function assertCountrySelectStartsWithPreferredOrder(string $html): void
    {
        $this->assertMatchesRegularExpression('/<select[^>]*id="country"[^>]*>/', $html);

        preg_match('/<select[^>]*id="country"[^>]*>(.*?)<\/select>/s', $html, $selectMatch);
        $selectBody = $selectMatch[1] ?? '';

        preg_match_all('/<option value="([A-Z]{2})"/', $selectBody, $matches);
        $codes = $matches[1] ?? [];

        $this->assertNotEmpty($codes, 'No country options found in country select');
        $this->assertSame('ME', $codes[0]);
        $this->assertSame('RS', $codes[1]);
        $this->assertSame('HR', $codes[2]);
    }
}
