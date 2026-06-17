<?php

namespace Tests\Feature\Panel;

use App\Models\DailyParkingData;
use App\Models\ListOfTimeSlot;
use App\Models\Reservation;
use App\Models\TempData;
use App\Models\User;
use App\Models\Vehicle;
use App\Models\VehicleType;
use App\Support\ReservationKind;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

final class AgencyTerminiVehicleDropdownFilterTest extends TestCase
{
    use RefreshDatabase;

    private const HINT_EN = 'Some vehicles are not shown because they already have a reservation on the selected date with the same arrival or departure time.';

    private const HINT_CG = 'Neka vozila nisu prikazana jer za odabrani datum već imaju rezervaciju sa istim vremenom dolaska ili odlaska.';

    /**
     * @return array{user: User, vt: VehicleType, date: string, drop: ListOfTimeSlot, pick: ListOfTimeSlot, other: ListOfTimeSlot}
     */
    private function seedBaseScenario(): array
    {
        $user = User::factory()->create(['email_verified_at' => now(), 'country' => 'ME', 'lang' => 'en']);
        $vt = VehicleType::query()->create(['price' => 10]);
        $date = now()->addDays(3)->toDateString();

        $drop = ListOfTimeSlot::query()->create(['time_slot' => '10:00 - 10:20']);
        $pick = ListOfTimeSlot::query()->create(['time_slot' => '11:00 - 11:20']);
        $other = ListOfTimeSlot::query()->create(['time_slot' => '12:00 - 12:20']);

        foreach ([$drop->id, $pick->id, $other->id] as $slotId) {
            DailyParkingData::query()->create([
                'date' => $date,
                'time_slot_id' => $slotId,
                'capacity' => 5,
                'reserved' => 0,
                'pending' => 0,
                'is_blocked' => false,
            ]);
        }

        return compact('user', 'vt', 'date', 'drop', 'pick', 'other');
    }

    private function makeVehicle(User $user, VehicleType $vt, string $plate): Vehicle
    {
        return Vehicle::query()->create([
            'user_id' => $user->id,
            'license_plate' => $plate,
            'vehicle_type_id' => $vt->id,
        ]);
    }

    private function makeTerminiReservation(
        VehicleType $vt,
        string $date,
        int $dropId,
        int $pickId,
        string $plate,
        string $status = 'paid',
    ): Reservation {
        return Reservation::query()->create([
            'merchant_transaction_id' => 'mt-'.uniqid('', true),
            'reservation_kind' => ReservationKind::TIME_SLOTS,
            'drop_off_time_slot_id' => $dropId,
            'pick_up_time_slot_id' => $pickId,
            'reservation_date' => $date,
            'user_name' => 'Agency',
            'country' => 'ME',
            'license_plate' => $plate,
            'vehicle_type_id' => $vt->id,
            'email' => 'a@example.com',
            'status' => $status,
            'invoice_amount' => '10.00',
            'email_sent' => Reservation::EMAIL_NOT_SENT,
            'created_by_admin' => false,
        ]);
    }

    /**
     * @param  array<string, mixed>  $query
     */
    private function panelHtml(User $user, array $query): string
    {
        return $this->actingAs($user)
            ->get(route('panel.reservations', $query, false))
            ->assertOk()
            ->getContent();
    }

    private function assertVehicleInDropdown(string $html, Vehicle $vehicle, bool $expected): void
    {
        if (! preg_match('/<select[^>]*id="vehicle_id_panel"[^>]*>(.*?)<\/select>/s', $html, $matches)) {
            $this->fail('vehicle_id_panel select not found in panel reservations page.');
        }

        $optionsHtml = $matches[1];
        $needle = 'value="'.$vehicle->id.'"';
        if ($expected) {
            $this->assertStringContainsString($needle, $optionsHtml);
        } else {
            $this->assertStringNotContainsString($needle, $optionsHtml);
        }
    }

    public function test_termini_dropdown_hides_vehicle_with_same_arrival_conflict(): void
    {
        ['user' => $user, 'vt' => $vt, 'date' => $date, 'drop' => $drop, 'pick' => $pick, 'other' => $other] = $this->seedBaseScenario();
        $conflict = $this->makeVehicle($user, $vt, 'KO111AA');
        $allowed = $this->makeVehicle($user, $vt, 'KO222BB');

        $this->makeTerminiReservation($vt, $date, $drop->id, $pick->id, $conflict->license_plate);

        $html = $this->panelHtml($user, [
            'reservation_kind' => ReservationKind::TIME_SLOTS,
            'reservation_date' => $date,
            'drop_off_time_slot_id' => $drop->id,
            'pick_up_time_slot_id' => $other->id,
        ]);

        $this->assertVehicleInDropdown($html, $conflict, false);
        $this->assertVehicleInDropdown($html, $allowed, true);
    }

    public function test_termini_dropdown_hides_vehicle_with_same_departure_conflict(): void
    {
        ['user' => $user, 'vt' => $vt, 'date' => $date, 'drop' => $drop, 'pick' => $pick] = $this->seedBaseScenario();
        $early = ListOfTimeSlot::query()->create(['time_slot' => '09:00 - 09:20']);
        DailyParkingData::query()->create([
            'date' => $date,
            'time_slot_id' => $early->id,
            'capacity' => 5,
            'reserved' => 0,
            'pending' => 0,
            'is_blocked' => false,
        ]);

        $conflict = $this->makeVehicle($user, $vt, 'KO333CC');
        $allowed = $this->makeVehicle($user, $vt, 'KO444DD');

        $this->makeTerminiReservation($vt, $date, $drop->id, $pick->id, $conflict->license_plate);

        $html = $this->panelHtml($user, [
            'reservation_kind' => ReservationKind::TIME_SLOTS,
            'reservation_date' => $date,
            'drop_off_time_slot_id' => $early->id,
            'pick_up_time_slot_id' => $pick->id,
        ]);

        $this->assertVehicleInDropdown($html, $conflict, false);
        $this->assertVehicleInDropdown($html, $allowed, true);
    }

    public function test_termini_dropdown_does_not_hide_vehicle_for_cross_match_only(): void
    {
        ['user' => $user, 'vt' => $vt, 'date' => $date, 'drop' => $drop, 'pick' => $pick, 'other' => $other] = $this->seedBaseScenario();
        $crossOk = $this->makeVehicle($user, $vt, 'KO555EE');

        $this->makeTerminiReservation($vt, $date, $drop->id, $pick->id, $crossOk->license_plate);

        $html = $this->panelHtml($user, [
            'reservation_kind' => ReservationKind::TIME_SLOTS,
            'reservation_date' => $date,
            'drop_off_time_slot_id' => $pick->id,
            'pick_up_time_slot_id' => $other->id,
        ]);

        $this->assertVehicleInDropdown($html, $crossOk, true);
    }

    public function test_termini_dropdown_does_not_hide_vehicle_when_both_slots_differ(): void
    {
        ['user' => $user, 'vt' => $vt, 'date' => $date, 'drop' => $drop, 'pick' => $pick, 'other' => $other] = $this->seedBaseScenario();
        $fourth = ListOfTimeSlot::query()->create(['time_slot' => '13:00 - 13:20']);
        DailyParkingData::query()->create([
            'date' => $date,
            'time_slot_id' => $fourth->id,
            'capacity' => 5,
            'reserved' => 0,
            'pending' => 0,
            'is_blocked' => false,
        ]);

        $vehicle = $this->makeVehicle($user, $vt, 'KO666FF');
        $this->makeTerminiReservation($vt, $date, $drop->id, $pick->id, $vehicle->license_plate);

        $html = $this->panelHtml($user, [
            'reservation_kind' => ReservationKind::TIME_SLOTS,
            'reservation_date' => $date,
            'drop_off_time_slot_id' => $other->id,
            'pick_up_time_slot_id' => $fourth->id,
        ]);

        $this->assertVehicleInDropdown($html, $vehicle, true);
    }

    public function test_daily_fee_dropdown_still_shows_vehicle_with_slot_conflict(): void
    {
        ['user' => $user, 'vt' => $vt, 'date' => $date, 'drop' => $drop, 'pick' => $pick] = $this->seedBaseScenario();
        $vehicle = $this->makeVehicle($user, $vt, 'KO777GG');

        $this->makeTerminiReservation($vt, $date, $drop->id, $pick->id, $vehicle->license_plate);

        $html = $this->panelHtml($user, [
            'reservation_kind' => ReservationKind::DAILY_TICKET,
            'reservation_date' => $date,
            'vehicle_id' => $vehicle->id,
        ]);

        $this->assertVehicleInDropdown($html, $vehicle, true);
        $this->assertStringNotContainsString('id="panelTerminiVehiclesHiddenHint"', $html);
    }

    public function test_pending_temp_data_conflict_hides_vehicle(): void
    {
        ['user' => $user, 'vt' => $vt, 'date' => $date, 'drop' => $drop, 'pick' => $pick, 'other' => $other] = $this->seedBaseScenario();
        $conflict = $this->makeVehicle($user, $vt, 'KO888HH');
        $allowed = $this->makeVehicle($user, $vt, 'KO999II');

        TempData::query()->create([
            'merchant_transaction_id' => 'mt-pending-1',
            'reservation_kind' => ReservationKind::TIME_SLOTS,
            'drop_off_time_slot_id' => $drop->id,
            'pick_up_time_slot_id' => $pick->id,
            'reservation_date' => $date,
            'user_name' => 'Pending',
            'country' => 'ME',
            'license_plate' => $conflict->license_plate,
            'vehicle_type_id' => $vt->id,
            'email' => 'p@example.com',
            'status' => TempData::STATUS_PENDING,
        ]);

        $html = $this->panelHtml($user, [
            'reservation_kind' => ReservationKind::TIME_SLOTS,
            'reservation_date' => $date,
            'drop_off_time_slot_id' => $drop->id,
            'pick_up_time_slot_id' => $other->id,
        ]);

        $this->assertVehicleInDropdown($html, $conflict, false);
        $this->assertVehicleInDropdown($html, $allowed, true);
    }

    public function test_expired_and_canceled_temp_data_do_not_hide_vehicle(): void
    {
        ['user' => $user, 'vt' => $vt, 'date' => $date, 'drop' => $drop, 'pick' => $pick, 'other' => $other] = $this->seedBaseScenario();
        $vehicle = $this->makeVehicle($user, $vt, 'KO000JJ');

        foreach ([TempData::STATUS_EXPIRED, TempData::STATUS_CANCELED] as $status) {
            TempData::query()->create([
                'merchant_transaction_id' => 'mt-'.$status,
                'reservation_kind' => ReservationKind::TIME_SLOTS,
                'drop_off_time_slot_id' => $drop->id,
                'pick_up_time_slot_id' => $pick->id,
                'reservation_date' => $date,
                'user_name' => 'Old',
                'country' => 'ME',
                'license_plate' => $vehicle->license_plate,
                'vehicle_type_id' => $vt->id,
                'email' => 'old@example.com',
                'status' => $status,
            ]);
        }

        $html = $this->panelHtml($user, [
            'reservation_kind' => ReservationKind::TIME_SLOTS,
            'reservation_date' => $date,
            'drop_off_time_slot_id' => $drop->id,
            'pick_up_time_slot_id' => $other->id,
        ]);

        $this->assertVehicleInDropdown($html, $vehicle, true);
    }

    public function test_hidden_vehicle_shows_explanatory_note(): void
    {
        ['user' => $user, 'vt' => $vt, 'date' => $date, 'drop' => $drop, 'pick' => $pick, 'other' => $other] = $this->seedBaseScenario();
        $conflict = $this->makeVehicle($user, $vt, 'KO111KK');
        $this->makeVehicle($user, $vt, 'KO222LL');

        $this->makeTerminiReservation($vt, $date, $drop->id, $pick->id, $conflict->license_plate);

        $html = $this->panelHtml($user, [
            'reservation_kind' => ReservationKind::TIME_SLOTS,
            'reservation_date' => $date,
            'drop_off_time_slot_id' => $drop->id,
            'pick_up_time_slot_id' => $other->id,
        ]);

        $this->assertStringContainsString('id="panelTerminiVehiclesHiddenHint"', $html);
        $this->assertStringContainsString(self::HINT_EN, $html);
    }

    public function test_no_hidden_vehicle_does_not_show_explanatory_note(): void
    {
        ['user' => $user, 'vt' => $vt, 'date' => $date, 'drop' => $drop, 'pick' => $pick, 'other' => $other] = $this->seedBaseScenario();
        $this->makeVehicle($user, $vt, 'KO333MM');

        $html = $this->panelHtml($user, [
            'reservation_kind' => ReservationKind::TIME_SLOTS,
            'reservation_date' => $date,
            'drop_off_time_slot_id' => $drop->id,
            'pick_up_time_slot_id' => $other->id,
        ]);

        $this->assertStringNotContainsString('id="panelTerminiVehiclesHiddenHint"', $html);
        $this->assertStringNotContainsString(self::HINT_EN, $html);
    }

    public function test_hint_renders_in_croatian_locale(): void
    {
        ['user' => $user, 'vt' => $vt, 'date' => $date, 'drop' => $drop, 'pick' => $pick, 'other' => $other] = $this->seedBaseScenario();
        $user->update(['lang' => 'cg']);
        app()->setLocale('cg');

        $conflict = $this->makeVehicle($user, $vt, 'KO444NN');
        $this->makeVehicle($user, $vt, 'KO555OO');
        $this->makeTerminiReservation($vt, $date, $drop->id, $pick->id, $conflict->license_plate);

        $html = $this->panelHtml($user, [
            'reservation_kind' => ReservationKind::TIME_SLOTS,
            'reservation_date' => $date,
            'drop_off_time_slot_id' => $drop->id,
            'pick_up_time_slot_id' => $other->id,
        ]);

        $this->assertStringContainsString(self::HINT_CG, $html);
    }
}
