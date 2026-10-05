<?php

namespace Tests\Feature\AdminPanel;

use App\Contracts\PaymentService;
use App\Contracts\PaymentSessionResult;
use App\Jobs\SendAdminUpdatedReservationDocumentJob;
use App\Models\Admin;
use App\Models\AgencyAdvanceTransaction;
use App\Models\BlockZoneWorklist;
use App\Models\DailyFeeBlockedDate;
use App\Models\DailyParkingData;
use App\Models\ListOfTimeSlot;
use App\Models\Reservation;
use App\Models\TempData;
use App\Models\User;
use App\Models\Vehicle;
use App\Models\VehicleType;
use App\Models\VehicleTypeTranslation;
use App\Services\AdminPanel\Blocking\BlockingService;
use App\Services\AdminPanel\Blocking\DailyFeeBlockedDateService;
use App\Services\Payment\PaymentSuccessHandler;
use App\Support\ReservationKind;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Queue;
use Mockery;
use Tests\TestCase;

final class DailyFeeBlockingAndConversionTest extends TestCase
{
    use RefreshDatabase;

    private function admin(string $user = 'dfee'): Admin
    {
        return Admin::query()->create([
            'username' => $user,
            'email' => $user.'@example.com',
            'password' => bcrypt('secret-password-dfee'),
            'control_access' => false,
            'admin_access' => true,
        ]);
    }

    private function vehicleType(float $price = 50): VehicleType
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

    /** @return list<ListOfTimeSlot> */
    private function seedSlots(int $count = 3): array
    {
        $out = [];
        for ($i = 0; $i < $count; $i++) {
            $h = 8 + $i;
            $out[] = ListOfTimeSlot::query()->create([
                'time_slot' => sprintf('%02d:00 - %02d:20', $h, $h),
            ]);
        }

        return $out;
    }

    private function parking(string $date, int $slotId, int $reserved = 0, int $pending = 0, bool $blocked = false): DailyParkingData
    {
        return DailyParkingData::query()->create([
            'date' => $date,
            'time_slot_id' => $slotId,
            'capacity' => 5,
            'reserved' => $reserved,
            'pending' => $pending,
            'is_blocked' => $blocked,
        ]);
    }

    private function mockPaymentRedirect(): void
    {
        $mock = Mockery::mock(PaymentService::class);
        $mock->shouldReceive('createSession')
            ->once()
            ->andReturn(new PaymentSessionResult(true, 'https://bank.example/pay', null));
        $this->app->instance(PaymentService::class, $mock);
    }

    private function logoutAdminPanel(): void
    {
        auth('panel_admin')->logout();
    }

    /** @return array<string, mixed> */
    private function guestDailyTicketPayload(string $date, VehicleType $vt, string $plate, string $email): array
    {
        return [
            'reservation_kind' => ReservationKind::DAILY_TICKET,
            'reservation_date' => $date,
            'name' => 'Guest',
            'country' => 'ME',
            'license_plate' => $plate,
            'vehicle_type_id' => $vt->id,
            'email' => $email,
            'accept_terms' => 1,
        ];
    }

    /** @param  list<ListOfTimeSlot>  $slots */
    private function parkAll(string $date, array $slots, bool $blocked = false, int $reserved = 0): void
    {
        foreach ($slots as $slot) {
            $this->parking($date, $slot->id, reserved: $reserved, blocked: $blocked);
        }
    }

    public function test_partial_slot_block_keeps_daily_fee_purchasable(): void
    {
        $admin = $this->admin('df1');
        $date = Carbon::now()->addDay()->toDateString();
        $slots = $this->seedSlots(3);
        $this->parkAll($date, $slots);
        $vt = $this->vehicleType();

        $this->actingAs($admin, 'panel_admin');
        $this->post(route('panel_admin.blocking.apply', [], false), [
            'date' => $date,
            'slot_ids' => [$slots[0]->id],
        ])->assertRedirect();

        $this->assertFalse(app(DailyFeeBlockedDateService::class)->isSaleProhibited($date));

        $this->logoutAdminPanel();
        $this->mockPaymentRedirect();
        $this->from('/guest/reserve')
            ->post(route('checkout.store', [], false), $this->guestDailyTicketPayload($date, $vt, 'DF111', 'df1@example.com'))
            ->assertRedirect('https://bank.example/pay');

        $this->assertDatabaseHas('temp_data', [
            'license_plate' => 'DF111',
            'reservation_kind' => ReservationKind::DAILY_TICKET,
        ]);
    }

    public function test_full_day_without_fee_checkbox_keeps_daily_fee_purchasable(): void
    {
        $admin = $this->admin('df2');
        $date = Carbon::now()->addDays(2)->toDateString();
        $slots = $this->seedSlots(3);
        $this->parkAll($date, $slots);
        $vt = $this->vehicleType();

        $this->actingAs($admin, 'panel_admin');
        $this->post(route('panel_admin.blocking.apply', [], false), [
            'date' => $date,
            'block_whole_day' => '1',
        ])->assertRedirect();

        $this->assertTrue(app(DailyFeeBlockedDateService::class)->isDateFullySlotBlocked($date));
        $this->assertFalse(app(DailyFeeBlockedDateService::class)->isSaleProhibited($date));

        $this->logoutAdminPanel();
        $this->mockPaymentRedirect();
        $this->from('/guest/reserve')
            ->post(route('checkout.store', [], false), $this->guestDailyTicketPayload($date, $vt, 'DF222', 'df2@example.com'))
            ->assertRedirect('https://bank.example/pay');

        $this->assertDatabaseHas('temp_data', ['license_plate' => 'DF222']);
    }

    public function test_full_day_with_fee_checkbox_rejects_new_daily_ticket_checkout(): void
    {
        $admin = $this->admin('df3');
        $date = Carbon::now()->addDays(3)->toDateString();
        $slots = $this->seedSlots(3);
        $this->parkAll($date, $slots);
        $vt = $this->vehicleType();

        $this->actingAs($admin, 'panel_admin');
        $this->post(route('panel_admin.blocking.apply', [], false), [
            'date' => $date,
            'block_whole_day' => '1',
            'block_daily_fee' => '1',
        ])->assertRedirect()->assertSessionHas('status');

        $this->assertTrue(app(DailyFeeBlockedDateService::class)->isSaleProhibited($date));

        $this->logoutAdminPanel();
        $this->from('/guest/reserve')
            ->post(route('checkout.store', [], false), $this->guestDailyTicketPayload($date, $vt, 'DF333', 'df3@example.com'))
            ->assertSessionHasErrors('reservation_date');

        $this->assertSame(0, TempData::query()->where('license_plate', 'DF333')->count());
    }

    public function test_fee_checkbox_without_whole_day_rejected(): void
    {
        $admin = $this->admin('df4');
        $date = Carbon::now()->addDays(4)->toDateString();
        $slots = $this->seedSlots(3);
        $this->parkAll($date, $slots);

        $this->actingAs($admin, 'panel_admin');
        $this->from(route('panel_admin.blocking', ['date' => $date], false))
            ->post(route('panel_admin.blocking.apply', [], false), [
                'date' => $date,
                'block_daily_fee' => '1',
                'slot_ids' => [$slots[0]->id],
            ])
            ->assertRedirect()
            ->assertSessionHasErrors('block_daily_fee');

        $this->assertSame(0, DailyFeeBlockedDate::query()->count());
    }

    public function test_pending_daily_ticket_before_block_can_success(): void
    {
        Bus::fake();
        $admin = $this->admin('df5');
        $date = Carbon::now()->addDays(5)->toDateString();
        $slots = $this->seedSlots(3);
        $this->parkAll($date, $slots);
        $vt = $this->vehicleType();

        $temp = TempData::query()->create([
            'merchant_transaction_id' => 'mt-df-pending',
            'retry_token' => 'rt-df-pending',
            'user_id' => null,
            'drop_off_time_slot_id' => null,
            'pick_up_time_slot_id' => null,
            'reservation_date' => $date,
            'user_name' => 'Guest',
            'country' => 'ME',
            'license_plate' => 'DF555',
            'vehicle_type_id' => $vt->id,
            'invoice_amount_snapshot' => '50.00',
            'email' => 'df5@example.com',
            'preferred_locale' => 'en',
            'status' => TempData::STATUS_PENDING,
            'reservation_kind' => ReservationKind::DAILY_TICKET,
        ]);

        $this->actingAs($admin, 'panel_admin');
        $this->post(route('panel_admin.blocking.apply', [], false), [
            'date' => $date,
            'block_whole_day' => '1',
            'block_daily_fee' => '1',
        ])->assertRedirect();

        $this->assertTrue(app(DailyFeeBlockedDateService::class)->isSaleProhibited($date));

        $ok = app(PaymentSuccessHandler::class)->handle($temp->fresh(), ['status' => 'success'], true, true);
        $this->assertTrue($ok);
        $this->assertDatabaseHas('reservations', [
            'merchant_transaction_id' => 'mt-df-pending',
            'reservation_kind' => ReservationKind::DAILY_TICKET,
            'status' => 'paid',
        ]);
    }

    public function test_partial_and_full_unblock_clear_fee_prohibition(): void
    {
        $admin = $this->admin('df6');
        $date = Carbon::now()->addDays(6)->toDateString();
        $slots = $this->seedSlots(3);
        $this->parkAll($date, $slots);

        $this->actingAs($admin, 'panel_admin');
        $this->post(route('panel_admin.blocking.apply', [], false), [
            'date' => $date,
            'block_whole_day' => '1',
            'block_daily_fee' => '1',
        ])->assertRedirect();
        $this->assertTrue(app(DailyFeeBlockedDateService::class)->isSaleProhibited($date));

        $this->post(route('panel_admin.blocking.unblock.apply', [], false), [
            'date' => $date,
            'slot_ids' => [$slots[0]->id],
        ])->assertRedirect();
        $this->assertFalse(app(DailyFeeBlockedDateService::class)->isSaleProhibited($date));

        // Re-block full day + fee, then full unblock.
        $this->post(route('panel_admin.blocking.apply', [], false), [
            'date' => $date,
            'block_whole_day' => '1',
            'block_daily_fee' => '1',
        ])->assertRedirect();
        $this->assertTrue(app(DailyFeeBlockedDateService::class)->isSaleProhibited($date));

        $this->post(route('panel_admin.blocking.unblock.apply', [], false), [
            'date' => $date,
            'unblock_all' => '1',
        ])->assertRedirect();
        $this->assertFalse(app(DailyFeeBlockedDateService::class)->isSaleProhibited($date));
    }

    /**
     * @return array{reservation: Reservation, row: BlockZoneWorklist, drop: ListOfTimeSlot, pick: ListOfTimeSlot, date: string, vt: VehicleType, slots: list<ListOfTimeSlot>}
     */
    private function readyToAdjustTimedSetup(string $mtid): array
    {
        $date = Carbon::now()->addDays(7)->toDateString();
        $slots = $this->seedSlots(3);
        $this->parkAll($date, $slots);
        $drop = $slots[0];
        $pick = $slots[1];
        DailyParkingData::query()->whereDate('date', $date)->where('time_slot_id', $drop->id)
            ->update(['is_blocked' => true, 'reserved' => 1]);
        DailyParkingData::query()->whereDate('date', $date)->where('time_slot_id', $pick->id)
            ->update(['is_blocked' => true, 'reserved' => 1]);

        $vt = $this->vehicleType(50);
        $reservation = Reservation::query()->create([
            'merchant_transaction_id' => $mtid,
            'drop_off_time_slot_id' => $drop->id,
            'pick_up_time_slot_id' => $pick->id,
            'reservation_date' => $date,
            'reservation_kind' => ReservationKind::TIME_SLOTS,
            'user_name' => 'Conv Guest',
            'country' => 'ME',
            'license_plate' => 'CV111',
            'vehicle_type_id' => $vt->id,
            'email' => 'conv@example.com',
            'status' => 'paid',
            'invoice_amount' => '50.00',
            'fiscal_jir' => 'JIR-CONV',
            'fiscal_ikof' => 'IKOF-CONV',
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
            'snapshot_json' => ['user_name' => 'Conv Guest', 'email' => 'conv@example.com'],
            'reservation_id' => $reservation->id,
        ]);

        return compact('reservation', 'row', 'drop', 'pick', 'date', 'vt', 'slots');
    }

    public function test_convert_same_date_to_daily_ticket(): void
    {
        Queue::fake();
        $admin = $this->admin('conv1');
        $ctx = $this->readyToAdjustTimedSetup('mt-conv-1');

        $this->actingAs($admin, 'panel_admin');
        $this->post(route('panel_admin.blocking.worklist.convert_daily_fee', $ctx['row'], false), [
            'daily_fee_date' => $ctx['date'],
        ])->assertRedirect()->assertSessionHas('status');

        $ctx['reservation']->refresh();
        $ctx['row']->refresh();

        $this->assertSame(ReservationKind::DAILY_TICKET, (string) $ctx['reservation']->reservation_kind);
        $this->assertNull($ctx['reservation']->drop_off_time_slot_id);
        $this->assertNull($ctx['reservation']->pick_up_time_slot_id);
        $this->assertSame($ctx['date'], $ctx['reservation']->reservation_date->toDateString());
        $this->assertSame('50.00', (string) $ctx['reservation']->invoice_amount);
        $this->assertSame('JIR-CONV', (string) $ctx['reservation']->fiscal_jir);
        $this->assertSame('IKOF-CONV', (string) $ctx['reservation']->fiscal_ikof);
        $this->assertSame((int) $ctx['vt']->id, (int) $ctx['reservation']->vehicle_type_id);
        $this->assertSame('CV111', (string) $ctx['reservation']->license_plate);

        $this->assertSame(0, (int) DailyParkingData::query()->whereDate('date', $ctx['date'])->where('time_slot_id', $ctx['drop']->id)->value('reserved'));
        $this->assertSame(0, (int) DailyParkingData::query()->whereDate('date', $ctx['date'])->where('time_slot_id', $ctx['pick']->id)->value('reserved'));
        $this->assertTrue((bool) DailyParkingData::query()->whereDate('date', $ctx['date'])->where('time_slot_id', $ctx['drop']->id)->value('is_blocked'));

        $this->assertSame(BlockZoneWorklist::STATUS_CONVERTED_TO_DAILY_FEE, (string) $ctx['row']->status);
        $this->assertSame(0, BlockZoneWorklist::query()->activeIntervention()->count());

        Queue::assertPushed(SendAdminUpdatedReservationDocumentJob::class);
    }

    public function test_convert_rejects_past_and_fee_blocked_destination(): void
    {
        $admin = $this->admin('conv2');
        $ctx = $this->readyToAdjustTimedSetup('mt-conv-2');

        $this->actingAs($admin, 'panel_admin');
        $this->post(route('panel_admin.blocking.worklist.convert_daily_fee', $ctx['row'], false), [
            'daily_fee_date' => Carbon::now()->subDay()->toDateString(),
        ])->assertSessionHasErrors('daily_fee_date');

        DailyFeeBlockedDate::query()->create([
            'date' => Carbon::now()->addDays(10)->toDateString(),
            'created_by_admin_id' => $admin->id,
        ]);

        $this->post(route('panel_admin.blocking.worklist.convert_daily_fee', $ctx['row'], false), [
            'daily_fee_date' => Carbon::now()->addDays(10)->toDateString(),
        ])->assertSessionHasErrors('daily_fee_date');

        $this->assertSame(ReservationKind::TIME_SLOTS, (string) $ctx['reservation']->fresh()->reservation_kind);
    }

    public function test_convert_idempotent_and_reconcile_preserves_terminal(): void
    {
        Queue::fake();
        $admin = $this->admin('conv3');
        $ctx = $this->readyToAdjustTimedSetup('mt-conv-3');

        $this->actingAs($admin, 'panel_admin');
        $this->post(route('panel_admin.blocking.worklist.convert_daily_fee', $ctx['row'], false), [
            'daily_fee_date' => $ctx['date'],
        ])->assertRedirect();

        $reservedAfter = (int) DailyParkingData::query()->whereDate('date', $ctx['date'])->where('time_slot_id', $ctx['drop']->id)->value('reserved');

        $this->post(route('panel_admin.blocking.worklist.convert_daily_fee', $ctx['row'], false), [
            'daily_fee_date' => $ctx['date'],
        ])->assertRedirect();

        $this->assertSame(
            $reservedAfter,
            (int) DailyParkingData::query()->whereDate('date', $ctx['date'])->where('time_slot_id', $ctx['drop']->id)->value('reserved')
        );

        app(\App\Services\AdminPanel\Blocking\BlockZoneWorklistService::class)
            ->reconcileForReservation($ctx['reservation']->fresh());

        $this->assertSame(1, BlockZoneWorklist::query()->count());
        $this->assertSame(
            BlockZoneWorklist::STATUS_CONVERTED_TO_DAILY_FEE,
            (string) BlockZoneWorklist::query()->first()->status
        );
    }

    public function test_pending_payment_cannot_convert(): void
    {
        $admin = $this->admin('conv4');
        $date = Carbon::now()->addDays(8)->toDateString();
        $slots = $this->seedSlots(2);
        $this->parkAll($date, $slots, blocked: true);

        $row = BlockZoneWorklist::query()->create([
            'merchant_transaction_id' => 'mt-conv-pending',
            'status' => BlockZoneWorklist::STATUS_PENDING_PAYMENT,
            'old_date' => $date,
            'old_drop_off' => $slots[0]->id,
            'old_pick_up' => $slots[1]->id,
            'affected_drop_off' => true,
            'affected_pick_up' => true,
            'snapshot_json' => [],
            'reservation_id' => null,
        ]);

        $this->actingAs($admin, 'panel_admin');
        $this->post(route('panel_admin.blocking.worklist.convert_daily_fee', $row, false), [
            'daily_fee_date' => $date,
        ])->assertSessionHasErrors('worklist');
    }

    public function test_guest_cannot_convert(): void
    {
        $ctx = $this->readyToAdjustTimedSetup('mt-conv-guest');
        $user = User::factory()->create();

        $this->actingAs($user)
            ->post(route('panel_admin.blocking.worklist.convert_daily_fee', $ctx['row'], false), [
                'daily_fee_date' => $ctx['date'],
            ])
            ->assertRedirect(route('panel_admin.login', [], false));

        $this->assertSame(BlockZoneWorklist::STATUS_READY_TO_ADJUST, (string) $ctx['row']->fresh()->status);
    }

    public function test_agency_advance_daily_ticket_rejected_when_fee_blocked(): void
    {
        config(['features.advance_payments' => true]);
        $admin = $this->admin('dfadv');
        $date = Carbon::now()->addDays(9)->toDateString();
        $slots = $this->seedSlots(3);
        $this->parkAll($date, $slots);
        $vt = $this->vehicleType();

        $agency = User::factory()->create(['lang' => 'cg']);
        AgencyAdvanceTransaction::query()->create([
            'agency_user_id' => $agency->id,
            'amount' => '100.00',
            'type' => AgencyAdvanceTransaction::TYPE_TOPUP,
            'reference_type' => null,
            'reference_id' => null,
            'merchant_transaction_id' => null,
            'note' => 'test',
        ]);

        $this->actingAs($admin, 'panel_admin');
        $this->post(route('panel_admin.blocking.apply', [], false), [
            'date' => $date,
            'block_whole_day' => '1',
            'block_daily_fee' => '1',
        ])->assertRedirect();

        $this->logoutAdminPanel();

        $vehicle = Vehicle::query()->create([
            'user_id' => $agency->id,
            'license_plate' => 'ADV111',
            'vehicle_type_id' => $vt->id,
            'status' => Vehicle::STATUS_ACTIVE,
        ]);

        $this->actingAs($agency)
            ->post(route('checkout.store', [], false), [
                'auth_panel_booking' => '1',
                'payment_method' => 'advance',
                'reservation_kind' => ReservationKind::DAILY_TICKET,
                'reservation_date' => $date,
                'vehicle_id' => $vehicle->id,
                'accept_terms' => 1,
            ])
            ->assertSessionHasErrors('reservation_date');

        $this->assertSame(0, Reservation::query()->where('license_plate', 'ADV111')->count());
    }
}
