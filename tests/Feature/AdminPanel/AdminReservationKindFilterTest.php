<?php

namespace Tests\Feature\AdminPanel;

use App\Models\Admin;
use App\Models\ListOfTimeSlot;
use App\Models\Reservation;
use App\Models\VehicleType;
use App\Support\ReservationKind;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AdminReservationKindFilterTest extends TestCase
{
    use RefreshDatabase;

    private function seedAdmin(): Admin
    {
        return Admin::query()->create([
            'username' => 'kindfilteradmin',
            'email' => 'kind-filter@example.com',
            'password' => bcrypt('secret-password-kf'),
            'control_access' => false,
            'admin_access' => true,
        ]);
    }

    /**
     * @return array{drop: ListOfTimeSlot, pick: ListOfTimeSlot, vt: VehicleType}
     */
    private function seedSlotsAndVehicle(): array
    {
        $drop = ListOfTimeSlot::query()->create(['time_slot' => '10:00 - 10:20']);
        $pick = ListOfTimeSlot::query()->create(['time_slot' => '11:00 - 11:20']);
        $vt = VehicleType::query()->create(['price' => 15]);

        return ['drop' => $drop, 'pick' => $pick, 'vt' => $vt];
    }

    public function test_no_kind_filter_returns_both_kinds(): void
    {
        $admin = $this->seedAdmin();
        $slots = $this->seedSlotsAndVehicle();
        $d = Carbon::now()->addDays(20)->toDateString();

        Reservation::query()->create([
            'merchant_transaction_id' => 'mt-kind-both-ts',
            'reservation_kind' => ReservationKind::TIME_SLOTS,
            'drop_off_time_slot_id' => $slots['drop']->id,
            'pick_up_time_slot_id' => $slots['pick']->id,
            'reservation_date' => $d,
            'user_name' => 'Termini User',
            'country' => 'ME',
            'license_plate' => 'KOTS01',
            'vehicle_type_id' => $slots['vt']->id,
            'email' => 'ts@example.com',
            'status' => 'paid',
            'invoice_amount' => '15.00',
            'email_sent' => Reservation::EMAIL_NOT_SENT,
        ]);

        Reservation::query()->create([
            'merchant_transaction_id' => 'mt-kind-both-dk',
            'reservation_kind' => ReservationKind::DAILY_TICKET,
            'drop_off_time_slot_id' => null,
            'pick_up_time_slot_id' => null,
            'reservation_date' => $d,
            'user_name' => 'Daily User',
            'country' => 'ME',
            'license_plate' => 'KODK01',
            'vehicle_type_id' => $slots['vt']->id,
            'email' => 'dk@example.com',
            'status' => 'paid',
            'invoice_amount' => '20.00',
            'email_sent' => Reservation::EMAIL_NOT_SENT,
        ]);

        $this->actingAs($admin, 'panel_admin');

        $this->get(route('panel_admin.reservations', ['date_single' => $d], false))
            ->assertOk()
            ->assertSee('mt-kind-both-ts', false)
            ->assertSee('mt-kind-both-dk', false)
            ->assertSee('Dnevna karta', false)
            ->assertSee('Dolazak', false);
    }

    public function test_termini_filter_returns_only_time_slots(): void
    {
        $admin = $this->seedAdmin();
        $slots = $this->seedSlotsAndVehicle();
        $d = Carbon::now()->addDays(21)->toDateString();

        Reservation::query()->create([
            'merchant_transaction_id' => 'mt-kind-ts-only',
            'reservation_kind' => ReservationKind::TIME_SLOTS,
            'drop_off_time_slot_id' => $slots['drop']->id,
            'pick_up_time_slot_id' => $slots['pick']->id,
            'reservation_date' => $d,
            'user_name' => 'Termini Only',
            'country' => 'ME',
            'license_plate' => 'KOTS02',
            'vehicle_type_id' => $slots['vt']->id,
            'email' => 'ts-only@example.com',
            'status' => 'paid',
            'invoice_amount' => '15.00',
            'email_sent' => Reservation::EMAIL_NOT_SENT,
        ]);

        Reservation::query()->create([
            'merchant_transaction_id' => 'mt-kind-ts-hidden-dk',
            'reservation_kind' => ReservationKind::DAILY_TICKET,
            'drop_off_time_slot_id' => null,
            'pick_up_time_slot_id' => null,
            'reservation_date' => $d,
            'user_name' => 'Hidden Daily',
            'country' => 'ME',
            'license_plate' => 'KODK02',
            'vehicle_type_id' => $slots['vt']->id,
            'email' => 'hidden@example.com',
            'status' => 'paid',
            'invoice_amount' => '20.00',
            'email_sent' => Reservation::EMAIL_NOT_SENT,
        ]);

        $this->actingAs($admin, 'panel_admin');

        $this->get(route('panel_admin.reservations', [
            'date_single' => $d,
            'reservation_kind' => ReservationKind::TIME_SLOTS,
        ], false))
            ->assertOk()
            ->assertSee('mt-kind-ts-only', false)
            ->assertSee('Dolazak', false)
            ->assertDontSee('mt-kind-ts-hidden-dk', false)
            ->assertDontSee('hidden@example.com', false);
    }

    public function test_daily_ticket_filter_returns_only_daily_ticket(): void
    {
        $admin = $this->seedAdmin();
        $slots = $this->seedSlotsAndVehicle();
        $d = Carbon::now()->addDays(22)->toDateString();

        Reservation::query()->create([
            'merchant_transaction_id' => 'mt-kind-dk-hidden-ts',
            'reservation_kind' => ReservationKind::TIME_SLOTS,
            'drop_off_time_slot_id' => $slots['drop']->id,
            'pick_up_time_slot_id' => $slots['pick']->id,
            'reservation_date' => $d,
            'user_name' => 'Hidden Termini',
            'country' => 'ME',
            'license_plate' => 'KOTS03',
            'vehicle_type_id' => $slots['vt']->id,
            'email' => 'hidden-ts@example.com',
            'status' => 'paid',
            'invoice_amount' => '15.00',
            'email_sent' => Reservation::EMAIL_NOT_SENT,
        ]);

        Reservation::query()->create([
            'merchant_transaction_id' => 'mt-kind-dk-only',
            'reservation_kind' => ReservationKind::DAILY_TICKET,
            'drop_off_time_slot_id' => null,
            'pick_up_time_slot_id' => null,
            'reservation_date' => $d,
            'user_name' => 'Daily Only',
            'country' => 'ME',
            'license_plate' => 'KODK03',
            'vehicle_type_id' => $slots['vt']->id,
            'email' => 'dk-only@example.com',
            'status' => 'paid',
            'invoice_amount' => '20.00',
            'email_sent' => Reservation::EMAIL_NOT_SENT,
        ]);

        $this->actingAs($admin, 'panel_admin');

        $this->get(route('panel_admin.reservations', [
            'date_single' => $d,
            'reservation_kind' => ReservationKind::DAILY_TICKET,
        ], false))
            ->assertOk()
            ->assertSee('mt-kind-dk-only', false)
            ->assertSee('Dnevna karta', false)
            ->assertDontSee('mt-kind-dk-hidden-ts', false)
            ->assertDontSee('hidden-ts@example.com', false);
    }

    public function test_kind_filter_combines_with_status_and_plate(): void
    {
        $admin = $this->seedAdmin();
        $slots = $this->seedSlotsAndVehicle();
        $d = Carbon::now()->addDays(23)->toDateString();

        Reservation::query()->create([
            'merchant_transaction_id' => 'mt-combo-match',
            'reservation_kind' => ReservationKind::DAILY_TICKET,
            'drop_off_time_slot_id' => null,
            'pick_up_time_slot_id' => null,
            'reservation_date' => $d,
            'user_name' => 'Combo Match',
            'country' => 'ME',
            'license_plate' => 'KOCMB1',
            'vehicle_type_id' => $slots['vt']->id,
            'email' => 'combo-match@example.com',
            'status' => 'paid',
            'invoice_amount' => '20.00',
            'email_sent' => Reservation::EMAIL_NOT_SENT,
        ]);

        Reservation::query()->create([
            'merchant_transaction_id' => 'mt-combo-wrong-status',
            'reservation_kind' => ReservationKind::DAILY_TICKET,
            'drop_off_time_slot_id' => null,
            'pick_up_time_slot_id' => null,
            'reservation_date' => $d,
            'user_name' => 'Combo Free',
            'country' => 'ME',
            'license_plate' => 'KOCMB2',
            'vehicle_type_id' => $slots['vt']->id,
            'email' => 'combo-free@example.com',
            'status' => 'free',
            'invoice_amount' => '0.00',
            'email_sent' => Reservation::EMAIL_NOT_SENT,
        ]);

        Reservation::query()->create([
            'merchant_transaction_id' => 'mt-combo-wrong-kind',
            'reservation_kind' => ReservationKind::TIME_SLOTS,
            'drop_off_time_slot_id' => $slots['drop']->id,
            'pick_up_time_slot_id' => $slots['pick']->id,
            'reservation_date' => $d,
            'user_name' => 'Combo Termini',
            'country' => 'ME',
            'license_plate' => 'KOCMB3',
            'vehicle_type_id' => $slots['vt']->id,
            'email' => 'combo-ts@example.com',
            'status' => 'paid',
            'invoice_amount' => '15.00',
            'email_sent' => Reservation::EMAIL_NOT_SENT,
        ]);

        $this->actingAs($admin, 'panel_admin');

        $this->get(route('panel_admin.reservations', [
            'date_single' => $d,
            'reservation_kind' => ReservationKind::DAILY_TICKET,
            'status' => 'paid',
            'license_plate' => 'KOCMB1',
        ], false))
            ->assertOk()
            ->assertSee('mt-combo-match', false)
            ->assertDontSee('mt-combo-wrong-status', false)
            ->assertDontSee('mt-combo-wrong-kind', false);
    }
}
