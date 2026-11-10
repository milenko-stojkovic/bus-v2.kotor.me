<?php

namespace Tests\Feature\Panel;

use App\Jobs\ProcessReservationAfterPaymentJob;
use App\Models\AgencyAdvanceTransaction;
use App\Models\DailyParkingData;
use App\Models\ListOfTimeSlot;
use App\Models\Reservation;
use App\Models\User;
use App\Models\Vehicle;
use App\Models\VehicleType;
use App\Services\AgencyAdvance\AgencyAdvanceService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

final class AdvanceReservationPaymentTest extends TestCase
{
    use RefreshDatabase;

    public function test_feature_flag_off_hides_advance_and_rejects_advance_method(): void
    {
        config(['features.advance_payments' => false]);

        $user = User::factory()->create(['email_verified_at' => now(), 'country' => 'ME']);
        $this->actingAs($user);

        $html = $this->get(route('panel.reservations', [], false))->assertOk()->getContent();
        $this->assertStringNotContainsString('Raspoloživi avans', $html);

        $drop = ListOfTimeSlot::query()->create(['time_slot' => '10:00 - 10:20']);
        $pick = ListOfTimeSlot::query()->create(['time_slot' => '11:00 - 11:20']);
        $date = now()->addDays(3)->toDateString();
        DailyParkingData::query()->create(['date' => $date, 'time_slot_id' => $drop->id, 'capacity' => 9, 'reserved' => 0, 'pending' => 0, 'is_blocked' => false]);
        DailyParkingData::query()->create(['date' => $date, 'time_slot_id' => $pick->id, 'capacity' => 9, 'reserved' => 0, 'pending' => 0, 'is_blocked' => false]);

        $vt = VehicleType::query()->create(['price' => 10]);
        $v = Vehicle::query()->create(['user_id' => $user->id, 'license_plate' => 'KO111AA', 'vehicle_type_id' => $vt->id]);

        $this->post(route('checkout.store', [], false), [
            'auth_panel_booking' => 1,
            'payment_method' => 'advance',
            'reservation_date' => $date,
            'drop_off_time_slot_id' => $drop->id,
            'pick_up_time_slot_id' => $pick->id,
            'vehicle_id' => $v->id,
            'accept_terms' => 1,
            'accept_privacy' => 1,
        ])->assertSessionHasErrors('payment_method');
    }

    public function test_feature_flag_on_sufficient_balance_creates_paid_reservation_from_advance_without_temp_data_and_creates_usage_ledger_and_increments_reserved_and_dispatches_pipeline(): void
    {
        config(['features.advance_payments' => true]);
        config(['services.bank.driver' => 'bankart']); // ensure we are not using fake bank path

        Bus::fake();

        $user = User::factory()->create(['email_verified_at' => now(), 'country' => 'ME']);
        $this->actingAs($user);

        $drop = ListOfTimeSlot::query()->create(['time_slot' => '10:00 - 10:20']);
        $pick = ListOfTimeSlot::query()->create(['time_slot' => '11:00 - 11:20']);
        $date = now()->addDays(3)->toDateString();
        $d1 = DailyParkingData::query()->create(['date' => $date, 'time_slot_id' => $drop->id, 'capacity' => 9, 'reserved' => 0, 'pending' => 0, 'is_blocked' => false]);
        $d2 = DailyParkingData::query()->create(['date' => $date, 'time_slot_id' => $pick->id, 'capacity' => 9, 'reserved' => 0, 'pending' => 0, 'is_blocked' => false]);

        $vt = VehicleType::query()->create(['price' => 10]);
        $v = Vehicle::query()->create(['user_id' => $user->id, 'license_plate' => 'KO111AA', 'vehicle_type_id' => $vt->id]);

        // Seed advance balance +20
        AgencyAdvanceTransaction::query()->create([
            'agency_user_id' => $user->id,
            'amount' => '20.00',
            'type' => AgencyAdvanceTransaction::TYPE_TOPUP,
            'reference_type' => 'advance_topup',
            'reference_id' => 1,
            'merchant_transaction_id' => 'mtid_seed',
            'note' => 'seed',
        ]);

        $this->post(route('checkout.store', [], false), [
            'auth_panel_booking' => 1,
            'payment_method' => 'advance',
            'reservation_date' => $date,
            'drop_off_time_slot_id' => $drop->id,
            'pick_up_time_slot_id' => $pick->id,
            'vehicle_id' => $v->id,
            'accept_terms' => 1,
            'accept_privacy' => 1,
            'merchant_transaction_id' => 'mtid_adv_1',
            'confirmed_amount' => '10.00',
        ])->assertRedirect(route('panel.reservations', [], false));

        $res = Reservation::query()->where('merchant_transaction_id', 'mtid_adv_1')->firstOrFail();
        $this->assertSame('paid', (string) $res->status);
        $this->assertSame('advance', (string) $res->payment_method);

        // No temp_data usage
        $this->assertFalse(DB::table('temp_data')->where('merchant_transaction_id', 'mtid_adv_1')->exists());

        // Ledger usage created (-10.00)
        $usage = AgencyAdvanceTransaction::query()
            ->where('type', AgencyAdvanceTransaction::TYPE_USAGE)
            ->where('reference_type', 'reservation')
            ->where('reference_id', $res->id)
            ->firstOrFail();
        $this->assertSame('-10.00', (string) $usage->amount);

        // Balance now 10.00
        $this->assertSame('10.00', app(AgencyAdvanceService::class)->balance($user->id));

        // reserved incremented on both slots; pending untouched
        $d1->refresh();
        $d2->refresh();
        $this->assertSame(1, (int) $d1->reserved);
        $this->assertSame(0, (int) $d1->pending);
        $this->assertSame(1, (int) $d2->reserved);
        $this->assertSame(0, (int) $d2->pending);

        Bus::assertDispatched(ProcessReservationAfterPaymentJob::class);
    }

    public function test_feature_flag_on_insufficient_balance_does_not_create_reservation_or_usage_and_shows_clear_error(): void
    {
        config(['features.advance_payments' => true]);

        $user = User::factory()->create(['email_verified_at' => now(), 'country' => 'ME']);
        $this->actingAs($user);

        $drop = ListOfTimeSlot::query()->create(['time_slot' => '10:00 - 10:20']);
        $pick = ListOfTimeSlot::query()->create(['time_slot' => '11:00 - 11:20']);
        $date = now()->addDays(3)->toDateString();
        DailyParkingData::query()->create(['date' => $date, 'time_slot_id' => $drop->id, 'capacity' => 9, 'reserved' => 0, 'pending' => 0, 'is_blocked' => false]);
        DailyParkingData::query()->create(['date' => $date, 'time_slot_id' => $pick->id, 'capacity' => 9, 'reserved' => 0, 'pending' => 0, 'is_blocked' => false]);

        $vt = VehicleType::query()->create(['price' => 10]);
        $v = Vehicle::query()->create(['user_id' => $user->id, 'license_plate' => 'KO111AA', 'vehicle_type_id' => $vt->id]);

        AgencyAdvanceTransaction::query()->create([
            'agency_user_id' => $user->id,
            'amount' => '5.00',
            'type' => AgencyAdvanceTransaction::TYPE_TOPUP,
            'reference_type' => 'advance_topup',
            'reference_id' => 1,
            'merchant_transaction_id' => 'mtid_seed',
            'note' => 'seed',
        ]);

        $this->post(route('checkout.store', [], false), [
            'auth_panel_booking' => 1,
            'payment_method' => 'advance',
            'reservation_date' => $date,
            'drop_off_time_slot_id' => $drop->id,
            'pick_up_time_slot_id' => $pick->id,
            'vehicle_id' => $v->id,
            'accept_terms' => 1,
            'accept_privacy' => 1,
            'merchant_transaction_id' => 'mtid_adv_2',
            'confirmed_amount' => '10.00',
        ])->assertSessionHas('error');

        $this->assertSame(0, Reservation::query()->where('merchant_transaction_id', 'mtid_adv_2')->count());
        $this->assertSame(0, AgencyAdvanceTransaction::query()->where('type', AgencyAdvanceTransaction::TYPE_USAGE)->count());
    }
}

