<?php

namespace Tests\Feature\AdminPanel;

use App\Jobs\SendAdminUpdatedReservationDocumentJob;
use App\Jobs\SendInvoiceEmailJob;
use App\Services\Pdf\KotorPdfAssets;
use App\Services\Pdf\PaidInvoicePdfGenerator;
use Carbon\Carbon as CarbonDate;
use Illuminate\Support\Facades\View;
use App\Models\Admin;
use App\Models\DailyParkingData;
use App\Models\ListOfTimeSlot;
use App\Models\Reservation;
use App\Models\VehicleType;
use App\Models\VehicleTypeTranslation;
use App\Support\ReservationKind;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class AdminReservationEditHardeningTest extends TestCase
{
    use RefreshDatabase;

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function seedAdmin(): Admin
    {
        return Admin::query()->create([
            'username' => 'editadmin',
            'email' => 'edit-admin@example.com',
            'password' => bcrypt('secret-password-edit'),
            'control_access' => false,
            'admin_access' => true,
        ]);
    }

    /**
     * @return array{vt: VehicleType, paidDrop: ListOfTimeSlot, paidPick: ListOfTimeSlot, freeDrop: ListOfTimeSlot, freePick: ListOfTimeSlot, s1: ListOfTimeSlot, s41: ListOfTimeSlot}
     */
    private function seedSlotsAndVehicle(): array
    {
        if (! ListOfTimeSlot::query()->whereKey(1)->exists()) {
            ListOfTimeSlot::query()->insert(['id' => 1, 'time_slot' => '00:10 - 00:30']);
        }
        if (! ListOfTimeSlot::query()->whereKey(41)->exists()) {
            ListOfTimeSlot::query()->insert(['id' => 41, 'time_slot' => '23:00 - 24:00']);
        }
        $s1 = ListOfTimeSlot::query()->findOrFail(1);
        $s41 = ListOfTimeSlot::query()->findOrFail(41);

        $paidDrop = ListOfTimeSlot::query()->create(['time_slot' => '10:00 - 10:20']);
        $paidPick = ListOfTimeSlot::query()->create(['time_slot' => '11:00 - 11:20']);
        $freeDrop = ListOfTimeSlot::query()->create(['time_slot' => '00:00 - 01:00']);
        $freePick = ListOfTimeSlot::query()->create(['time_slot' => '01:00 - 02:00']);

        $vt = VehicleType::query()->create(['price' => 25]);
        foreach (['en', 'cg'] as $locale) {
            VehicleTypeTranslation::query()->create([
                'vehicle_type_id' => $vt->id,
                'locale' => $locale,
                'name' => 'Bus',
                'description' => null,
            ]);
        }

        return compact('vt', 'paidDrop', 'paidPick', 'freeDrop', 'freePick', 's1', 's41');
    }

    /**
     * @param  list<ListOfTimeSlot>  $slots
     */
    private function seedDailyForDate(string $date, array $slots, int $capacity = 5, int $reserved = 0): void
    {
        foreach ($slots as $slot) {
            DailyParkingData::query()->create([
                'date' => $date,
                'time_slot_id' => $slot->id,
                'capacity' => $capacity,
                'reserved' => $reserved,
                'pending' => 0,
                'is_blocked' => false,
            ]);
        }
    }

    private function validPayload(Reservation $r, array $overrides = []): array
    {
        return array_merge([
            'reservation_date' => $r->reservation_date->toDateString(),
            'drop_off_time_slot_id' => $r->drop_off_time_slot_id,
            'pick_up_time_slot_id' => $r->pick_up_time_slot_id,
            'user_name' => $r->user_name,
            'country' => $r->country,
            'license_plate' => $r->license_plate,
            'vehicle_type_id' => $r->vehicle_type_id,
            'email' => $r->email,
            'return_query' => '',
        ], $overrides);
    }

    public function test_search_results_show_pdf_and_izmeni_for_upcoming(): void
    {
        $admin = $this->seedAdmin();
        $slots = $this->seedSlotsAndVehicle();
        $d = Carbon::now()->addDays(2)->toDateString();
        $this->seedDailyForDate($d, [$slots['paidDrop'], $slots['paidPick']]);

        $mtid = 'mt-list-actions-'.uniqid();
        Reservation::query()->create([
            'merchant_transaction_id' => $mtid,
            'drop_off_time_slot_id' => $slots['paidDrop']->id,
            'pick_up_time_slot_id' => $slots['paidPick']->id,
            'reservation_date' => $d,
            'user_name' => 'List User',
            'country' => 'ME',
            'license_plate' => 'KO100AA',
            'vehicle_type_id' => $slots['vt']->id,
            'email' => 'list@example.com',
            'status' => 'paid',
            'invoice_amount' => '25.00',
            'email_sent' => Reservation::EMAIL_NOT_SENT,
        ]);

        $this->actingAs($admin, 'panel_admin');

        $this->get(route('panel_admin.reservations', ['merchant_transaction_id' => $mtid], false))
            ->assertOk()
            ->assertSee('PDF', false)
            ->assertSee('Izmeni', false);
    }

    public function test_expired_realized_reservation_cannot_be_edited(): void
    {
        $admin = $this->seedAdmin();
        $slots = $this->seedSlotsAndVehicle();
        $past = Carbon::now()->subDays(2)->toDateString();
        $this->seedDailyForDate($past, [$slots['paidDrop'], $slots['paidPick']]);

        $r = Reservation::query()->create([
            'merchant_transaction_id' => 'mt-expired-edit',
            'drop_off_time_slot_id' => $slots['paidDrop']->id,
            'pick_up_time_slot_id' => $slots['paidPick']->id,
            'reservation_date' => $past,
            'user_name' => 'Past',
            'country' => 'ME',
            'license_plate' => 'KOEXP1',
            'vehicle_type_id' => $slots['vt']->id,
            'email' => 'past@example.com',
            'status' => 'paid',
            'invoice_amount' => '25.00',
            'email_sent' => Reservation::EMAIL_NOT_SENT,
        ]);

        $this->actingAs($admin, 'panel_admin');

        $this->get(route('panel_admin.reservations.edit', ['reservation' => $r], false))
            ->assertForbidden();

        $this->put(route('panel_admin.reservations.update', $r, false), $this->validPayload($r, [
            'license_plate' => 'KOEXP2',
        ]))->assertForbidden();
    }

    public function test_paid_reservation_moved_to_free_slots_stays_paid_with_same_invoice(): void
    {
        Queue::fake();
        $admin = $this->seedAdmin();
        $slots = $this->seedSlotsAndVehicle();
        $d = Carbon::now()->addDays(3)->toDateString();
        $this->seedDailyForDate($d, [$slots['paidDrop'], $slots['paidPick'], $slots['freeDrop'], $slots['freePick']]);

        $r = Reservation::query()->create([
            'merchant_transaction_id' => 'mt-paid-to-free',
            'drop_off_time_slot_id' => $slots['paidDrop']->id,
            'pick_up_time_slot_id' => $slots['paidPick']->id,
            'reservation_date' => $d,
            'user_name' => 'Paid Mover',
            'country' => 'ME',
            'license_plate' => 'KOPF01',
            'vehicle_type_id' => $slots['vt']->id,
            'email' => 'paidfree@example.com',
            'status' => 'paid',
            'invoice_amount' => '99.99',
            'email_sent' => Reservation::EMAIL_SENT,
        ]);

        DailyParkingData::query()
            ->whereDate('date', $d)
            ->whereIn('time_slot_id', [$slots['paidDrop']->id, $slots['paidPick']->id])
            ->increment('reserved');

        $this->actingAs($admin, 'panel_admin');

        $this->put(route('panel_admin.reservations.update', $r, false), $this->validPayload($r, [
            'drop_off_time_slot_id' => $slots['freeDrop']->id,
            'pick_up_time_slot_id' => $slots['freePick']->id,
        ]))->assertRedirect();

        $r->refresh();
        $this->assertSame('paid', $r->status);
        $this->assertSame('99.99', (string) $r->invoice_amount);
        $this->assertSame($slots['freeDrop']->id, $r->drop_off_time_slot_id);
    }

    public function test_non_admin_free_reservation_cannot_move_to_paid_slots(): void
    {
        $admin = $this->seedAdmin();
        $slots = $this->seedSlotsAndVehicle();
        $d = Carbon::now()->addDays(4)->toDateString();
        $this->seedDailyForDate($d, [$slots['s1'], $slots['s41'], $slots['paidDrop'], $slots['paidPick']]);

        $r = Reservation::query()->create([
            'merchant_transaction_id' => null,
            'drop_off_time_slot_id' => $slots['s1']->id,
            'pick_up_time_slot_id' => $slots['s41']->id,
            'reservation_date' => $d,
            'user_name' => 'Agency Free',
            'country' => 'ME',
            'license_plate' => 'FR001',
            'vehicle_type_id' => $slots['vt']->id,
            'email' => 'agencyfree@example.com',
            'status' => 'free',
            'invoice_amount' => '0.00',
            'email_sent' => Reservation::EMAIL_SENT,
            'created_by_admin' => false,
        ]);

        DailyParkingData::query()
            ->whereDate('date', $d)
            ->whereIn('time_slot_id', [$slots['s1']->id, $slots['s41']->id])
            ->increment('reserved');

        $this->actingAs($admin, 'panel_admin');

        $this->put(route('panel_admin.reservations.update', $r, false), $this->validPayload($r, [
            'drop_off_time_slot_id' => $slots['paidDrop']->id,
            'pick_up_time_slot_id' => $slots['paidPick']->id,
        ]))->assertRedirect()->assertSessionHas('error');

        $r->refresh();
        $this->assertSame($slots['s1']->id, $r->drop_off_time_slot_id);
        $this->assertSame($slots['s41']->id, $r->pick_up_time_slot_id);
    }

    public function test_admin_created_free_reservation_can_move_to_paid_slots(): void
    {
        Queue::fake();
        $admin = $this->seedAdmin();
        $slots = $this->seedSlotsAndVehicle();
        $d = Carbon::now()->addDays(5)->toDateString();
        $this->seedDailyForDate($d, [$slots['s1'], $slots['s41'], $slots['paidDrop'], $slots['paidPick']]);

        $r = Reservation::query()->create([
            'merchant_transaction_id' => null,
            'drop_off_time_slot_id' => $slots['s1']->id,
            'pick_up_time_slot_id' => $slots['s41']->id,
            'reservation_date' => $d,
            'user_name' => 'Admin Free',
            'country' => 'ME',
            'license_plate' => 'AF001',
            'vehicle_type_id' => $slots['vt']->id,
            'email' => 'adminfree@example.com',
            'status' => 'free',
            'invoice_amount' => '0.00',
            'email_sent' => Reservation::EMAIL_SENT,
            'created_by_admin' => true,
        ]);

        DailyParkingData::query()
            ->whereDate('date', $d)
            ->whereIn('time_slot_id', [$slots['s1']->id, $slots['s41']->id])
            ->increment('reserved');

        $this->actingAs($admin, 'panel_admin');

        $this->put(route('panel_admin.reservations.update', $r, false), $this->validPayload($r, [
            'drop_off_time_slot_id' => $slots['paidDrop']->id,
            'pick_up_time_slot_id' => $slots['paidPick']->id,
        ]))->assertRedirect();

        $r->refresh();
        $this->assertSame($slots['paidDrop']->id, $r->drop_off_time_slot_id);
        $this->assertSame(1, (int) DailyParkingData::query()
            ->whereDate('date', $d)
            ->where('time_slot_id', $slots['paidDrop']->id)
            ->value('reserved'));
    }

    public function test_time_slots_update_releases_old_capacity_and_reserves_new(): void
    {
        Queue::fake();
        $admin = $this->seedAdmin();
        $slots = $this->seedSlotsAndVehicle();
        $d = Carbon::now()->addDays(6)->toDateString();
        $this->seedDailyForDate($d, [$slots['paidDrop'], $slots['paidPick']]);

        $r = Reservation::query()->create([
            'merchant_transaction_id' => 'mt-cap-swap',
            'drop_off_time_slot_id' => $slots['paidDrop']->id,
            'pick_up_time_slot_id' => $slots['paidPick']->id,
            'reservation_date' => $d,
            'user_name' => 'Cap',
            'country' => 'ME',
            'license_plate' => 'KOCAP1',
            'vehicle_type_id' => $slots['vt']->id,
            'email' => 'cap@example.com',
            'status' => 'paid',
            'invoice_amount' => '25.00',
            'email_sent' => Reservation::EMAIL_NOT_SENT,
        ]);

        DailyParkingData::query()
            ->whereDate('date', $d)
            ->whereIn('time_slot_id', [$slots['paidDrop']->id, $slots['paidPick']->id])
            ->increment('reserved');

        $otherDrop = ListOfTimeSlot::query()->create(['time_slot' => '12:00 - 12:20']);
        $otherPick = ListOfTimeSlot::query()->create(['time_slot' => '13:00 - 13:20']);
        $this->seedDailyForDate($d, [$otherDrop, $otherPick]);

        $this->actingAs($admin, 'panel_admin');

        $this->put(route('panel_admin.reservations.update', $r, false), $this->validPayload($r, [
            'drop_off_time_slot_id' => $otherDrop->id,
            'pick_up_time_slot_id' => $otherPick->id,
        ]))->assertRedirect();

        $this->assertSame(0, (int) DailyParkingData::query()
            ->whereDate('date', $d)
            ->where('time_slot_id', $slots['paidDrop']->id)
            ->value('reserved'));
        $this->assertSame(1, (int) DailyParkingData::query()
            ->whereDate('date', $d)
            ->where('time_slot_id', $otherDrop->id)
            ->value('reserved'));
    }

    public function test_failed_update_rolls_back_capacity_and_reservation(): void
    {
        $admin = $this->seedAdmin();
        $slots = $this->seedSlotsAndVehicle();
        $d = Carbon::now()->addDays(7)->toDateString();
        $this->seedDailyForDate($d, [$slots['paidDrop'], $slots['paidPick']], capacity: 5);

        $blockerDrop = ListOfTimeSlot::query()->create(['time_slot' => '14:00 - 14:20']);
        $blockerPick = ListOfTimeSlot::query()->create(['time_slot' => '15:00 - 15:20']);
        $this->seedDailyForDate($d, [$blockerDrop, $blockerPick], capacity: 1, reserved: 1);

        $r = Reservation::query()->create([
            'merchant_transaction_id' => 'mt-rollback',
            'drop_off_time_slot_id' => $slots['paidDrop']->id,
            'pick_up_time_slot_id' => $slots['paidPick']->id,
            'reservation_date' => $d,
            'user_name' => 'Rollback',
            'country' => 'ME',
            'license_plate' => 'KORB01',
            'vehicle_type_id' => $slots['vt']->id,
            'email' => 'rb@example.com',
            'status' => 'paid',
            'invoice_amount' => '25.00',
            'email_sent' => Reservation::EMAIL_NOT_SENT,
        ]);

        DailyParkingData::query()
            ->whereDate('date', $d)
            ->whereIn('time_slot_id', [$slots['paidDrop']->id, $slots['paidPick']->id])
            ->increment('reserved');

        $this->actingAs($admin, 'panel_admin');

        $this->put(route('panel_admin.reservations.update', $r, false), $this->validPayload($r, [
            'drop_off_time_slot_id' => $blockerDrop->id,
            'pick_up_time_slot_id' => $blockerPick->id,
            'license_plate' => 'KORB99',
        ]))->assertRedirect()->assertSessionHas('error');

        $r->refresh();
        $this->assertSame('KORB01', $r->license_plate);
        $this->assertSame(1, (int) DailyParkingData::query()
            ->whereDate('date', $d)
            ->where('time_slot_id', $slots['paidDrop']->id)
            ->value('reserved'));
    }

    public function test_daily_ticket_edit_form_has_no_slot_fields(): void
    {
        $admin = $this->seedAdmin();
        $d = Carbon::now()->addDays(8)->toDateString();
        $vt = VehicleType::query()->create(['price' => 20]);

        $r = Reservation::query()->create([
            'merchant_transaction_id' => 'mt-dk-form',
            'reservation_kind' => ReservationKind::DAILY_TICKET,
            'drop_off_time_slot_id' => null,
            'pick_up_time_slot_id' => null,
            'reservation_date' => $d,
            'user_name' => 'DK User',
            'country' => 'ME',
            'license_plate' => 'KODK01',
            'vehicle_type_id' => $vt->id,
            'email' => 'dk@example.com',
            'status' => 'paid',
            'invoice_amount' => '20.00',
            'email_sent' => Reservation::EMAIL_NOT_SENT,
        ]);

        $this->actingAs($admin, 'panel_admin');

        $this->get(route('panel_admin.reservations.edit', ['reservation' => $r], false))
            ->assertOk()
            ->assertSee('Sačuvaj', false)
            ->assertDontSee('drop_off_time_slot_id', false)
            ->assertDontSee('pick_up_time_slot_id', false);
    }

    public function test_daily_ticket_update_does_not_touch_daily_parking_data(): void
    {
        Queue::fake();
        $admin = $this->seedAdmin();
        $slots = $this->seedSlotsAndVehicle();
        $d = Carbon::now()->addDays(9)->toDateString();
        $this->seedDailyForDate($d, [$slots['paidDrop']], reserved: 2);

        $beforeCount = DailyParkingData::query()->count();
        $beforeReserved = (int) DailyParkingData::query()
            ->whereDate('date', $d)
            ->where('time_slot_id', $slots['paidDrop']->id)
            ->value('reserved');

        $r = Reservation::query()->create([
            'merchant_transaction_id' => 'mt-dk-update',
            'reservation_kind' => ReservationKind::DAILY_TICKET,
            'drop_off_time_slot_id' => null,
            'pick_up_time_slot_id' => null,
            'reservation_date' => $d,
            'user_name' => 'DK User',
            'country' => 'ME',
            'license_plate' => 'KODK02',
            'vehicle_type_id' => $slots['vt']->id,
            'email' => 'dk@example.com',
            'status' => 'paid',
            'invoice_amount' => '20.00',
            'email_sent' => Reservation::EMAIL_NOT_SENT,
        ]);

        $d2 = Carbon::now()->addDays(10)->toDateString();
        $this->actingAs($admin, 'panel_admin');

        $this->put(route('panel_admin.reservations.update', $r, false), [
            'reservation_date' => $d2,
            'user_name' => 'DK Updated',
            'country' => 'ME',
            'license_plate' => 'KODK03',
            'vehicle_type_id' => $slots['vt']->id,
            'email' => 'dk-updated@example.com',
            'return_query' => '',
        ])->assertRedirect();

        $this->assertSame($beforeCount, DailyParkingData::query()->count());
        $this->assertSame($beforeReserved, (int) DailyParkingData::query()
            ->whereDate('date', $d)
            ->where('time_slot_id', $slots['paidDrop']->id)
            ->value('reserved'));
        $this->assertSame($d2, $r->fresh()->reservation_date->toDateString());
        Queue::assertPushed(SendAdminUpdatedReservationDocumentJob::class);
        Queue::assertNotPushed(SendInvoiceEmailJob::class);
    }

    public function test_reservation_kind_status_and_mtid_cannot_be_changed_via_update(): void
    {
        $admin = $this->seedAdmin();
        $slots = $this->seedSlotsAndVehicle();
        $d = Carbon::now()->addDays(11)->toDateString();
        $this->seedDailyForDate($d, [$slots['paidDrop'], $slots['paidPick']]);

        $r = Reservation::query()->create([
            'merchant_transaction_id' => 'mt-immutable',
            'drop_off_time_slot_id' => $slots['paidDrop']->id,
            'pick_up_time_slot_id' => $slots['paidPick']->id,
            'reservation_date' => $d,
            'user_name' => 'Immutable',
            'country' => 'ME',
            'license_plate' => 'KOIM01',
            'vehicle_type_id' => $slots['vt']->id,
            'email' => 'im@example.com',
            'status' => 'paid',
            'invoice_amount' => '25.00',
            'email_sent' => Reservation::EMAIL_NOT_SENT,
        ]);

        DailyParkingData::query()
            ->whereDate('date', $d)
            ->whereIn('time_slot_id', [$slots['paidDrop']->id, $slots['paidPick']->id])
            ->increment('reserved');

        $this->actingAs($admin, 'panel_admin');

        $this->put(route('panel_admin.reservations.update', $r, false), array_merge($this->validPayload($r), [
            'status' => 'free',
            'merchant_transaction_id' => 'hacked-mtid',
            'reservation_kind' => ReservationKind::DAILY_TICKET,
            'invoice_amount' => '0.01',
        ]))->assertSessionHasErrors(['status', 'merchant_transaction_id', 'reservation_kind', 'invoice_amount']);
    }

    public function test_license_plate_normalized_to_uppercase(): void
    {
        Queue::fake();
        $admin = $this->seedAdmin();
        $slots = $this->seedSlotsAndVehicle();
        $d = Carbon::now()->addDays(12)->toDateString();
        $this->seedDailyForDate($d, [$slots['paidDrop'], $slots['paidPick']]);

        $r = Reservation::query()->create([
            'merchant_transaction_id' => 'mt-plate-norm',
            'drop_off_time_slot_id' => $slots['paidDrop']->id,
            'pick_up_time_slot_id' => $slots['paidPick']->id,
            'reservation_date' => $d,
            'user_name' => 'Plate',
            'country' => 'ME',
            'license_plate' => 'KOLOW1',
            'vehicle_type_id' => $slots['vt']->id,
            'email' => 'plate@example.com',
            'status' => 'paid',
            'invoice_amount' => '25.00',
            'email_sent' => Reservation::EMAIL_NOT_SENT,
        ]);

        DailyParkingData::query()
            ->whereDate('date', $d)
            ->whereIn('time_slot_id', [$slots['paidDrop']->id, $slots['paidPick']->id])
            ->increment('reserved');

        $this->actingAs($admin, 'panel_admin');

        $this->put(route('panel_admin.reservations.update', $r, false), $this->validPayload($r, [
            'license_plate' => 'ab 12 cd',
        ]))->assertRedirect();

        $this->assertSame('AB12CD', $r->fresh()->license_plate);
    }

    public function test_pick_up_only_mode_keeps_drop_off_and_date(): void
    {
        Queue::fake();
        Carbon::setTestNow(Carbon::today()->setTime(10, 30));

        $admin = $this->seedAdmin();
        $drop = ListOfTimeSlot::query()->create(['time_slot' => '08:00 - 08:20']);
        $pick = ListOfTimeSlot::query()->create(['time_slot' => '18:00 - 18:20']);
        $newPick = ListOfTimeSlot::query()->create(['time_slot' => '19:00 - 19:20']);
        $otherDrop = ListOfTimeSlot::query()->create(['time_slot' => '09:00 - 09:20']);
        $today = Carbon::today()->toDateString();
        $this->seedDailyForDate($today, [$drop, $pick, $newPick, $otherDrop]);

        $r = Reservation::query()->create([
            'merchant_transaction_id' => 'mt-pick-only',
            'drop_off_time_slot_id' => $drop->id,
            'pick_up_time_slot_id' => $pick->id,
            'reservation_date' => $today,
            'user_name' => 'Today',
            'country' => 'ME',
            'license_plate' => 'KOTDY1',
            'vehicle_type_id' => VehicleType::query()->create(['price' => 10])->id,
            'email' => 'today@example.com',
            'status' => 'paid',
            'invoice_amount' => '10.00',
            'email_sent' => Reservation::EMAIL_NOT_SENT,
        ]);

        DailyParkingData::query()
            ->whereDate('date', $today)
            ->whereIn('time_slot_id', [$drop->id, $pick->id])
            ->increment('reserved');

        $this->actingAs($admin, 'panel_admin');

        $this->put(route('panel_admin.reservations.update', $r, false), $this->validPayload($r, [
            'reservation_date' => Carbon::today()->addDay()->toDateString(),
            'drop_off_time_slot_id' => $otherDrop->id,
            'pick_up_time_slot_id' => $newPick->id,
        ]))->assertRedirect();

        $r->refresh();
        $this->assertSame($today, $r->reservation_date->toDateString());
        $this->assertSame($drop->id, $r->drop_off_time_slot_id);
        $this->assertSame($newPick->id, $r->pick_up_time_slot_id);
    }

    public function test_successful_time_slots_edit_dispatches_updated_pdf_email_job(): void
    {
        Queue::fake();

        $admin = $this->seedAdmin();
        $slots = $this->seedSlotsAndVehicle();
        $d = Carbon::now()->addDays(13)->toDateString();
        $this->seedDailyForDate($d, [$slots['paidDrop'], $slots['paidPick']]);

        $r = Reservation::query()->create([
            'merchant_transaction_id' => 'mt-email-job-ts',
            'drop_off_time_slot_id' => $slots['paidDrop']->id,
            'pick_up_time_slot_id' => $slots['paidPick']->id,
            'reservation_date' => $d,
            'user_name' => 'Email TS',
            'country' => 'ME',
            'license_plate' => 'KOEM01',
            'vehicle_type_id' => $slots['vt']->id,
            'email' => 'email-ts@example.com',
            'status' => 'paid',
            'invoice_amount' => '25.00',
            'fiscal_jir' => 'JIR-TEST-123',
            'email_sent' => Reservation::EMAIL_SENT,
        ]);

        DailyParkingData::query()
            ->whereDate('date', $d)
            ->whereIn('time_slot_id', [$slots['paidDrop']->id, $slots['paidPick']->id])
            ->increment('reserved');

        $this->actingAs($admin, 'panel_admin');

        $this->put(route('panel_admin.reservations.update', $r, false), $this->validPayload($r, [
            'license_plate' => 'KOEM99',
        ]))->assertRedirect();

        Queue::assertPushed(SendAdminUpdatedReservationDocumentJob::class, function (SendAdminUpdatedReservationDocumentJob $job) use ($r): bool {
            return $job->reservationId === $r->id
                && in_array('license_plate', $job->changedFields, true);
        });
    }

    public function test_failed_validation_does_not_dispatch_updated_email_job(): void
    {
        Queue::fake();

        $admin = $this->seedAdmin();
        $slots = $this->seedSlotsAndVehicle();
        $d = Carbon::now()->addDays(14)->toDateString();
        $this->seedDailyForDate($d, [$slots['paidDrop'], $slots['paidPick']]);

        $r = Reservation::query()->create([
            'merchant_transaction_id' => 'mt-no-email',
            'drop_off_time_slot_id' => $slots['paidDrop']->id,
            'pick_up_time_slot_id' => $slots['paidPick']->id,
            'reservation_date' => $d,
            'user_name' => 'No Email',
            'country' => 'ME',
            'license_plate' => 'KONE01',
            'vehicle_type_id' => $slots['vt']->id,
            'email' => 'no-email@example.com',
            'status' => 'paid',
            'invoice_amount' => '25.00',
            'email_sent' => Reservation::EMAIL_NOT_SENT,
        ]);

        $this->actingAs($admin, 'panel_admin');

        $this->put(route('panel_admin.reservations.update', $r, false), $this->validPayload($r, [
            'status' => 'free',
        ]))->assertSessionHasErrors(['status']);

        Queue::assertNothingPushed();
    }

    public function test_immutable_payment_fields_unchanged_after_edit(): void
    {
        Queue::fake();

        $admin = $this->seedAdmin();
        $slots = $this->seedSlotsAndVehicle();
        $d = Carbon::now()->addDays(15)->toDateString();
        $this->seedDailyForDate($d, [$slots['paidDrop'], $slots['paidPick']]);

        $r = Reservation::query()->create([
            'merchant_transaction_id' => 'mt-immutable',
            'drop_off_time_slot_id' => $slots['paidDrop']->id,
            'pick_up_time_slot_id' => $slots['paidPick']->id,
            'reservation_date' => $d,
            'user_name' => 'Immutable',
            'country' => 'ME',
            'license_plate' => 'KOIM01',
            'vehicle_type_id' => $slots['vt']->id,
            'email' => 'immutable@example.com',
            'status' => 'paid',
            'invoice_amount' => '77.77',
            'fiscal_jir' => 'JIR-KEEP',
            'email_sent' => Reservation::EMAIL_SENT,
        ]);

        DailyParkingData::query()
            ->whereDate('date', $d)
            ->whereIn('time_slot_id', [$slots['paidDrop']->id, $slots['paidPick']->id])
            ->increment('reserved');

        $this->actingAs($admin, 'panel_admin');

        $this->put(route('panel_admin.reservations.update', $r, false), $this->validPayload($r, [
            'license_plate' => 'KOIM99',
        ]))->assertRedirect();

        $r->refresh();
        $this->assertSame('paid', $r->status);
        $this->assertSame('mt-immutable', $r->merchant_transaction_id);
        $this->assertSame('77.77', (string) $r->invoice_amount);
        $this->assertSame('JIR-KEEP', $r->fiscal_jir);
    }

    public function test_regenerated_pdf_reflects_updated_license_plate(): void
    {
        $slots = $this->seedSlotsAndVehicle();
        $d = Carbon::now()->addDays(16)->toDateString();

        $r = Reservation::query()->create([
            'merchant_transaction_id' => 'mt-pdf-plate',
            'drop_off_time_slot_id' => $slots['paidDrop']->id,
            'pick_up_time_slot_id' => $slots['paidPick']->id,
            'reservation_date' => $d,
            'user_name' => 'PDF Plate',
            'country' => 'ME',
            'license_plate' => 'KOOLD1',
            'vehicle_type_id' => $slots['vt']->id,
            'email' => 'pdf@example.com',
            'status' => 'paid',
            'invoice_amount' => '25.00',
            'email_sent' => Reservation::EMAIL_NOT_SENT,
        ]);

        $r->update(['license_plate' => 'KONEW9']);
        $r->load(['vehicleType.translations', 'dropOffTimeSlot', 'pickUpTimeSlot']);

        $html = View::make('pdf.paid-invoice', [
            'reservation' => $r,
            'isFiscal' => false,
            'isDailyTicket' => false,
            'validityDateDisplay' => CarbonDate::parse($r->reservation_date)->format('d.m.Y'),
            'logoDataUri' => KotorPdfAssets::logoDataUri(),
            'qrDataUri' => null,
            'countryDisplay' => KotorPdfAssets::countryDisplayCg((string) $r->country),
            'vehicleLine' => 'Bus',
            'unitPrice' => (float) $r->invoice_amount,
            'fiscalDateTime' => CarbonDate::parse($r->created_at ?? now()),
            'internalNumber' => null,
            'nonFiscalNote' => PaidInvoicePdfGenerator::nonFiscalNoteFor($r),
        ])->render();

        $this->assertStringContainsString('KONEW9', $html);
        $this->assertStringNotContainsString('KOOLD1', $html);
    }

    public function test_realized_reservation_shows_disabled_izmeni_in_list(): void
    {
        $admin = $this->seedAdmin();
        $slots = $this->seedSlotsAndVehicle();
        $past = Carbon::now()->subDays(3)->toDateString();
        $this->seedDailyForDate($past, [$slots['paidDrop'], $slots['paidPick']]);

        $mtid = 'mt-realized-list-'.uniqid();
        Reservation::query()->create([
            'merchant_transaction_id' => $mtid,
            'drop_off_time_slot_id' => $slots['paidDrop']->id,
            'pick_up_time_slot_id' => $slots['paidPick']->id,
            'reservation_date' => $past,
            'user_name' => 'Past List',
            'country' => 'ME',
            'license_plate' => 'KOPAST1',
            'vehicle_type_id' => $slots['vt']->id,
            'email' => 'past-list@example.com',
            'status' => 'paid',
            'invoice_amount' => '25.00',
            'email_sent' => Reservation::EMAIL_NOT_SENT,
        ]);

        $this->actingAs($admin, 'panel_admin');

        $this->get(route('panel_admin.reservations', ['merchant_transaction_id' => $mtid], false))
            ->assertOk()
            ->assertSee('PDF', false)
            ->assertSee('Izmeni', false)
            ->assertSee('Realizovana rezervacija', false);
    }
}
