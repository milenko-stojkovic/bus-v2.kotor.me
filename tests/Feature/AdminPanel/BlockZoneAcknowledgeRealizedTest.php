<?php

namespace Tests\Feature\AdminPanel;

use App\Models\Admin;
use App\Models\BlockZoneWorklist;
use App\Models\DailyParkingData;
use App\Models\ListOfTimeSlot;
use App\Models\Reservation;
use App\Models\TempData;
use App\Models\User;
use App\Models\VehicleType;
use App\Models\VehicleTypeTranslation;
use App\Services\AdminPanel\Blocking\BlockZoneWorklistService;
use App\Services\AdminPanel\Blocking\BlockingService;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

final class BlockZoneAcknowledgeRealizedTest extends TestCase
{
    use RefreshDatabase;

    private function admin(string $user = 'ackadmin'): Admin
    {
        return Admin::query()->create([
            'username' => $user,
            'email' => $user.'@example.com',
            'password' => bcrypt('secret-password-ack'),
            'control_access' => false,
            'admin_access' => true,
        ]);
    }

    private function vehicleType(): VehicleType
    {
        $vt = VehicleType::query()->create(['price' => 10]);
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

    private function parking(
        string $date,
        int $slotId,
        int $reserved = 0,
        int $pending = 0,
        bool $blocked = false,
    ): DailyParkingData {
        return DailyParkingData::query()->create([
            'date' => $date,
            'time_slot_id' => $slotId,
            'capacity' => 5,
            'reserved' => $reserved,
            'pending' => $pending,
            'is_blocked' => $blocked,
        ]);
    }

    /**
     * @return array{reservation: Reservation, row: BlockZoneWorklist, drop: ListOfTimeSlot, pick: ListOfTimeSlot, free: ListOfTimeSlot, date: string, vt: VehicleType}
     */
    private function readyToAdjustSetup(string $mtid): array
    {
        $date = Carbon::now()->addDay()->toDateString();
        [$drop, $pick, $free] = $this->threeSlots();
        $vt = $this->vehicleType();
        $this->parking($date, $drop->id, reserved: 1, blocked: true);
        $this->parking($date, $pick->id, reserved: 1, blocked: true);
        $this->parking($date, $free->id);

        $reservation = Reservation::query()->create([
            'merchant_transaction_id' => $mtid,
            'drop_off_time_slot_id' => $drop->id,
            'pick_up_time_slot_id' => $pick->id,
            'reservation_date' => $date,
            'user_name' => 'Ack Guest',
            'country' => 'ME',
            'license_plate' => 'ACK111',
            'vehicle_type_id' => $vt->id,
            'email' => 'ack@example.com',
            'status' => 'paid',
            'invoice_amount' => '10.00',
            'fiscal_jir' => 'JIR-ACK-1',
            'email_sent' => Reservation::EMAIL_NOT_SENT,
        ]);

        $row = BlockZoneWorklist::query()->create([
            'merchant_transaction_id' => $mtid,
            'status' => BlockZoneWorklist::STATUS_READY_TO_ADJUST,
            'old_date' => $date,
            'old_drop_off' => $drop->id,
            'old_pick_up' => $pick->id,
            'affected_drop_off' => true,
            'affected_pick_up' => true,
            'snapshot_json' => [
                'user_name' => 'Ack Guest',
                'email' => 'ack@example.com',
                'reservation_id' => $reservation->id,
                'reservation_status' => 'paid',
                'target_block_slots' => [$drop->id, $pick->id],
            ],
            'reservation_id' => $reservation->id,
            'temp_data_id' => null,
        ]);

        return compact('reservation', 'row', 'drop', 'pick', 'free', 'date', 'vt');
    }

    public function test_admin_acknowledges_ready_to_adjust_entry(): void
    {
        $admin = $this->admin('ack1');
        $ctx = $this->readyToAdjustSetup('mt-ack-1');

        $this->actingAs($admin, 'panel_admin');
        $this->post(route('panel_admin.blocking.worklist.acknowledge', $ctx['row'], false), [
            'resolution_note' => 'Realizovano uprkos blokadi.',
        ])
            ->assertRedirect()
            ->assertSessionHas('status');

        $ctx['row']->refresh();
        $this->assertSame(BlockZoneWorklist::STATUS_ACKNOWLEDGED_NO_ADJUSTMENT, (string) $ctx['row']->status);
        $this->assertSame((int) $admin->id, (int) $ctx['row']->reviewed_by_admin_id);
        $this->assertNotNull($ctx['row']->reviewed_at);
        $this->assertSame('Realizovano uprkos blokadi.', $ctx['row']->resolution_note);
    }

    public function test_acknowledged_entry_disappears_from_active_list(): void
    {
        $admin = $this->admin('ack2');
        $ctx = $this->readyToAdjustSetup('mt-ack-2');

        $this->actingAs($admin, 'panel_admin');
        $this->post(route('panel_admin.blocking.worklist.acknowledge', $ctx['row'], false))
            ->assertRedirect();

        $html = $this->get(route('panel_admin.blocking', [], false))->assertOk()->getContent();
        $this->assertStringNotContainsString('mt-ack-2', $html);
        $this->assertStringContainsString('Nema otvorenih stavki.', $html);

        $this->assertSame(1, BlockZoneWorklist::query()->count());
        $this->assertSame(0, BlockZoneWorklist::query()->activeIntervention()->count());
    }

    public function test_acknowledgment_does_not_modify_reservation_or_blockade(): void
    {
        $admin = $this->admin('ack3');
        $ctx = $this->readyToAdjustSetup('mt-ack-3');
        $before = $ctx['reservation']->fresh()->toArray();

        $this->actingAs($admin, 'panel_admin');
        $this->post(route('panel_admin.blocking.worklist.acknowledge', $ctx['row'], false))
            ->assertRedirect();

        $after = $ctx['reservation']->fresh()->toArray();
        foreach ([
            'reservation_date',
            'drop_off_time_slot_id',
            'pick_up_time_slot_id',
            'vehicle_type_id',
            'status',
            'invoice_amount',
            'fiscal_jir',
            'license_plate',
            'email',
        ] as $key) {
            $this->assertEquals($before[$key] ?? null, $after[$key] ?? null, "Field {$key} must not change");
        }

        $this->assertTrue((bool) DailyParkingData::query()->whereDate('date', $ctx['date'])->where('time_slot_id', $ctx['drop']->id)->value('is_blocked'));
        $this->assertTrue((bool) DailyParkingData::query()->whereDate('date', $ctx['date'])->where('time_slot_id', $ctx['pick']->id)->value('is_blocked'));
    }

    public function test_reconcile_does_not_reopen_acknowledged_same_fingerprint(): void
    {
        $admin = $this->admin('ack4');
        $ctx = $this->readyToAdjustSetup('mt-ack-4');

        $this->actingAs($admin, 'panel_admin');
        $this->post(route('panel_admin.blocking.worklist.acknowledge', $ctx['row'], false))
            ->assertRedirect();

        app(BlockZoneWorklistService::class)->reconcileForReservation($ctx['reservation']->fresh());

        $ctx['row']->refresh();
        $this->assertSame(BlockZoneWorklist::STATUS_ACKNOWLEDGED_NO_ADJUSTMENT, (string) $ctx['row']->status);
        $this->assertSame(0, BlockZoneWorklist::query()->activeIntervention()->count());
    }

    public function test_reblock_same_slots_does_not_reopen_acknowledgment(): void
    {
        $admin = $this->admin('ack5');
        $ctx = $this->readyToAdjustSetup('mt-ack-5');

        $this->actingAs($admin, 'panel_admin');
        $this->post(route('panel_admin.blocking.worklist.acknowledge', $ctx['row'], false))
            ->assertRedirect();

        app(BlockingService::class)->applyBlock($ctx['date'], [$ctx['drop']->id, $ctx['pick']->id]);

        $ctx['row']->refresh();
        $this->assertSame(BlockZoneWorklist::STATUS_ACKNOWLEDGED_NO_ADJUSTMENT, (string) $ctx['row']->status);
    }

    public function test_moved_reservation_onto_new_blocked_slots_creates_new_intervention(): void
    {
        Queue::fake();
        $admin = $this->admin('ack6');
        $ctx = $this->readyToAdjustSetup('mt-ack-6');

        $this->actingAs($admin, 'panel_admin');
        $this->post(route('panel_admin.blocking.worklist.acknowledge', $ctx['row'], false))
            ->assertRedirect();

        // Unblock original occupancy; move reservation to free slots while they are open.
        DailyParkingData::query()->whereDate('date', $ctx['date'])->where('time_slot_id', $ctx['drop']->id)
            ->update(['is_blocked' => false]);
        DailyParkingData::query()->whereDate('date', $ctx['date'])->where('time_slot_id', $ctx['pick']->id)
            ->update(['is_blocked' => false]);

        $free2 = ListOfTimeSlot::query()->create(['time_slot' => '11:00 - 11:20']);
        $this->parking($ctx['date'], $free2->id);

        $this->put(route('panel_admin.reservations.update', $ctx['reservation'], false), [
            'reservation_date' => $ctx['date'],
            'drop_off_time_slot_id' => $ctx['free']->id,
            'pick_up_time_slot_id' => $free2->id,
            'user_name' => 'Ack Guest',
            'country' => 'ME',
            'license_plate' => 'ACK111',
            'vehicle_type_id' => $ctx['vt']->id,
            'email' => 'ack@example.com',
        ])->assertRedirect()->assertSessionMissing('error');

        $ctx['reservation']->refresh();
        $this->assertSame($ctx['free']->id, (int) $ctx['reservation']->drop_off_time_slot_id);
        $this->assertSame($free2->id, (int) $ctx['reservation']->pick_up_time_slot_id);

        // Acknowledged fingerprint no longer matches; a new block on the new slots must reopen intervention.
        $summary = app(BlockingService::class)->applyBlock($ctx['date'], [$ctx['free']->id, $free2->id]);
        $this->assertGreaterThan(0, $summary['worklist_touched']);

        $row = BlockZoneWorklist::query()->where('merchant_transaction_id', 'mt-ack-6')->firstOrFail();
        $this->assertSame(BlockZoneWorklist::STATUS_READY_TO_ADJUST, (string) $row->status);
        $this->assertSame($ctx['free']->id, (int) $row->old_drop_off);
        $this->assertSame($free2->id, (int) $row->old_pick_up);
        $this->assertNull($row->reviewed_by_admin_id);
        $this->assertSame(1, BlockZoneWorklist::query()->activeIntervention()->count());
    }

    public function test_guest_cannot_acknowledge(): void
    {
        $ctx = $this->readyToAdjustSetup('mt-ack-guest');
        $user = User::factory()->create();

        $this->actingAs($user)
            ->post(route('panel_admin.blocking.worklist.acknowledge', $ctx['row'], false))
            ->assertRedirect(route('panel_admin.login', [], false));

        $this->assertSame(BlockZoneWorklist::STATUS_READY_TO_ADJUST, (string) $ctx['row']->fresh()->status);
    }

    public function test_pending_payment_cannot_be_acknowledged(): void
    {
        $admin = $this->admin('ack-pending');
        $date = Carbon::now()->addDay()->toDateString();
        [$drop, $pick] = $this->threeSlots();
        $this->vehicleType();
        $this->parking($date, $drop->id, pending: 1, blocked: true);
        $this->parking($date, $pick->id, pending: 1, blocked: true);

        $temp = TempData::query()->create([
            'merchant_transaction_id' => 'mt-ack-pending',
            'retry_token' => 'rt-ack-pending',
            'user_id' => null,
            'drop_off_time_slot_id' => $drop->id,
            'pick_up_time_slot_id' => $pick->id,
            'reservation_date' => $date,
            'user_name' => 'Pending',
            'country' => 'ME',
            'license_plate' => 'PEN111',
            'vehicle_type_id' => VehicleType::query()->first()->id,
            'invoice_amount_snapshot' => '10.00',
            'email' => 'p@example.com',
            'preferred_locale' => 'en',
            'status' => TempData::STATUS_PENDING,
        ]);

        $row = BlockZoneWorklist::query()->create([
            'merchant_transaction_id' => 'mt-ack-pending',
            'status' => BlockZoneWorklist::STATUS_PENDING_PAYMENT,
            'old_date' => $date,
            'old_drop_off' => $drop->id,
            'old_pick_up' => $pick->id,
            'affected_drop_off' => true,
            'affected_pick_up' => true,
            'snapshot_json' => ['user_name' => 'Pending', 'email' => 'p@example.com'],
            'reservation_id' => null,
            'temp_data_id' => $temp->id,
        ]);

        $this->actingAs($admin, 'panel_admin');
        $this->from(route('panel_admin.blocking', [], false))
            ->post(route('panel_admin.blocking.worklist.acknowledge', $row, false))
            ->assertRedirect()
            ->assertSessionHasErrors('worklist');

        $this->assertSame(BlockZoneWorklist::STATUS_PENDING_PAYMENT, (string) $row->fresh()->status);
    }

    public function test_repeated_acknowledgment_is_idempotent(): void
    {
        $admin = $this->admin('ack-idem');
        $ctx = $this->readyToAdjustSetup('mt-ack-idem');

        $this->actingAs($admin, 'panel_admin');
        $this->post(route('panel_admin.blocking.worklist.acknowledge', $ctx['row'], false), [
            'resolution_note' => 'first',
        ])->assertRedirect()->assertSessionHas('status');

        $firstAt = $ctx['row']->fresh()->reviewed_at;

        $this->post(route('panel_admin.blocking.worklist.acknowledge', $ctx['row'], false), [
            'resolution_note' => 'second',
        ])->assertRedirect()->assertSessionHas('status');

        $ctx['row']->refresh();
        $this->assertSame(BlockZoneWorklist::STATUS_ACKNOWLEDGED_NO_ADJUSTMENT, (string) $ctx['row']->status);
        $this->assertSame('first', $ctx['row']->resolution_note);
        $this->assertTrue($firstAt->equalTo($ctx['row']->reviewed_at));
    }

    public function test_missing_reservation_fails_safely(): void
    {
        $admin = $this->admin('ack-missing');
        $date = Carbon::now()->addDay()->toDateString();
        [$drop, $pick] = $this->threeSlots();
        $this->parking($date, $drop->id, blocked: true);
        $this->parking($date, $pick->id, blocked: true);

        $row = BlockZoneWorklist::query()->create([
            'merchant_transaction_id' => 'mt-ack-missing',
            'status' => BlockZoneWorklist::STATUS_READY_TO_ADJUST,
            'old_date' => $date,
            'old_drop_off' => $drop->id,
            'old_pick_up' => $pick->id,
            'affected_drop_off' => true,
            'affected_pick_up' => true,
            'snapshot_json' => ['user_name' => 'X', 'email' => 'x@example.com'],
            'reservation_id' => 999999,
            'temp_data_id' => null,
        ]);

        $this->actingAs($admin, 'panel_admin');
        $this->from(route('panel_admin.blocking', [], false))
            ->post(route('panel_admin.blocking.worklist.acknowledge', $row, false))
            ->assertRedirect()
            ->assertSessionHasErrors('worklist');

        $this->assertSame(BlockZoneWorklist::STATUS_READY_TO_ADJUST, (string) $row->fresh()->status);
    }

    public function test_unblock_preserves_acknowledged_audit_row(): void
    {
        $admin = $this->admin('ack-unblock');
        $ctx = $this->readyToAdjustSetup('mt-ack-unblock');

        $this->actingAs($admin, 'panel_admin');
        $this->post(route('panel_admin.blocking.worklist.acknowledge', $ctx['row'], false))
            ->assertRedirect();

        $this->post(route('panel_admin.blocking.unblock.apply', [], false), [
            'date' => $ctx['date'],
            'slot_ids' => [$ctx['drop']->id, $ctx['pick']->id],
        ])->assertRedirect();

        $this->assertFalse((bool) DailyParkingData::query()->whereDate('date', $ctx['date'])->where('time_slot_id', $ctx['drop']->id)->value('is_blocked'));
        $this->assertSame(1, BlockZoneWorklist::query()->count());
        $this->assertSame(
            BlockZoneWorklist::STATUS_ACKNOWLEDGED_NO_ADJUSTMENT,
            (string) BlockZoneWorklist::query()->first()->status
        );
        $this->assertSame(0, BlockZoneWorklist::query()->activeIntervention()->count());
    }

    public function test_index_still_shows_ready_to_adjust_and_pending(): void
    {
        $admin = $this->admin('ack-list');
        $ctx = $this->readyToAdjustSetup('mt-ack-list');

        $this->actingAs($admin, 'panel_admin');
        $html = $this->get(route('panel_admin.blocking', [], false))->assertOk()->getContent();
        $this->assertStringContainsString('mt-ack-list', $html);
        $this->assertStringContainsString('Potvrdi realizaciju', $html);
        $this->assertStringContainsString('Prilagodi rezervaciju', $html);

        $this->assertSame($ctx['row']->id, BlockZoneWorklist::query()->activeIntervention()->first()->id);
    }
}
