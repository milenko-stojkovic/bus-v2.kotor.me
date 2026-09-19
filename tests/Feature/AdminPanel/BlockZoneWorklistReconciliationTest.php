<?php

namespace Tests\Feature\AdminPanel;

use App\Models\Admin;
use App\Models\BlockZoneWorklist;
use App\Models\DailyParkingData;
use App\Models\ListOfTimeSlot;
use App\Models\Reservation;
use App\Models\TempData;
use App\Models\VehicleType;
use App\Models\VehicleTypeTranslation;
use App\Services\AdminPanel\Blocking\BlockZoneWorklistService;
use App\Services\AdminPanel\Blocking\BlockingService;
use App\Services\Payment\PaymentSuccessHandler;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

/**
 * Live worklist membership for confirmed reservations after slot-mutating admin paths.
 */
final class BlockZoneWorklistReconciliationTest extends TestCase
{
    use RefreshDatabase;

    private function admin(string $user = 'wlrecon'): Admin
    {
        return Admin::query()->create([
            'username' => $user,
            'email' => $user.'@example.com',
            'password' => bcrypt('secret-password-wl'),
            'control_access' => false,
            'admin_access' => true,
        ]);
    }

    private function vehicleType(float $price = 10): VehicleType
    {
        $vt = VehicleType::query()->create(['price' => $price]);
        foreach (['en', 'cg'] as $locale) {
            VehicleTypeTranslation::query()->create([
                'vehicle_type_id' => $vt->id,
                'locale' => $locale,
                'name' => 'Bus',
                'description' => null,
            ]);
        }

        return $vt;
    }

    /**
     * @return array{0: ListOfTimeSlot, 1: ListOfTimeSlot, 2: ListOfTimeSlot}
     */
    private function threeSlots(): array
    {
        return [
            ListOfTimeSlot::query()->create(['time_slot' => '08:00 - 08:20']),
            ListOfTimeSlot::query()->create(['time_slot' => '09:00 - 09:20']),
            ListOfTimeSlot::query()->create(['time_slot' => '10:00 - 10:20']),
        ];
    }

    /**
     * Ordered earliest→latest so a free destination for drop can stay before pick.
     *
     * @return array{0: ListOfTimeSlot, 1: ListOfTimeSlot, 2: ListOfTimeSlot, 3: ListOfTimeSlot}
     */
    private function fourSlots(): array
    {
        return [
            ListOfTimeSlot::query()->create(['time_slot' => '08:00 - 08:20']),
            ListOfTimeSlot::query()->create(['time_slot' => '09:00 - 09:20']),
            ListOfTimeSlot::query()->create(['time_slot' => '10:00 - 10:20']),
            ListOfTimeSlot::query()->create(['time_slot' => '11:00 - 11:20']),
        ];
    }

    private function parking(
        string $date,
        int $slotId,
        int $capacity = 5,
        int $reserved = 0,
        int $pending = 0,
        bool $blocked = false,
    ): DailyParkingData {
        return DailyParkingData::query()->create([
            'date' => $date,
            'time_slot_id' => $slotId,
            'capacity' => $capacity,
            'reserved' => $reserved,
            'pending' => $pending,
            'is_blocked' => $blocked,
        ]);
    }

    /**
     * Two free unblocked slots so Blokiranje Prilagodi date prefilter (≥2 free) can pass.
     *
     * @return array{reservation: Reservation, drop: ListOfTimeSlot, pick: ListOfTimeSlot, free: ListOfTimeSlot, free2: ListOfTimeSlot, date: string, vt: VehicleType}
     */
    private function occupiedBothBlockedSetup(string $mtid): array
    {
        $date = Carbon::now()->addDay()->toDateString();
        [$free, $drop, $pick, $free2] = $this->fourSlots();
        $vt = $this->vehicleType();
        $this->parking($date, $free->id);
        $this->parking($date, $drop->id, reserved: 1, blocked: true);
        $this->parking($date, $pick->id, reserved: 1, blocked: true);
        $this->parking($date, $free2->id);

        $reservation = Reservation::query()->create([
            'merchant_transaction_id' => $mtid,
            'drop_off_time_slot_id' => $drop->id,
            'pick_up_time_slot_id' => $pick->id,
            'reservation_date' => $date,
            'user_name' => 'Guest',
            'country' => 'ME',
            'license_plate' => 'WL111AA',
            'vehicle_type_id' => $vt->id,
            'email' => 'wl@example.com',
            'status' => 'paid',
            'invoice_amount' => '10.00',
            'email_sent' => Reservation::EMAIL_NOT_SENT,
        ]);

        BlockZoneWorklist::query()->create([
            'merchant_transaction_id' => $mtid,
            'status' => BlockZoneWorklist::STATUS_READY_TO_ADJUST,
            'old_date' => $date,
            'old_drop_off' => $drop->id,
            'old_pick_up' => $pick->id,
            'affected_drop_off' => true,
            'affected_pick_up' => true,
            'snapshot_json' => [
                'user_name' => 'Guest',
                'email' => 'wl@example.com',
                'reservation_id' => $reservation->id,
                'reservation_status' => 'paid',
                'target_block_slots' => [$drop->id, $pick->id],
            ],
            'reservation_id' => $reservation->id,
            'temp_data_id' => null,
        ]);

        return compact('reservation', 'drop', 'pick', 'free', 'free2', 'date', 'vt');
    }

    public function test_a_admin_edit_fully_out_of_blocked_drop_removes_worklist(): void
    {
        Queue::fake();
        $admin = $this->admin('wla');
        $date = Carbon::now()->addDay()->toDateString();
        [$free, $drop, $pick] = $this->threeSlots();
        $vt = $this->vehicleType();
        $this->parking($date, $free->id);
        $this->parking($date, $drop->id, reserved: 1, blocked: true);
        $this->parking($date, $pick->id, reserved: 1);

        $reservation = Reservation::query()->create([
            'merchant_transaction_id' => 'mt-recon-a',
            'drop_off_time_slot_id' => $drop->id,
            'pick_up_time_slot_id' => $pick->id,
            'reservation_date' => $date,
            'user_name' => 'Guest',
            'country' => 'ME',
            'license_plate' => 'WLA111',
            'vehicle_type_id' => $vt->id,
            'email' => 'a@example.com',
            'status' => 'paid',
            'invoice_amount' => '10.00',
            'email_sent' => Reservation::EMAIL_NOT_SENT,
        ]);

        BlockZoneWorklist::query()->create([
            'merchant_transaction_id' => 'mt-recon-a',
            'status' => BlockZoneWorklist::STATUS_READY_TO_ADJUST,
            'old_date' => $date,
            'old_drop_off' => $drop->id,
            'old_pick_up' => $pick->id,
            'affected_drop_off' => true,
            'affected_pick_up' => false,
            'snapshot_json' => ['user_name' => 'Guest', 'email' => 'a@example.com'],
            'reservation_id' => $reservation->id,
            'temp_data_id' => null,
        ]);

        $this->actingAs($admin, 'panel_admin');
        $this->put(route('panel_admin.reservations.update', $reservation, false), [
            'reservation_date' => $date,
            'drop_off_time_slot_id' => $free->id,
            'pick_up_time_slot_id' => $pick->id,
            'user_name' => 'Guest',
            'country' => 'ME',
            'license_plate' => 'WLA111',
            'vehicle_type_id' => $vt->id,
            'email' => 'a@example.com',
        ])->assertRedirect();

        $reservation->refresh();
        $this->assertSame($free->id, (int) $reservation->drop_off_time_slot_id);
        $this->assertSame(0, BlockZoneWorklist::query()->count());
    }

    public function test_b_admin_edit_moves_only_drop_out_keeps_pick_affected(): void
    {
        Queue::fake();
        $admin = $this->admin('wlb');
        $ctx = $this->occupiedBothBlockedSetup('mt-recon-b');

        $this->actingAs($admin, 'panel_admin');
        $this->put(route('panel_admin.reservations.update', $ctx['reservation'], false), [
            'reservation_date' => $ctx['date'],
            'drop_off_time_slot_id' => $ctx['free']->id,
            'pick_up_time_slot_id' => $ctx['pick']->id,
            'user_name' => 'Guest',
            'country' => 'ME',
            'license_plate' => 'WL111AA',
            'vehicle_type_id' => $ctx['vt']->id,
            'email' => 'wl@example.com',
        ])->assertRedirect();

        $ctx['reservation']->refresh();
        $this->assertSame($ctx['free']->id, (int) $ctx['reservation']->drop_off_time_slot_id);
        $this->assertSame($ctx['pick']->id, (int) $ctx['reservation']->pick_up_time_slot_id);

        $this->assertSame(1, BlockZoneWorklist::query()->count());
        $this->assertDatabaseHas('block_zone_worklist', [
            'merchant_transaction_id' => 'mt-recon-b',
            'status' => BlockZoneWorklist::STATUS_READY_TO_ADJUST,
            'old_drop_off' => $ctx['free']->id,
            'old_pick_up' => $ctx['pick']->id,
            'affected_drop_off' => false,
            'affected_pick_up' => true,
            'reservation_id' => $ctx['reservation']->id,
        ]);
    }

    public function test_c_admin_edit_moves_final_blocked_side_out_removes_worklist(): void
    {
        Queue::fake();
        $admin = $this->admin('wlc');
        $ctx = $this->occupiedBothBlockedSetup('mt-recon-c');

        $this->actingAs($admin, 'panel_admin');
        $this->put(route('panel_admin.reservations.update', $ctx['reservation'], false), [
            'reservation_date' => $ctx['date'],
            'drop_off_time_slot_id' => $ctx['free']->id,
            'pick_up_time_slot_id' => $ctx['pick']->id,
            'user_name' => 'Guest',
            'country' => 'ME',
            'license_plate' => 'WL111AA',
            'vehicle_type_id' => $ctx['vt']->id,
            'email' => 'wl@example.com',
        ])->assertRedirect();

        $this->assertSame(1, BlockZoneWorklist::query()->count());

        // After first move only pick remains blocked; unblock former drop so it can be the new pick.
        DailyParkingData::query()->whereDate('date', $ctx['date'])->where('time_slot_id', $ctx['drop']->id)
            ->update(['is_blocked' => false]);

        $this->put(route('panel_admin.reservations.update', $ctx['reservation'], false), [
            'reservation_date' => $ctx['date'],
            'drop_off_time_slot_id' => $ctx['free']->id,
            'pick_up_time_slot_id' => $ctx['free2']->id,
            'user_name' => 'Guest',
            'country' => 'ME',
            'license_plate' => 'WL111AA',
            'vehicle_type_id' => $ctx['vt']->id,
            'email' => 'wl@example.com',
        ])->assertRedirect();

        $this->assertSame(0, BlockZoneWorklist::query()->count());
    }

    public function test_d_non_slot_edit_while_still_blocked_keeps_worklist(): void
    {
        Queue::fake();
        $admin = $this->admin('wld');
        $date = Carbon::now()->addDay()->toDateString();
        [$drop, $pick] = $this->threeSlots();
        $vt = $this->vehicleType();
        $this->parking($date, $drop->id, reserved: 1, blocked: true);
        $this->parking($date, $pick->id, reserved: 1);

        $reservation = Reservation::query()->create([
            'merchant_transaction_id' => 'mt-recon-d',
            'drop_off_time_slot_id' => $drop->id,
            'pick_up_time_slot_id' => $pick->id,
            'reservation_date' => $date,
            'user_name' => 'Old Name',
            'country' => 'ME',
            'license_plate' => 'WLD111',
            'vehicle_type_id' => $vt->id,
            'email' => 'd@example.com',
            'status' => 'paid',
            'invoice_amount' => '10.00',
            'email_sent' => Reservation::EMAIL_NOT_SENT,
        ]);

        BlockZoneWorklist::query()->create([
            'merchant_transaction_id' => 'mt-recon-d',
            'status' => BlockZoneWorklist::STATUS_READY_TO_ADJUST,
            'old_date' => $date,
            'old_drop_off' => $drop->id,
            'old_pick_up' => $pick->id,
            'affected_drop_off' => true,
            'affected_pick_up' => false,
            'snapshot_json' => ['user_name' => 'Old Name', 'email' => 'd@example.com'],
            'reservation_id' => $reservation->id,
            'temp_data_id' => null,
        ]);

        $this->actingAs($admin, 'panel_admin');
        $this->put(route('panel_admin.reservations.update', $reservation, false), [
            'reservation_date' => $date,
            'drop_off_time_slot_id' => $drop->id,
            'pick_up_time_slot_id' => $pick->id,
            'user_name' => 'New Name',
            'country' => 'ME',
            'license_plate' => 'WLD111',
            'vehicle_type_id' => $vt->id,
            'email' => 'd@example.com',
        ])->assertRedirect();

        $this->assertSame(1, BlockZoneWorklist::query()->count());
        $row = BlockZoneWorklist::query()->first();
        $this->assertTrue((bool) $row->affected_drop_off);
        $this->assertFalse((bool) $row->affected_pick_up);
        $this->assertSame($drop->id, (int) $row->old_drop_off);
        $this->assertSame('New Name', $row->snapshot_json['user_name'] ?? null);
    }

    public function test_e_reconcile_refreshes_stale_snapshot_to_current_blocked_slots(): void
    {
        $date = Carbon::now()->addDay()->toDateString();
        [$staleDrop, $currentDrop, $pick] = $this->threeSlots();
        $vt = $this->vehicleType();
        $this->parking($date, $staleDrop->id, blocked: true);
        $this->parking($date, $currentDrop->id, reserved: 1, blocked: true);
        $this->parking($date, $pick->id, reserved: 1);

        $reservation = Reservation::query()->create([
            'merchant_transaction_id' => 'mt-recon-e',
            'drop_off_time_slot_id' => $currentDrop->id,
            'pick_up_time_slot_id' => $pick->id,
            'reservation_date' => $date,
            'user_name' => 'Guest',
            'country' => 'ME',
            'license_plate' => 'WLE111',
            'vehicle_type_id' => $vt->id,
            'email' => 'e@example.com',
            'status' => 'paid',
            'invoice_amount' => '10.00',
            'email_sent' => Reservation::EMAIL_NOT_SENT,
        ]);

        BlockZoneWorklist::query()->create([
            'merchant_transaction_id' => 'mt-recon-e',
            'status' => BlockZoneWorklist::STATUS_READY_TO_ADJUST,
            'old_date' => $date,
            'old_drop_off' => $staleDrop->id,
            'old_pick_up' => $pick->id,
            'affected_drop_off' => true,
            'affected_pick_up' => true,
            'snapshot_json' => [
                'user_name' => 'Guest',
                'email' => 'e@example.com',
                'target_block_slots' => [$staleDrop->id, $pick->id],
            ],
            'reservation_id' => $reservation->id,
            'temp_data_id' => null,
        ]);

        app(BlockZoneWorklistService::class)->reconcileForReservation($reservation);

        $this->assertSame(1, BlockZoneWorklist::query()->count());
        $this->assertDatabaseHas('block_zone_worklist', [
            'merchant_transaction_id' => 'mt-recon-e',
            'old_drop_off' => $currentDrop->id,
            'old_pick_up' => $pick->id,
            'affected_drop_off' => true,
            'affected_pick_up' => false,
            'reservation_id' => $reservation->id,
        ]);
        $row = BlockZoneWorklist::query()->first();
        $this->assertSame([$currentDrop->id], $row->snapshot_json['target_block_slots'] ?? null);
    }

    public function test_f_prilagodi_both_blocked_move_only_one_side_keeps_worklist(): void
    {
        Queue::fake();
        $admin = $this->admin('wlf');
        $ctx = $this->occupiedBothBlockedSetup('mt-recon-f');
        $row = BlockZoneWorklist::query()->where('merchant_transaction_id', 'mt-recon-f')->firstOrFail();

        $this->actingAs($admin, 'panel_admin');
        $this->post(route('panel_admin.blocking.worklist.adjust.apply', $row, false), [
            'new_date' => $ctx['date'],
            'new_drop_off' => $ctx['free']->id,
            'new_pick_up' => $ctx['pick']->id,
        ])->assertSessionMissing('error')->assertRedirect();

        $this->assertSame(1, BlockZoneWorklist::query()->count());
        $this->assertDatabaseHas('block_zone_worklist', [
            'merchant_transaction_id' => 'mt-recon-f',
            'old_drop_off' => $ctx['free']->id,
            'old_pick_up' => $ctx['pick']->id,
            'affected_drop_off' => false,
            'affected_pick_up' => true,
            'status' => BlockZoneWorklist::STATUS_READY_TO_ADJUST,
        ]);
        $this->assertSame($ctx['free']->id, (int) $ctx['reservation']->fresh()->drop_off_time_slot_id);
        $this->assertSame($ctx['pick']->id, (int) $ctx['reservation']->fresh()->pick_up_time_slot_id);
    }

    public function test_g_prilagodi_final_affected_side_out_removes_worklist(): void
    {
        Queue::fake();
        $admin = $this->admin('wlg');
        $ctx = $this->occupiedBothBlockedSetup('mt-recon-g');
        $row = BlockZoneWorklist::query()->where('merchant_transaction_id', 'mt-recon-g')->firstOrFail();

        $this->actingAs($admin, 'panel_admin');
        $this->post(route('panel_admin.blocking.worklist.adjust.apply', $row, false), [
            'new_date' => $ctx['date'],
            'new_drop_off' => $ctx['free']->id,
            'new_pick_up' => $ctx['pick']->id,
        ])->assertSessionMissing('error');

        $row = BlockZoneWorklist::query()->where('merchant_transaction_id', 'mt-recon-g')->firstOrFail();
        DailyParkingData::query()->whereDate('date', $ctx['date'])->where('time_slot_id', $ctx['drop']->id)
            ->update(['is_blocked' => false]);

        $this->post(route('panel_admin.blocking.worklist.adjust.apply', $row, false), [
            'new_date' => $ctx['date'],
            'new_drop_off' => $ctx['free']->id,
            'new_pick_up' => $ctx['drop']->id,
        ])->assertSessionMissing('error')->assertRedirect();

        $this->assertSame(0, BlockZoneWorklist::query()->count());
    }

    public function test_h_unblock_still_clears_worklist_when_slots_unblocked(): void
    {
        $admin = $this->admin('wlh');
        $ctx = $this->occupiedBothBlockedSetup('mt-recon-h');

        $this->actingAs($admin, 'panel_admin');
        $this->post(route('panel_admin.blocking.unblock.apply', [], false), [
            'date' => $ctx['date'],
            'slot_ids' => [$ctx['drop']->id, $ctx['pick']->id],
        ])->assertRedirect();

        $this->assertFalse((bool) DailyParkingData::query()->whereDate('date', $ctx['date'])->where('time_slot_id', $ctx['drop']->id)->value('is_blocked'));
        $this->assertSame(0, BlockZoneWorklist::query()->count());
        $this->assertSame($ctx['drop']->id, (int) $ctx['reservation']->fresh()->drop_off_time_slot_id);
    }

    public function test_i_pending_then_block_then_success_still_ready_to_adjust(): void
    {
        Bus::fake();
        $date = Carbon::now()->addDays(2)->toDateString();
        [$drop, $pick] = $this->threeSlots();
        $vt = $this->vehicleType();
        $this->parking($date, $drop->id, pending: 1);
        $this->parking($date, $pick->id, pending: 1);

        $temp = TempData::query()->create([
            'merchant_transaction_id' => 'mt-recon-i',
            'retry_token' => 'rt-recon-i',
            'user_id' => null,
            'drop_off_time_slot_id' => $drop->id,
            'pick_up_time_slot_id' => $pick->id,
            'reservation_date' => $date,
            'user_name' => 'Guest',
            'country' => 'ME',
            'license_plate' => 'WLI111',
            'vehicle_type_id' => $vt->id,
            'invoice_amount_snapshot' => '10.00',
            'email' => 'i@example.com',
            'preferred_locale' => 'en',
            'status' => TempData::STATUS_PENDING,
        ]);

        app(BlockingService::class)->applyBlock($date, [$drop->id, $pick->id]);
        $this->assertDatabaseHas('block_zone_worklist', [
            'merchant_transaction_id' => 'mt-recon-i',
            'status' => BlockZoneWorklist::STATUS_PENDING_PAYMENT,
        ]);

        $created = app(PaymentSuccessHandler::class)->handle($temp->fresh(), ['status' => 'success'], true, true);
        $this->assertTrue($created);

        $this->assertDatabaseHas('block_zone_worklist', [
            'merchant_transaction_id' => 'mt-recon-i',
            'status' => BlockZoneWorklist::STATUS_READY_TO_ADJUST,
        ]);
        $this->assertTrue((bool) DailyParkingData::query()->whereDate('date', $date)->where('time_slot_id', $drop->id)->value('is_blocked'));
        $this->assertDatabaseHas('reservations', ['merchant_transaction_id' => 'mt-recon-i']);
    }
}
