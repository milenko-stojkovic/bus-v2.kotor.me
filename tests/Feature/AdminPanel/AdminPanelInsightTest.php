<?php

namespace Tests\Feature\AdminPanel;

use App\Models\Admin;
use App\Models\ListOfTimeSlot;
use App\Models\Reservation;
use App\Models\TempData;
use App\Models\VehicleType;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AdminPanelInsightTest extends TestCase
{
    use RefreshDatabase;

    private function seedAdmin(): Admin
    {
        return Admin::query()->create([
            'username' => 'insightadmin',
            'email' => 'insight-admin@example.com',
            'password' => bcrypt('secret-password-ins'),
            'control_access' => false,
            'admin_access' => true,
        ]);
    }

    public function test_insight_page_renders(): void
    {
        $admin = $this->seedAdmin();
        $this->actingAs($admin, 'panel_admin');

        $this->get(route('panel_admin.insight', [], false))
            ->assertOk()
            ->assertSee('Uvid', false);
    }

    public function test_insight_search_by_mtid_lists_temp_data_and_links_to_detail(): void
    {
        $admin = $this->seedAdmin();
        $this->actingAs($admin, 'panel_admin');

        $slot = ListOfTimeSlot::query()->create(['time_slot' => '08:00 - 08:20']);
        $vt = VehicleType::query()->create(['price' => 10]);

        TempData::query()->create([
            'merchant_transaction_id' => 'mt-ins-1',
            'retry_token' => 'rt1',
            'drop_off_time_slot_id' => $slot->id,
            'pick_up_time_slot_id' => $slot->id,
            'reservation_date' => Carbon::now()->addDay()->toDateString(),
            'user_name' => 'Ime Prezime',
            'country' => 'ME',
            'license_plate' => 'KO1',
            'vehicle_type_id' => $vt->id,
            'email' => 'x@example.com',
            'status' => TempData::STATUS_PENDING,
            'resolution_reason' => 'test',
        ]);

        $resp = $this->get(route('panel_admin.insight', [
            'search' => 1,
            'merchant_transaction_id' => 'mt-ins-1',
        ], false));

        $resp->assertOk()
            ->assertSee('mt-ins-1', false)
            ->assertSee('Detalji', false);
    }

    public function test_insight_detail_back_link_keeps_search_query(): void
    {
        $admin = $this->seedAdmin();
        $this->actingAs($admin, 'panel_admin');

        $slot = ListOfTimeSlot::query()->create(['time_slot' => '08:00 - 08:20']);
        $vt = VehicleType::query()->create(['price' => 10]);

        TempData::query()->create([
            'merchant_transaction_id' => 'mt-ins-back-1',
            'retry_token' => 'rtb',
            'drop_off_time_slot_id' => $slot->id,
            'pick_up_time_slot_id' => $slot->id,
            'reservation_date' => Carbon::now()->addDay()->toDateString(),
            'user_name' => 'X',
            'country' => 'ME',
            'license_plate' => 'KO1',
            'vehicle_type_id' => $vt->id,
            'email' => 'x@example.com',
            'status' => TempData::STATUS_PENDING,
        ]);

        $rq = http_build_query(['search' => 1, 'merchant_transaction_id' => 'mt-ins-back-1']);
        $this->get(route('panel_admin.insight.show', [
            'merchantTransactionId' => 'mt-ins-back-1',
            'rq' => $rq,
        ], false))
            ->assertOk()
            ->assertSee('href="'.route('panel_admin.insight', [], false).'?'.e($rq).'"', false);
    }

    public function test_insight_detail_shows_no_logs_message_when_unavailable(): void
    {
        $admin = $this->seedAdmin();
        $this->actingAs($admin, 'panel_admin');

        $slot = ListOfTimeSlot::query()->create(['time_slot' => '08:00 - 08:20']);
        $vt = VehicleType::query()->create(['price' => 10]);

        TempData::query()->create([
            'merchant_transaction_id' => 'mt-ins-2',
            'retry_token' => 'rt2',
            'drop_off_time_slot_id' => $slot->id,
            'pick_up_time_slot_id' => $slot->id,
            'reservation_date' => Carbon::now()->addDay()->toDateString(),
            'user_name' => 'X',
            'country' => 'ME',
            'license_plate' => 'KO2',
            'vehicle_type_id' => $vt->id,
            'email' => 'y@example.com',
            // SQLite test schema may not include all production statuses.
            'status' => TempData::STATUS_LATE_SUCCESS,
        ]);

        $this->get(route('panel_admin.insight.show', ['merchantTransactionId' => 'mt-ins-2'], false))
            ->assertOk()
            ->assertSee('Detaljni payment logovi nisu dostupni u retention periodu.', false);
    }

    public function test_insight_admin_free_mtid_fallback_shows_notice(): void
    {
        $admin = $this->seedAdmin();
        $this->actingAs($admin, 'panel_admin');

        $slot = ListOfTimeSlot::query()->create(['time_slot' => '08:00 - 08:20']);
        $vt = VehicleType::query()->create(['price' => 10]);

        Reservation::query()->create([
            'merchant_transaction_id' => 'mt-admin-free-1',
            'drop_off_time_slot_id' => $slot->id,
            'pick_up_time_slot_id' => $slot->id,
            'reservation_date' => Carbon::now()->addDay()->toDateString(),
            'user_name' => 'Admin Free',
            'country' => 'ME',
            'license_plate' => 'KO9',
            'vehicle_type_id' => $vt->id,
            'email' => 'af@example.com',
            'status' => 'free',
            'invoice_amount' => '0.00',
            'email_sent' => Reservation::EMAIL_NOT_SENT,
            'created_by_admin' => true,
        ]);

        $this->get(route('panel_admin.insight', [
            'search' => 1,
            'merchant_transaction_id' => 'mt-admin-free-1',
        ], false))
            ->assertOk()
            ->assertSee('Admin-free rezervacija', false);
    }

    public function test_insight_timeline_retention_uses_logging_config_days(): void
    {
        $admin = $this->seedAdmin();
        $this->actingAs($admin, 'panel_admin');

        config()->set('logging.channels.payments.days', 1);

        $slot = ListOfTimeSlot::query()->create(['time_slot' => '08:00 - 08:20']);
        $vt = VehicleType::query()->create(['price' => 10]);

        TempData::query()->create([
            'merchant_transaction_id' => 'mt-ins-ret',
            'retry_token' => 'rt-ret',
            'drop_off_time_slot_id' => $slot->id,
            'pick_up_time_slot_id' => $slot->id,
            'reservation_date' => Carbon::now()->addDay()->toDateString(),
            'user_name' => 'R',
            'country' => 'ME',
            'license_plate' => 'KO7',
            'vehicle_type_id' => $vt->id,
            'email' => 'ret@example.com',
            'status' => TempData::STATUS_PENDING,
        ]);

        $logDir = storage_path('logs');
        @mkdir($logDir, 0777, true);

        $y = Carbon::now()->subDay()->format('Y-m-d');
        $yPath = $logDir.DIRECTORY_SEPARATOR.'payments-'.$y.'.log';
        file_put_contents($yPath, '[2026-04-21 10:00:00] local.INFO: callback received {"merchant_transaction_id":"mt-ins-ret"}'."\n");

        $resp = $this->get(route('panel_admin.insight.show', ['merchantTransactionId' => 'mt-ins-ret'], false));
        $resp->assertOk()
            ->assertSee('Detaljni payment logovi nisu dostupni u retention periodu.', false);
    }

    public function test_insight_detail_timeline_renders_events_in_chronological_order(): void
    {
        Carbon::setTestNow('2026-06-30 12:00:00');

        $admin = $this->seedAdmin();
        $this->actingAs($admin, 'panel_admin');

        $slot = ListOfTimeSlot::query()->create(['time_slot' => '08:00 - 08:20']);
        $vt = VehicleType::query()->create(['price' => 10]);

        TempData::query()->create([
            'merchant_transaction_id' => 'mt-ins-order',
            'retry_token' => 'rt-order',
            'drop_off_time_slot_id' => $slot->id,
            'pick_up_time_slot_id' => $slot->id,
            'reservation_date' => Carbon::now()->addDay()->toDateString(),
            'user_name' => 'R',
            'country' => 'ME',
            'license_plate' => 'KO8',
            'vehicle_type_id' => $vt->id,
            'email' => 'order@example.com',
            'status' => TempData::STATUS_PENDING,
        ]);

        $logDir = storage_path('logs');
        @mkdir($logDir, 0777, true);
        file_put_contents(
            $logDir.DIRECTORY_SEPARATOR.'payments-2026-06-30.log',
            '[2026-06-30 05:32:00] local.INFO: inquiry {"merchant_transaction_id":"mt-ins-order"}'."\n"
            .'[2026-06-30 00:01:00] local.INFO: callback {"merchant_transaction_id":"mt-ins-order"}'."\n"
        );
        file_put_contents(
            $logDir.DIRECTORY_SEPARATOR.'payments-2026-06-29.log',
            '[2026-06-29 22:35:00] local.INFO: createSession {"merchant_transaction_id":"mt-ins-order"}'."\n"
        );

        $html = $this->get(route('panel_admin.insight.show', ['merchantTransactionId' => 'mt-ins-order'], false))
            ->assertOk()
            ->getContent();

        $pos2235 = strpos($html, '2026-06-29 22:35:00');
        $pos0001 = strpos($html, '2026-06-30 00:01:00');
        $pos0532 = strpos($html, '2026-06-30 05:32:00');

        $this->assertNotFalse($pos2235);
        $this->assertNotFalse($pos0001);
        $this->assertNotFalse($pos0532);
        $this->assertLessThan($pos0001, $pos2235);
        $this->assertLessThan($pos0532, $pos0001);

        Carbon::setTestNow();
    }
}

