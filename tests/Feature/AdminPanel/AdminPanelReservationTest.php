<?php

namespace Tests\Feature\AdminPanel;

use App\Jobs\SendAdminUpdatedReservationDocumentJob;
use App\Jobs\SendFreeReservationConfirmationJob;
use App\Jobs\SendInvoiceEmailJob;
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

class AdminPanelReservationTest extends TestCase
{
    use RefreshDatabase;

    private function seedAdmin(): Admin
    {
        return Admin::query()->create([
            'username' => 'resvadmin',
            'email' => 'resv-admin@example.com',
            'password' => bcrypt('secret-password-rv'),
            'control_access' => false,
            'admin_access' => true,
        ]);
    }

    /**
     * @return array{s1: ListOfTimeSlot, s2: ListOfTimeSlot, s3: ListOfTimeSlot, vt: VehicleType}
     */
    private function seedVehicleAndThreeSlots(): array
    {
        $s1 = ListOfTimeSlot::query()->create(['time_slot' => '10:00 - 10:20']);
        $s2 = ListOfTimeSlot::query()->create(['time_slot' => '11:00 - 11:20']);
        $s3 = ListOfTimeSlot::query()->create(['time_slot' => '12:00 - 12:20']);
        $vt = VehicleType::query()->create(['price' => 15]);
        foreach (['en', 'cg'] as $locale) {
            VehicleTypeTranslation::query()->create([
                'vehicle_type_id' => $vt->id,
                'locale' => $locale,
                'name' => 'Bus',
                'description' => null,
            ]);
        }

        return ['s1' => $s1, 's2' => $s2, 's3' => $s3, 'vt' => $vt];
    }

    /**
     * @param  list<ListOfTimeSlot>  $slots
     */
    private function seedDailyForDate(string $date, array $slots): void
    {
        foreach ($slots as $slot) {
            DailyParkingData::query()->create([
                'date' => $date,
                'time_slot_id' => $slot->id,
                'capacity' => 5,
                'reserved' => 0,
                'pending' => 0,
                'is_blocked' => false,
            ]);
        }
    }

    public function test_guest_is_redirected_from_reservations_index(): void
    {
        $this->get(route('panel_admin.reservations', [], false))
            ->assertRedirect();
    }

    public function test_admin_can_open_reservations_search_page(): void
    {
        $admin = $this->seedAdmin();
        $this->actingAs($admin, 'panel_admin');

        $this->get(route('panel_admin.reservations', [], false))
            ->assertOk()
            ->assertSee('Rezervacije', false);
    }

    public function test_search_by_merchant_transaction_id_returns_reservation(): void
    {
        $admin = $this->seedAdmin();
        $d = Carbon::now()->addDays(3)->toDateString();
        $slots = $this->seedVehicleAndThreeSlots();
        $this->seedDailyForDate($d, [$slots['s1'], $slots['s2'], $slots['s3']]);

        $mtid = 'mt-admin-search-unique-'.uniqid();
        Reservation::query()->create([
            'merchant_transaction_id' => $mtid,
            'drop_off_time_slot_id' => $slots['s1']->id,
            'pick_up_time_slot_id' => $slots['s2']->id,
            'reservation_date' => $d,
            'user_name' => 'Test User',
            'country' => 'ME',
            'license_plate' => 'KO999AA',
            'vehicle_type_id' => $slots['vt']->id,
            'email' => 'search@example.com',
            'status' => 'paid',
            'invoice_amount' => '15.00',
            'email_sent' => Reservation::EMAIL_NOT_SENT,
        ]);

        DailyParkingData::query()
            ->whereDate('date', $d)
            ->whereIn('time_slot_id', [$slots['s1']->id, $slots['s2']->id])
            ->increment('reserved');

        $this->actingAs($admin, 'panel_admin');

        $this->get(route('panel_admin.reservations', ['merchant_transaction_id' => $mtid], false))
            ->assertOk()
            ->assertSee($mtid, false)
            ->assertSee('Rezultati', false);
    }

    public function test_edit_returns_403_for_realized_reservation(): void
    {
        $admin = $this->seedAdmin();
        $past = Carbon::now()->subDay()->toDateString();
        $slots = $this->seedVehicleAndThreeSlots();
        $this->seedDailyForDate($past, [$slots['s1'], $slots['s2'], $slots['s3']]);

        $r = Reservation::query()->create([
            'merchant_transaction_id' => 'mt-past-realized',
            'drop_off_time_slot_id' => $slots['s1']->id,
            'pick_up_time_slot_id' => $slots['s2']->id,
            'reservation_date' => $past,
            'user_name' => 'Past',
            'country' => 'ME',
            'license_plate' => 'AA111',
            'vehicle_type_id' => $slots['vt']->id,
            'email' => 'past@example.com',
            'status' => 'paid',
            'invoice_amount' => '15.00',
            'email_sent' => Reservation::EMAIL_NOT_SENT,
        ]);

        $this->actingAs($admin, 'panel_admin');

        $this->get(route('panel_admin.reservations.edit', ['reservation' => $r], false))
            ->assertForbidden();
    }

    public function test_admin_can_update_reservation_and_dispatch_invoice_job(): void
    {
        Queue::fake();

        $admin = $this->seedAdmin();
        $d1 = Carbon::now()->addDay()->toDateString();
        $d2 = Carbon::now()->addDays(2)->toDateString();
        $slots = $this->seedVehicleAndThreeSlots();
        $this->seedDailyForDate($d1, [$slots['s1'], $slots['s2'], $slots['s3']]);
        $this->seedDailyForDate($d2, [$slots['s1'], $slots['s2'], $slots['s3']]);

        $r = Reservation::query()->create([
            'merchant_transaction_id' => 'mt-update-'.uniqid(),
            'drop_off_time_slot_id' => $slots['s1']->id,
            'pick_up_time_slot_id' => $slots['s2']->id,
            'reservation_date' => $d1,
            'user_name' => 'Move Me',
            'country' => 'ME',
            'license_plate' => 'KO111AA',
            'vehicle_type_id' => $slots['vt']->id,
            'email' => 'move@example.com',
            'status' => 'paid',
            'invoice_amount' => '15.00',
            'email_sent' => Reservation::EMAIL_SENT,
        ]);

        DailyParkingData::query()
            ->whereDate('date', $d1)
            ->whereIn('time_slot_id', [$slots['s1']->id, $slots['s2']->id])
            ->increment('reserved');

        $this->actingAs($admin, 'panel_admin');

        $rq = http_build_query(['merchant_transaction_id' => $r->merchant_transaction_id]);

        $response = $this->put(route('panel_admin.reservations.update', $r, false), [
            'reservation_date' => $d2,
            'drop_off_time_slot_id' => $slots['s1']->id,
            'pick_up_time_slot_id' => $slots['s3']->id,
            'user_name' => 'Move Me',
            'country' => 'ME',
            'license_plate' => 'KO222BB',
            'vehicle_type_id' => $slots['vt']->id,
            'email' => 'move@example.com',
            'return_query' => $rq,
        ]);

        $response->assertRedirect();
        $location = (string) $response->headers->get('Location');
        $this->assertStringContainsString('rezervacije', $location);
        $this->assertStringContainsString('merchant_transaction_id', $location);

        $r->refresh();
        $this->assertSame($d2, $r->reservation_date->toDateString());
        $this->assertSame($slots['s3']->id, $r->pick_up_time_slot_id);
        $this->assertSame('KO222BB', $r->license_plate);
        $this->assertNull($r->invoice_sent_at);
        $this->assertSame(Reservation::EMAIL_NOT_SENT, (int) $r->email_sent);

        $this->assertSame(0, (int) DailyParkingData::query()
            ->whereDate('date', $d1)
            ->where('time_slot_id', $slots['s1']->id)
            ->value('reserved'));
        $this->assertSame(0, (int) DailyParkingData::query()
            ->whereDate('date', $d1)
            ->where('time_slot_id', $slots['s2']->id)
            ->value('reserved'));
        $this->assertSame(1, (int) DailyParkingData::query()
            ->whereDate('date', $d2)
            ->where('time_slot_id', $slots['s1']->id)
            ->value('reserved'));
        $this->assertSame(1, (int) DailyParkingData::query()
            ->whereDate('date', $d2)
            ->where('time_slot_id', $slots['s3']->id)
            ->value('reserved'));

        Queue::assertPushed(SendAdminUpdatedReservationDocumentJob::class);
        Queue::assertNotPushed(SendInvoiceEmailJob::class);
        Queue::assertNotPushed(SendFreeReservationConfirmationJob::class);
    }

    public function test_admin_update_free_reservation_dispatches_confirmation_job(): void
    {
        Queue::fake();

        $admin = $this->seedAdmin();
        $d1 = Carbon::now()->addDay()->toDateString();
        $slots = $this->seedVehicleAndThreeSlots();
        $this->seedDailyForDate($d1, [$slots['s1'], $slots['s2'], $slots['s3']]);

        $r = Reservation::query()->create([
            'merchant_transaction_id' => null,
            'drop_off_time_slot_id' => $slots['s1']->id,
            'pick_up_time_slot_id' => $slots['s2']->id,
            'reservation_date' => $d1,
            'user_name' => 'Free User',
            'country' => 'ME',
            'license_plate' => 'FR111',
            'vehicle_type_id' => $slots['vt']->id,
            'email' => 'free@example.com',
            'status' => 'free',
            'invoice_amount' => '0.00',
            'email_sent' => Reservation::EMAIL_SENT,
            'created_by_admin' => true,
        ]);

        DailyParkingData::query()
            ->whereDate('date', $d1)
            ->whereIn('time_slot_id', [$slots['s1']->id, $slots['s2']->id])
            ->increment('reserved');

        $this->actingAs($admin, 'panel_admin');

        $this->put(route('panel_admin.reservations.update', $r, false), [
            'reservation_date' => $d1,
            'drop_off_time_slot_id' => $slots['s1']->id,
            'pick_up_time_slot_id' => $slots['s2']->id,
            'user_name' => 'Free User Updated',
            'country' => 'ME',
            'license_plate' => 'FR222',
            'vehicle_type_id' => $slots['vt']->id,
            'email' => 'free@example.com',
            'return_query' => '',
        ])->assertRedirect();

        $this->assertSame('Free User Updated', $r->fresh()->user_name);

        Queue::assertPushed(SendAdminUpdatedReservationDocumentJob::class);
        Queue::assertNotPushed(SendInvoiceEmailJob::class);
        Queue::assertNotPushed(SendFreeReservationConfirmationJob::class);
    }

    public function test_admin_search_renders_daily_ticket_without_slot_crash(): void
    {
        $admin = $this->seedAdmin();
        $d = Carbon::now()->addDays(5)->toDateString();
        $vt = VehicleType::query()->create(['price' => 20]);
        $mtid = 'mt-admin-daily-'.uniqid();

        Reservation::query()->create([
            'merchant_transaction_id' => $mtid,
            'reservation_kind' => ReservationKind::DAILY_TICKET,
            'drop_off_time_slot_id' => null,
            'pick_up_time_slot_id' => null,
            'reservation_date' => $d,
            'user_name' => 'Daily Agency',
            'country' => 'ME',
            'license_plate' => 'KO777DD',
            'vehicle_type_id' => $vt->id,
            'email' => 'daily-admin@test.local',
            'status' => 'paid',
            'invoice_amount' => '20.00',
            'email_sent' => Reservation::EMAIL_NOT_SENT,
        ]);

        $this->actingAs($admin, 'panel_admin');

        $this->get(route('panel_admin.reservations', ['merchant_transaction_id' => $mtid], false))
            ->assertOk()
            ->assertSee('Dnevna karta', false)
            ->assertSee('Autoboka i Puč', false)
            ->assertDontSee('Vrijeme dolaska', false);
    }

    public function test_admin_can_update_daily_ticket_safe_fields(): void
    {
        Queue::fake();

        $admin = $this->seedAdmin();
        $d = Carbon::now()->addDays(4)->toDateString();
        $vt = VehicleType::query()->create(['price' => 20]);

        $r = Reservation::query()->create([
            'merchant_transaction_id' => 'mt-admin-daily-edit',
            'reservation_kind' => ReservationKind::DAILY_TICKET,
            'drop_off_time_slot_id' => null,
            'pick_up_time_slot_id' => null,
            'reservation_date' => $d,
            'user_name' => 'Daily Agency',
            'country' => 'ME',
            'license_plate' => 'KO777DD',
            'vehicle_type_id' => $vt->id,
            'email' => 'daily-admin@test.local',
            'status' => 'paid',
            'invoice_amount' => '20.00',
            'email_sent' => Reservation::EMAIL_NOT_SENT,
        ]);

        $this->actingAs($admin, 'panel_admin');

        $this->get(route('panel_admin.reservations.edit', ['reservation' => $r], false))
            ->assertOk()
            ->assertSee('Dnevna karta', false)
            ->assertSee('Sačuvaj', false);

        $this->put(route('panel_admin.reservations.update', $r, false), [
            'reservation_date' => $d,
            'user_name' => 'Daily Updated',
            'country' => 'ME',
            'license_plate' => 'KO888EE',
            'vehicle_type_id' => $vt->id,
            'email' => 'daily-updated@test.local',
            'return_query' => '',
        ])->assertRedirect();

        $r->refresh();
        $this->assertSame('Daily Updated', $r->user_name);
        $this->assertSame('KO888EE', $r->license_plate);
        Queue::assertPushed(SendAdminUpdatedReservationDocumentJob::class);
    }
}
