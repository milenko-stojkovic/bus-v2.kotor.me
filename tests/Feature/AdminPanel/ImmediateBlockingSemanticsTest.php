<?php

namespace Tests\Feature\AdminPanel;

use App\Models\Admin;
use App\Models\AgencyAdvanceTransaction;
use App\Models\BlockZoneWorklist;
use App\Models\DailyParkingData;
use App\Models\ListOfTimeSlot;
use App\Models\Reservation;
use App\Models\TempData;
use App\Models\User;
use App\Models\Vehicle;
use App\Models\VehicleType;
use App\Models\VehicleTypeTranslation;
use App\Services\AdminPanel\Blocking\BlockingService;
use App\Services\AdminPanel\Reservation\AdminReservationSlotRules;
use App\Services\Payment\PaymentSuccessHandler;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

/**
 * Approved semantics: is_blocked takes effect immediately; worklist tracks existing occupants;
 * already-pending soft-locks are grandfathered (Option A).
 */
final class ImmediateBlockingSemanticsTest extends TestCase
{
    use RefreshDatabase;

    private function admin(): Admin
    {
        return Admin::query()->create([
            'username' => 'blocksem',
            'email' => 'block-sem@example.com',
            'password' => bcrypt('secret-password-block'),
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

    public function test_a_free_slot_block_sets_is_blocked_without_worklist(): void
    {
        $admin = $this->admin();
        $date = Carbon::now()->addDay()->toDateString();
        [$s1] = $this->threeSlots();
        $this->parking($date, $s1->id);

        $this->actingAs($admin, 'panel_admin');
        $this->post(route('panel_admin.blocking.apply', [], false), [
            'date' => $date,
            'slot_ids' => [$s1->id],
        ])->assertRedirect();

        $this->assertTrue((bool) DailyParkingData::query()->whereDate('date', $date)->where('time_slot_id', $s1->id)->value('is_blocked'));
        $this->assertSame(0, BlockZoneWorklist::query()->count());
    }

    public function test_b_occupied_confirmed_slot_blocks_immediately_and_creates_worklist(): void
    {
        $admin = $this->admin();
        $date = Carbon::now()->addDay()->toDateString();
        [$drop, $pick] = $this->threeSlots();
        $vt = $this->vehicleType();
        $this->parking($date, $drop->id, reserved: 1);
        $this->parking($date, $pick->id, reserved: 1);

        $reservation = Reservation::query()->create([
            'merchant_transaction_id' => 'mt-block-occupied',
            'drop_off_time_slot_id' => $drop->id,
            'pick_up_time_slot_id' => $pick->id,
            'reservation_date' => $date,
            'user_name' => 'Agency',
            'country' => 'ME',
            'license_plate' => 'KO111AA',
            'vehicle_type_id' => $vt->id,
            'email' => 'a@example.com',
            'status' => 'paid',
            'invoice_amount' => '10.00',
            'email_sent' => Reservation::EMAIL_NOT_SENT,
        ]);

        $this->actingAs($admin, 'panel_admin');
        $this->post(route('panel_admin.blocking.apply', [], false), [
            'date' => $date,
            'slot_ids' => [$drop->id, $pick->id],
        ])->assertRedirect();

        $this->assertTrue((bool) DailyParkingData::query()->whereDate('date', $date)->where('time_slot_id', $drop->id)->value('is_blocked'));
        $this->assertTrue((bool) DailyParkingData::query()->whereDate('date', $date)->where('time_slot_id', $pick->id)->value('is_blocked'));
        $this->assertSame(1, (int) DailyParkingData::query()->whereDate('date', $date)->where('time_slot_id', $drop->id)->value('reserved'));
        $this->assertSame($date, $reservation->fresh()->reservation_date->toDateString());
        $this->assertDatabaseHas('block_zone_worklist', [
            'merchant_transaction_id' => 'mt-block-occupied',
            'reservation_id' => $reservation->id,
            'status' => BlockZoneWorklist::STATUS_READY_TO_ADJUST,
        ]);
    }

    public function test_c_new_guest_checkout_rejected_on_blocked_slot(): void
    {
        $date = Carbon::now()->addDays(2)->toDateString();
        [$drop, $pick] = $this->threeSlots();
        $vt = $this->vehicleType();
        $this->parking($date, $drop->id, blocked: true);
        $this->parking($date, $pick->id, blocked: true);

        $response = $this->post(route('checkout.store', [], false), [
            'reservation_date' => $date,
            'drop_off_time_slot_id' => $drop->id,
            'pick_up_time_slot_id' => $pick->id,
            'name' => 'Guest',
            'email' => 'guest@example.com',
            'country' => 'ME',
            'license_plate' => 'GUEST1',
            'vehicle_type_id' => $vt->id,
            'accept_terms' => 1,
            'accept_privacy' => 1,
        ]);

        $this->assertTrue(in_array($response->status(), [422, 302], true));
        $this->assertSame(0, TempData::query()->count());
        $this->assertSame(0, Reservation::query()->count());
    }

    public function test_d_agency_advance_rejected_on_blocked_slot(): void
    {
        config(['features.advance_payments' => true]);
        Bus::fake();

        $user = User::factory()->create(['email_verified_at' => now(), 'country' => 'ME']);
        $this->actingAs($user);

        $date = Carbon::now()->addDays(3)->toDateString();
        [$drop, $pick] = $this->threeSlots();
        $this->parking($date, $drop->id, blocked: true);
        $this->parking($date, $pick->id, blocked: true);
        $vt = $this->vehicleType(10);
        $vehicle = Vehicle::query()->create([
            'user_id' => $user->id,
            'license_plate' => 'KO222BB',
            'vehicle_type_id' => $vt->id,
        ]);
        AgencyAdvanceTransaction::query()->create([
            'agency_user_id' => $user->id,
            'amount' => '50.00',
            'type' => AgencyAdvanceTransaction::TYPE_TOPUP,
            'reference_type' => 'advance_topup',
            'reference_id' => 1,
            'merchant_transaction_id' => 'mtid_seed_adv',
            'note' => 'seed',
        ]);

        $response = $this->post(route('checkout.store', [], false), [
            'auth_panel_booking' => 1,
            'payment_method' => 'advance',
            'reservation_date' => $date,
            'drop_off_time_slot_id' => $drop->id,
            'pick_up_time_slot_id' => $pick->id,
            'vehicle_id' => $vehicle->id,
            'accept_terms' => 1,
            'accept_privacy' => 1,
        ]);

        $this->assertTrue(in_array($response->status(), [422, 302], true));
        $this->assertSame(0, Reservation::query()->count());
    }

    public function test_e_move_out_of_blocked_slot_succeeds_and_keeps_old_blocked(): void
    {
        Queue::fake();
        $admin = $this->admin();
        $d1 = Carbon::now()->addDay()->toDateString();
        $d2 = Carbon::now()->addDays(2)->toDateString();
        [$s1, $s2, $s3] = $this->threeSlots();
        $vt = $this->vehicleType();

        foreach ([$d1, $d2] as $date) {
            foreach ([$s1, $s2, $s3] as $slot) {
                $this->parking($date, $slot->id);
            }
        }
        DailyParkingData::query()->whereDate('date', $d1)->where('time_slot_id', $s1->id)->update(['reserved' => 1, 'is_blocked' => true]);
        DailyParkingData::query()->whereDate('date', $d1)->where('time_slot_id', $s2->id)->update(['reserved' => 1, 'is_blocked' => true]);

        $reservation = Reservation::query()->create([
            'merchant_transaction_id' => 'mt-move-out',
            'drop_off_time_slot_id' => $s1->id,
            'pick_up_time_slot_id' => $s2->id,
            'reservation_date' => $d1,
            'user_name' => 'T',
            'country' => 'ME',
            'license_plate' => 'AB123',
            'vehicle_type_id' => $vt->id,
            'email' => 't@example.com',
            'status' => 'paid',
            'invoice_amount' => '10.00',
            'email_sent' => Reservation::EMAIL_NOT_SENT,
        ]);

        $row = BlockZoneWorklist::query()->create([
            'merchant_transaction_id' => 'mt-move-out',
            'status' => BlockZoneWorklist::STATUS_READY_TO_ADJUST,
            'old_date' => $d1,
            'old_drop_off' => $s1->id,
            'old_pick_up' => $s2->id,
            'affected_drop_off' => true,
            'affected_pick_up' => true,
            'snapshot_json' => ['user_name' => 'T', 'email' => 't@example.com'],
            'reservation_id' => $reservation->id,
            'temp_data_id' => null,
        ]);

        $this->actingAs($admin, 'panel_admin');
        $this->post(route('panel_admin.blocking.worklist.adjust.apply', $row, false), [
            'new_date' => $d2,
            'new_drop_off' => $s1->id,
            'new_pick_up' => $s3->id,
        ])->assertRedirect();

        $this->assertSame($d2, $reservation->fresh()->reservation_date->toDateString());
        $this->assertSame(0, (int) DailyParkingData::query()->whereDate('date', $d1)->where('time_slot_id', $s1->id)->value('reserved'));
        $this->assertTrue((bool) DailyParkingData::query()->whereDate('date', $d1)->where('time_slot_id', $s1->id)->value('is_blocked'));
        $this->assertTrue((bool) DailyParkingData::query()->whereDate('date', $d1)->where('time_slot_id', $s2->id)->value('is_blocked'));
        $this->assertSame(1, (int) DailyParkingData::query()->whereDate('date', $d2)->where('time_slot_id', $s1->id)->value('reserved'));
        $this->assertDatabaseMissing('block_zone_worklist', ['id' => $row->id]);
    }

    public function test_f_move_into_blocked_slot_is_rejected(): void
    {
        $admin = $this->admin();
        $d1 = Carbon::now()->addDay()->toDateString();
        $d2 = Carbon::now()->addDays(2)->toDateString();
        [$s1, $s2, $s3] = $this->threeSlots();
        $vt = $this->vehicleType();

        foreach ([$d1, $d2] as $date) {
            foreach ([$s1, $s2, $s3] as $slot) {
                $this->parking($date, $slot->id);
            }
        }
        DailyParkingData::query()->whereDate('date', $d1)->whereIn('time_slot_id', [$s1->id, $s2->id])->update(['reserved' => 1]);
        DailyParkingData::query()->whereDate('date', $d2)->where('time_slot_id', $s1->id)->update(['is_blocked' => true]);

        $reservation = Reservation::query()->create([
            'merchant_transaction_id' => 'mt-move-into-blocked',
            'drop_off_time_slot_id' => $s1->id,
            'pick_up_time_slot_id' => $s2->id,
            'reservation_date' => $d1,
            'user_name' => 'T',
            'country' => 'ME',
            'license_plate' => 'AB123',
            'vehicle_type_id' => $vt->id,
            'email' => 't@example.com',
            'status' => 'paid',
            'invoice_amount' => '10.00',
            'email_sent' => Reservation::EMAIL_NOT_SENT,
        ]);

        $row = BlockZoneWorklist::query()->create([
            'merchant_transaction_id' => 'mt-move-into-blocked',
            'status' => BlockZoneWorklist::STATUS_READY_TO_ADJUST,
            'old_date' => $d1,
            'old_drop_off' => $s1->id,
            'old_pick_up' => $s2->id,
            'affected_drop_off' => true,
            'affected_pick_up' => true,
            'snapshot_json' => ['user_name' => 'T', 'email' => 't@example.com'],
            'reservation_id' => $reservation->id,
            'temp_data_id' => null,
        ]);

        $this->actingAs($admin, 'panel_admin');
        $this->post(route('panel_admin.blocking.worklist.adjust.apply', $row, false), [
            'new_date' => $d2,
            'new_drop_off' => $s1->id,
            'new_pick_up' => $s3->id,
        ])->assertSessionHas('error');

        $this->assertSame($d1, $reservation->fresh()->reservation_date->toDateString());
        $this->assertTrue(BlockZoneWorklist::query()->whereKey($row->id)->exists());
    }

    public function test_g_admin_edit_current_blocked_slot_remains_selectable_other_blocked_does_not(): void
    {
        $date = Carbon::now()->addDay()->toDateString();
        [$s1, $s2, $s3] = $this->threeSlots();
        $vt = $this->vehicleType();
        $currentDrop = $this->parking($date, $s1->id, reserved: 1, blocked: true);
        $currentPick = $this->parking($date, $s2->id, reserved: 1, blocked: true);
        $otherBlocked = $this->parking($date, $s3->id, blocked: true);

        $reservation = Reservation::query()->create([
            'merchant_transaction_id' => 'mt-edit-current-blocked',
            'drop_off_time_slot_id' => $s1->id,
            'pick_up_time_slot_id' => $s2->id,
            'reservation_date' => $date,
            'user_name' => 'T',
            'country' => 'ME',
            'license_plate' => 'AB123',
            'vehicle_type_id' => $vt->id,
            'email' => 't@example.com',
            'status' => 'paid',
            'invoice_amount' => '10.00',
            'email_sent' => Reservation::EMAIL_NOT_SENT,
        ]);

        $rules = app(AdminReservationSlotRules::class);
        $this->assertTrue($rules->slotSelectableOnDaily($reservation, $date, $s1->id, $currentDrop));
        $this->assertTrue($rules->slotSelectableOnDaily($reservation, $date, $s2->id, $currentPick));
        $this->assertFalse($rules->slotSelectableOnDaily($reservation, $date, $s3->id, $otherBlocked));
    }

    public function test_h_unblock_before_worklist_resolution_clears_block_and_worklist(): void
    {
        $admin = $this->admin();
        $date = Carbon::now()->addDay()->toDateString();
        [$drop, $pick] = $this->threeSlots();
        $vt = $this->vehicleType();
        $this->parking($date, $drop->id, reserved: 1, blocked: true);
        $this->parking($date, $pick->id, reserved: 1, blocked: true);

        $reservation = Reservation::query()->create([
            'merchant_transaction_id' => 'mt-unblock-wl',
            'drop_off_time_slot_id' => $drop->id,
            'pick_up_time_slot_id' => $pick->id,
            'reservation_date' => $date,
            'user_name' => 'T',
            'country' => 'ME',
            'license_plate' => 'AB123',
            'vehicle_type_id' => $vt->id,
            'email' => 't@example.com',
            'status' => 'paid',
            'invoice_amount' => '10.00',
            'email_sent' => Reservation::EMAIL_NOT_SENT,
        ]);

        BlockZoneWorklist::query()->create([
            'merchant_transaction_id' => 'mt-unblock-wl',
            'status' => BlockZoneWorklist::STATUS_READY_TO_ADJUST,
            'old_date' => $date,
            'old_drop_off' => $drop->id,
            'old_pick_up' => $pick->id,
            'affected_drop_off' => true,
            'affected_pick_up' => true,
            'snapshot_json' => ['user_name' => 'T', 'email' => 't@example.com'],
            'reservation_id' => $reservation->id,
            'temp_data_id' => null,
        ]);

        $this->actingAs($admin, 'panel_admin');
        $this->post(route('panel_admin.blocking.unblock.apply', [], false), [
            'date' => $date,
            'slot_ids' => [$drop->id, $pick->id],
        ])->assertRedirect();

        $this->assertFalse((bool) DailyParkingData::query()->whereDate('date', $date)->where('time_slot_id', $drop->id)->value('is_blocked'));
        $this->assertSame($date, $reservation->fresh()->reservation_date->toDateString());
        $this->assertSame(0, BlockZoneWorklist::query()->count());
    }

    public function test_i_pending_then_block_then_success_is_grandfathered_onto_worklist(): void
    {
        Bus::fake();
        $admin = $this->admin();
        $date = Carbon::now()->addDays(2)->toDateString();
        [$drop, $pick] = $this->threeSlots();
        $vt = $this->vehicleType();
        $this->parking($date, $drop->id, pending: 1);
        $this->parking($date, $pick->id, pending: 1);

        $temp = TempData::query()->create([
            'merchant_transaction_id' => 'mt-gf-pending-success',
            'retry_token' => 'rt-gf',
            'user_id' => null,
            'drop_off_time_slot_id' => $drop->id,
            'pick_up_time_slot_id' => $pick->id,
            'reservation_date' => $date,
            'user_name' => 'Guest',
            'country' => 'ME',
            'license_plate' => 'GF111',
            'vehicle_type_id' => $vt->id,
            'invoice_amount_snapshot' => '10.00',
            'email' => 'gf@example.com',
            'preferred_locale' => 'en',
            'status' => TempData::STATUS_PENDING,
        ]);

        $summary = app(BlockingService::class)->applyBlock($date, [$drop->id, $pick->id]);
        $this->assertSame(2, $summary['blocked_slots']);
        $this->assertSame(1, $summary['worklist_touched']);

        $this->assertTrue((bool) DailyParkingData::query()->whereDate('date', $date)->where('time_slot_id', $drop->id)->value('is_blocked'));
        $this->assertSame(1, (int) DailyParkingData::query()->whereDate('date', $date)->where('time_slot_id', $drop->id)->value('pending'));
        $this->assertDatabaseHas('block_zone_worklist', [
            'merchant_transaction_id' => 'mt-gf-pending-success',
            'status' => BlockZoneWorklist::STATUS_PENDING_PAYMENT,
            'temp_data_id' => $temp->id,
        ]);

        $created = app(PaymentSuccessHandler::class)->handle($temp->fresh(), ['status' => 'success'], true, true);
        $this->assertTrue($created);

        $reservation = Reservation::query()->where('merchant_transaction_id', 'mt-gf-pending-success')->first();
        $this->assertNotNull($reservation);
        $this->assertSame(0, (int) DailyParkingData::query()->whereDate('date', $date)->where('time_slot_id', $drop->id)->value('pending'));
        $this->assertSame(1, (int) DailyParkingData::query()->whereDate('date', $date)->where('time_slot_id', $drop->id)->value('reserved'));
        $this->assertTrue((bool) DailyParkingData::query()->whereDate('date', $date)->where('time_slot_id', $drop->id)->value('is_blocked'));
        $this->assertDatabaseHas('block_zone_worklist', [
            'merchant_transaction_id' => 'mt-gf-pending-success',
            'status' => BlockZoneWorklist::STATUS_READY_TO_ADJUST,
            'reservation_id' => $reservation->id,
        ]);

        // Sanity: actingAs admin unused but keeps pattern; silence unused if needed
        $this->assertTrue($admin->admin_access);
    }

    public function test_j_pending_then_block_then_cancel_keeps_blocked_and_clears_worklist_pending(): void
    {
        $date = Carbon::now()->addDays(2)->toDateString();
        [$drop, $pick] = $this->threeSlots();
        $vt = $this->vehicleType();
        $this->parking($date, $drop->id, pending: 1);
        $this->parking($date, $pick->id, pending: 1);

        $temp = TempData::query()->create([
            'merchant_transaction_id' => 'mt-gf-pending-fail',
            'retry_token' => 'rt-gf-fail',
            'user_id' => null,
            'drop_off_time_slot_id' => $drop->id,
            'pick_up_time_slot_id' => $pick->id,
            'reservation_date' => $date,
            'user_name' => 'Guest',
            'country' => 'ME',
            'license_plate' => 'GF222',
            'vehicle_type_id' => $vt->id,
            'invoice_amount_snapshot' => '10.00',
            'email' => 'gf2@example.com',
            'preferred_locale' => 'en',
            'status' => TempData::STATUS_PENDING,
        ]);

        app(BlockingService::class)->applyBlock($date, [$drop->id, $pick->id]);
        $this->assertTrue((bool) DailyParkingData::query()->whereDate('date', $date)->where('time_slot_id', $drop->id)->value('is_blocked'));
        $this->assertSame(1, BlockZoneWorklist::query()->count());

        app(\App\Services\Payment\PaymentInitFailureService::class)->failAndRelease($temp->fresh(), 'test_cancel');

        $this->assertSame(TempData::STATUS_CANCELED, (string) $temp->fresh()->status);
        $this->assertSame(0, (int) DailyParkingData::query()->whereDate('date', $date)->where('time_slot_id', $drop->id)->value('pending'));
        $this->assertTrue((bool) DailyParkingData::query()->whereDate('date', $date)->where('time_slot_id', $drop->id)->value('is_blocked'));
        $this->assertSame(0, BlockZoneWorklist::query()->count());
        $this->assertSame(0, Reservation::query()->count());
    }

    public function test_apply_block_flash_mentions_blocked_and_worklist_counts(): void
    {
        $admin = $this->admin();
        $date = Carbon::now()->addDay()->toDateString();
        [$drop, $pick] = $this->threeSlots();
        $vt = $this->vehicleType();
        $this->parking($date, $drop->id, reserved: 1);
        $this->parking($date, $pick->id, reserved: 1);
        Reservation::query()->create([
            'merchant_transaction_id' => 'mt-flash',
            'drop_off_time_slot_id' => $drop->id,
            'pick_up_time_slot_id' => $pick->id,
            'reservation_date' => $date,
            'user_name' => 'T',
            'country' => 'ME',
            'license_plate' => 'AB123',
            'vehicle_type_id' => $vt->id,
            'email' => 't@example.com',
            'status' => 'paid',
            'invoice_amount' => '10.00',
            'email_sent' => Reservation::EMAIL_NOT_SENT,
        ]);

        $this->actingAs($admin, 'panel_admin');
        $this->post(route('panel_admin.blocking.apply', [], false), [
            'date' => $date,
            'slot_ids' => [$drop->id, $pick->id],
        ])->assertRedirect()->assertSessionHas('status');

        $status = (string) session('status');
        $this->assertStringContainsString('Blokirano termina: 2', $status);
        $this->assertStringContainsString('Stavki za prilagođavanje', $status);
    }
}
