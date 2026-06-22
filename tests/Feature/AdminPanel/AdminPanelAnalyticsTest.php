<?php

namespace Tests\Feature\AdminPanel;

use App\Models\Admin;
use App\Models\AgencyAdvanceTopup;
use App\Models\AgencyAdvanceTransaction;
use App\Models\DailyParkingData;
use App\Models\LimoPickupEvent;
use App\Models\ListOfTimeSlot;
use App\Models\Reservation;
use App\Models\TempData;
use App\Models\User;
use App\Models\VehicleType;
use App\Models\VehicleTypeTranslation;
use App\Services\AdminPanel\Analytics\AdminAnalyticsService;
use App\Services\Limo\LimoPickupService;
use App\Services\Pdf\AdminAnalyticsPdfGenerator;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class AdminPanelAnalyticsTest extends TestCase
{
    use RefreshDatabase;

    private function seedAdmin(): Admin
    {
        return Admin::query()->create([
            'username' => 'analyticsadmin',
            'email' => 'analytics-admin@example.com',
            'password' => bcrypt('secret-password-an'),
            'control_access' => false,
            'admin_access' => true,
        ]);
    }

    public function test_analytics_page_renders_for_panel_admin(): void
    {
        $admin = $this->seedAdmin();
        $this->actingAs($admin, 'panel_admin');

        $this->get(route('panel_admin.analytics', [], false))
            ->assertOk()
            ->assertSee('Analitika', false);
    }

    public function test_analytics_show_and_pdf_work_for_simple_dataset(): void
    {
        $admin = $this->seedAdmin();
        $this->actingAs($admin, 'panel_admin');

        $slot1 = ListOfTimeSlot::query()->create(['time_slot' => '08:00 - 08:20']);
        $slot2 = ListOfTimeSlot::query()->create(['time_slot' => '09:00 - 09:20']);

        $vt = VehicleType::query()->create(['price' => 50]);
        foreach (['cg', 'en'] as $loc) {
            VehicleTypeTranslation::query()->create([
                'vehicle_type_id' => $vt->id,
                'locale' => $loc,
                'name' => $loc === 'cg' ? 'Veliki autobus' : 'Big bus',
                'description' => $loc === 'cg' ? 'preko 23 sjedišta' : 'over 23 seats',
            ]);
        }

        $d = Carbon::now()->addDay()->toDateString();
        foreach ([$slot1, $slot2] as $s) {
            DailyParkingData::query()->create([
                'date' => $d,
                'time_slot_id' => $s->id,
                'capacity' => 5,
                'reserved' => 0,
                'pending' => 0,
                'is_blocked' => false,
            ]);
        }

        Reservation::query()->create([
            'merchant_transaction_id' => 'mt-analytics-1',
            'drop_off_time_slot_id' => $slot1->id,
            'pick_up_time_slot_id' => $slot2->id,
            'reservation_date' => $d,
            'user_name' => 'X',
            'country' => 'ME',
            'license_plate' => 'KO1',
            'vehicle_type_id' => $vt->id,
            'email' => 'x@example.com',
            'status' => 'paid',
            'invoice_amount' => '50.00',
            'email_sent' => Reservation::EMAIL_NOT_SENT,
        ]);

        TempData::query()->create([
            'merchant_transaction_id' => 'mt-tmp-1',
            'drop_off_time_slot_id' => $slot1->id,
            'pick_up_time_slot_id' => $slot2->id,
            'reservation_date' => $d,
            'user_name' => 'X',
            'country' => 'ME',
            'license_plate' => 'KO1',
            'vehicle_type_id' => $vt->id,
            'email' => 'x@example.com',
            'status' => TempData::STATUS_CANCELED,
        ]);

        $resp = $this->get(route('panel_admin.analytics', [
            'show' => 1,
            'date_from' => $d,
            'date_to' => $d,
            'include_free' => 0,
        ], false));

        $resp->assertOk()
            ->assertSee('Prihod od rezervacija (paid)', false)
            ->assertSee('Ukupan prihod (rezervacije + Limo)', false)
            ->assertSee('50.00 EUR', false)
            ->assertSee('Limo pickup', false);

        $pdf = $this->get(route('panel_admin.analytics.pdf', [
            'date_from' => $d,
            'date_to' => $d,
            'include_free' => 0,
        ], false));

        $pdf->assertOk();
        $this->assertSame('application/pdf', $pdf->headers->get('Content-Type'));
    }

    public function test_slot_usage_index_kpi_uses_new_label_numeric_format_help_text_and_pdf(): void
    {
        $admin = $this->seedAdmin();
        $this->actingAs($admin, 'panel_admin');

        $slot1 = ListOfTimeSlot::query()->create(['time_slot' => '08:00 - 08:20']);
        $slot2 = ListOfTimeSlot::query()->create(['time_slot' => '09:00 - 09:20']);

        $vt = VehicleType::query()->create(['price' => 50]);
        foreach (['cg', 'en'] as $loc) {
            VehicleTypeTranslation::query()->create([
                'vehicle_type_id' => $vt->id,
                'locale' => $loc,
                'name' => 'VT',
                'description' => 'd',
            ]);
        }

        $d = Carbon::now()->addDays(5)->toDateString();

        Reservation::query()->create([
            'merchant_transaction_id' => 'mt-slot-usage-index',
            'drop_off_time_slot_id' => $slot1->id,
            'pick_up_time_slot_id' => $slot2->id,
            'reservation_date' => $d,
            'user_name' => 'X',
            'country' => 'ME',
            'license_plate' => 'KO1',
            'vehicle_type_id' => $vt->id,
            'email' => 'x@example.com',
            'status' => 'paid',
            'invoice_amount' => '50.00',
            'email_sent' => Reservation::EMAIL_NOT_SENT,
        ]);

        $dataset = app(AdminAnalyticsService::class)->build($d, $d, false);
        $this->assertSame(2, $dataset['kpi']['occupied_slots_total']);
        $this->assertEqualsWithDelta(1.0, (float) $dataset['kpi']['avg_occupancy_slot_level'], 0.001);

        $html = $this->get(route('panel_admin.analytics', [
            'show' => 1,
            'date_from' => $d,
            'date_to' => $d,
            'include_free' => 0,
        ], false))->assertOk()->getContent();

        $this->assertStringContainsString('Prosječno zauzeće termina', $html);
        $this->assertStringNotContainsString('Prosječna popunjenost (slot-level)', $html);
        $this->assertStringContainsString('Nije procenat popunjenosti kapaciteta', $html);
        $this->assertStringContainsString('title="Prosječan broj slot-dodjela po (termin × dan)."', $html);

        $this->assertMatchesRegularExpression(
            '/Prosječno zauzeće termina<\/div>\s*<div class="text-lg font-semibold text-gray-900">1\.00<\/div>/i',
            $html,
        );
        $this->assertDoesNotMatchRegularExpression(
            '/Prosječno zauzeće termina<\/div>\s*<div class="text-lg font-semibold text-gray-900">[\d.]+%<\/div>/i',
            $html,
        );

        $pdfHtml = view('pdf.admin-analytics-report', [
            'dataset' => $dataset,
            'logoDataUri' => '',
        ])->render();

        $this->assertStringContainsString('Prosječno zauzeće termina', $pdfHtml);
        $this->assertStringNotContainsString('Popunjenost (slot-level', $pdfHtml);
        $this->assertStringContainsString('Nije procenat popunjenosti kapaciteta', $pdfHtml);
        $this->assertMatchesRegularExpression(
            '/Prosječno zauzeće termina[\s\S]*?1\.00/i',
            $pdfHtml,
        );

        $adminPanelDoc = file_get_contents(base_path('docs/admin-panel.md'));
        $this->assertStringContainsString('Prosječno zauzeće termina', $adminPanelDoc);
        $this->assertStringContainsString('nije** popunjenost kapaciteta', $adminPanelDoc);

        $projectDoneDoc = file_get_contents(base_path('docs/project-done.md'));
        $this->assertStringContainsString('Prosječno zauzeće termina', $projectDoneDoc);
        $this->assertStringContainsString('Prosječna popunjenost (slot-level)', $projectDoneDoc);
    }

    public function test_agency_analysis_section_renders_sorted_and_computes_metrics_and_pdf_contains_it(): void
    {
        $admin = $this->seedAdmin();
        $this->actingAs($admin, 'panel_admin');

        $slotMorning = ListOfTimeSlot::query()->create(['time_slot' => '06:00 - 06:20']);
        $slotDay = ListOfTimeSlot::query()->create(['time_slot' => '10:00 - 10:20']);
        $slotEvening = ListOfTimeSlot::query()->create(['time_slot' => '21:00 - 21:20']);

        $vtA = VehicleType::query()->create(['price' => 50]);
        $vtB = VehicleType::query()->create(['price' => 20]);
        foreach (['cg', 'en'] as $loc) {
            VehicleTypeTranslation::query()->create([
                'vehicle_type_id' => $vtA->id,
                'locale' => $loc,
                'name' => $loc === 'cg' ? 'Tip A' : 'Type A',
                'description' => $loc === 'cg' ? 'opis a' : 'desc a',
            ]);
            VehicleTypeTranslation::query()->create([
                'vehicle_type_id' => $vtB->id,
                'locale' => $loc,
                'name' => $loc === 'cg' ? 'Tip B' : 'Type B',
                'description' => $loc === 'cg' ? 'opis b' : 'desc b',
            ]);
        }

        $agencyHigh = User::query()->create([
            'name' => 'Agencija High',
            'email' => 'high@example.com',
            'password' => bcrypt('secret'),
            'lang' => 'cg',
            'country' => 'ME',
            'email_verified_at' => now(),
        ]);
        $agencyLow = User::query()->create([
            'name' => 'Agencija Low',
            'email' => 'low@example.com',
            'password' => bcrypt('secret'),
            'lang' => 'cg',
            'country' => 'ME',
            'email_verified_at' => now(),
        ]);

        $d = Carbon::now()->addDays(3)->toDateString();

        // High agency: 2 paid (revenue 100), 1 free. Uses vtA twice => top type 2/3.
        Reservation::query()->create([
            'user_id' => $agencyHigh->id,
            'merchant_transaction_id' => 'mt-ag-high-1',
            'drop_off_time_slot_id' => $slotMorning->id,
            'pick_up_time_slot_id' => $slotMorning->id, // 1 slot
            'reservation_date' => $d,
            'user_name' => $agencyHigh->name,
            'country' => 'ME',
            'license_plate' => 'H1',
            'vehicle_type_id' => $vtA->id,
            'email' => 'x@example.com',
            'status' => 'paid',
            'invoice_amount' => '60.00',
            'email_sent' => Reservation::EMAIL_NOT_SENT,
            'created_by_admin' => false,
        ]);
        Reservation::query()->create([
            'user_id' => $agencyHigh->id,
            'merchant_transaction_id' => 'mt-ag-high-2',
            'drop_off_time_slot_id' => $slotDay->id,
            'pick_up_time_slot_id' => $slotEvening->id, // 2 slots
            'reservation_date' => $d,
            'user_name' => $agencyHigh->name,
            'country' => 'ME',
            'license_plate' => 'H2',
            'vehicle_type_id' => $vtA->id,
            'email' => 'x@example.com',
            'status' => 'paid',
            'invoice_amount' => '40.00',
            'email_sent' => Reservation::EMAIL_NOT_SENT,
            'created_by_admin' => false,
        ]);
        Reservation::query()->create([
            'user_id' => $agencyHigh->id,
            'merchant_transaction_id' => 'mt-ag-high-free',
            'drop_off_time_slot_id' => $slotEvening->id,
            'pick_up_time_slot_id' => $slotEvening->id,
            'reservation_date' => $d,
            'user_name' => $agencyHigh->name,
            'country' => 'ME',
            'license_plate' => 'H3',
            'vehicle_type_id' => $vtB->id,
            'email' => 'x@example.com',
            'status' => 'free',
            'invoice_amount' => '0.00',
            'email_sent' => Reservation::EMAIL_NOT_SENT,
            'created_by_admin' => false,
        ]);

        // Low agency: 1 paid (revenue 10), 1 free. Uses vtB => top type 2/2.
        Reservation::query()->create([
            'user_id' => $agencyLow->id,
            'merchant_transaction_id' => 'mt-ag-low-1',
            'drop_off_time_slot_id' => $slotDay->id,
            'pick_up_time_slot_id' => $slotDay->id,
            'reservation_date' => $d,
            'user_name' => $agencyLow->name,
            'country' => 'ME',
            'license_plate' => 'L1',
            'vehicle_type_id' => $vtB->id,
            'email' => 'x@example.com',
            'status' => 'paid',
            'invoice_amount' => '10.00',
            'email_sent' => Reservation::EMAIL_NOT_SENT,
            'created_by_admin' => false,
        ]);
        Reservation::query()->create([
            'user_id' => $agencyLow->id,
            'merchant_transaction_id' => 'mt-ag-low-free',
            'drop_off_time_slot_id' => $slotMorning->id,
            'pick_up_time_slot_id' => $slotEvening->id,
            'reservation_date' => $d,
            'user_name' => $agencyLow->name,
            'country' => 'ME',
            'license_plate' => 'L2',
            'vehicle_type_id' => $vtB->id,
            'email' => 'x@example.com',
            'status' => 'free',
            'invoice_amount' => '0.00',
            'email_sent' => Reservation::EMAIL_NOT_SENT,
            'created_by_admin' => false,
        ]);

        $html = $this->get(route('panel_admin.analytics', [
            'show' => 1,
            'date_from' => $d,
            'date_to' => $d,
            'include_free' => 1,
        ], false))->assertOk()->getContent();

        $this->assertStringContainsString('Analiza po agencijama', $html);

        // Sorted by revenue desc: High should appear before Low.
        $posHigh = strpos($html, 'Agencija High');
        $posLow = strpos($html, 'Agencija Low');
        $this->assertNotFalse($posHigh);
        $this->assertNotFalse($posLow);
        $this->assertTrue($posHigh < $posLow);

        // Revenue counts paid only: High=100, Low=10.
        $this->assertStringContainsString('100.00 EUR', $html);
        $this->assertStringContainsString('10.00 EUR', $html);

        // Free counted in column: High has 1 free, Low has 1 free.
        $this->assertMatchesRegularExpression('/Agencija High[\s\S]*?<td[^>]*>\s*1\s*<\/td>[\s\S]*?<td[^>]*>\s*33\.3%/i', $html);

        // Slot count rule: High occupied = 1 + 2 + 1 = 4.
        $this->assertMatchesRegularExpression('/Agencija High[\s\S]*?<td[^>]*>\s*4\s*<\/td>/i', $html);

        // Top vehicle type for High: vtA appears 2 of 3 => 66.7%.
        $this->assertMatchesRegularExpression('/Agencija High[\s\S]*?Tip A\s*\(opis a\)[\s\S]*?66\.7%/i', $html);

        $pdf = $this->get(route('panel_admin.analytics.pdf', [
            'date_from' => $d,
            'date_to' => $d,
            'include_free' => 1,
        ], false));

        $pdf->assertOk();
        $this->assertSame('application/pdf', $pdf->headers->get('Content-Type'));
    }

    public function test_admin_free_fzbr_by_agency_section_groups_by_user_and_includes_none_and_pdf_contains_it(): void
    {
        $admin = $this->seedAdmin();
        $this->actingAs($admin, 'panel_admin');

        $slotMorning = ListOfTimeSlot::query()->create(['time_slot' => '06:00 - 06:20']);
        $slotDay = ListOfTimeSlot::query()->create(['time_slot' => '10:00 - 10:20']);
        $slotEvening = ListOfTimeSlot::query()->create(['time_slot' => '21:00 - 21:20']);

        $vtA = VehicleType::query()->create(['price' => 50]);
        foreach (['cg', 'en'] as $loc) {
            VehicleTypeTranslation::query()->create([
                'vehicle_type_id' => $vtA->id,
                'locale' => $loc,
                'name' => $loc === 'cg' ? 'Tip A' : 'Type A',
                'description' => $loc === 'cg' ? 'opis a' : 'desc a',
            ]);
        }

        $agency = User::query()->create([
            'name' => 'Agencija X',
            'email' => 'ax@example.com',
            'password' => bcrypt('secret'),
            'lang' => 'cg',
            'country' => 'ME',
            'email_verified_at' => now(),
        ]);

        $d = Carbon::now()->addDays(4)->toDateString();

        // Agency: 2 admin-free reservations.
        Reservation::query()->create([
            'user_id' => $agency->id,
            'merchant_transaction_id' => 'mt-af-1',
            'drop_off_time_slot_id' => $slotMorning->id,
            'pick_up_time_slot_id' => $slotMorning->id, // 1 slot
            'reservation_date' => $d,
            'user_name' => $agency->name,
            'country' => 'ME',
            'license_plate' => 'AF1',
            'vehicle_type_id' => $vtA->id,
            'email' => 'a@example.com',
            'status' => 'free',
            'invoice_amount' => '0.00',
            'email_sent' => Reservation::EMAIL_NOT_SENT,
            'created_by_admin' => true,
        ]);
        Reservation::query()->create([
            'user_id' => $agency->id,
            'merchant_transaction_id' => 'mt-af-2',
            'drop_off_time_slot_id' => $slotDay->id,
            'pick_up_time_slot_id' => $slotEvening->id, // 2 slots
            'reservation_date' => $d,
            'user_name' => $agency->name,
            'country' => 'ME',
            'license_plate' => 'AF2',
            'vehicle_type_id' => $vtA->id,
            'email' => 'a@example.com',
            'status' => 'free',
            'invoice_amount' => '0.00',
            'email_sent' => Reservation::EMAIL_NOT_SENT,
            'created_by_admin' => true,
        ]);

        // None: 1 admin-free reservation without agency.
        Reservation::query()->create([
            'user_id' => null,
            'merchant_transaction_id' => 'mt-af-none',
            'drop_off_time_slot_id' => $slotEvening->id,
            'pick_up_time_slot_id' => $slotEvening->id,
            'reservation_date' => $d,
            'user_name' => 'n/a',
            'country' => 'ME',
            'license_plate' => 'AFN',
            'vehicle_type_id' => $vtA->id,
            'email' => 'a@example.com',
            'status' => 'free',
            'invoice_amount' => '0.00',
            'email_sent' => Reservation::EMAIL_NOT_SENT,
            'created_by_admin' => true,
        ]);

        // Should be excluded: not admin created.
        Reservation::query()->create([
            'user_id' => $agency->id,
            'merchant_transaction_id' => 'mt-af-excluded',
            'drop_off_time_slot_id' => $slotMorning->id,
            'pick_up_time_slot_id' => $slotMorning->id,
            'reservation_date' => $d,
            'user_name' => $agency->name,
            'country' => 'ME',
            'license_plate' => 'AFX',
            'vehicle_type_id' => $vtA->id,
            'email' => 'a@example.com',
            'status' => 'free',
            'invoice_amount' => '0.00',
            'email_sent' => Reservation::EMAIL_NOT_SENT,
            'created_by_admin' => false,
        ]);

        $html = $this->get(route('panel_admin.analytics', [
            'show' => 1,
            'date_from' => $d,
            'date_to' => $d,
            'include_free' => 0,
        ], false))->assertOk()->getContent();

        $this->assertStringContainsString('Besplatne rezervacije po agencijama', $html);

        // Agency appears with 2 reservations and occupied slots = 1 + 2 = 3.
        $this->assertMatchesRegularExpression('/Agencija X[\s\S]*?<td[^>]*>\s*2\s*<\/td>[\s\S]*?<td[^>]*>\s*3\s*<\/td>/i', $html);

        // None bucket present.
        $this->assertStringContainsString('Bez agencije', $html);

        // Sorting by reservation count desc -> Agencija X (2) should appear before Bez agencije (1).
        $posA = strpos($html, 'Agencija X');
        $posNone = strpos($html, 'Bez agencije');
        $this->assertNotFalse($posA);
        $this->assertNotFalse($posNone);
        $this->assertTrue($posA < $posNone);

        $pdf = $this->get(route('panel_admin.analytics.pdf', [
            'date_from' => $d,
            'date_to' => $d,
            'include_free' => 0,
        ], false));
        $pdf->assertOk();
        $this->assertSame('application/pdf', $pdf->headers->get('Content-Type'));
    }

    public function test_analytics_date_from_min_is_oldest_realized_reservation_date_with_fallbacks(): void
    {
        $admin = $this->seedAdmin();
        $this->actingAs($admin, 'panel_admin');

        $slot = ListOfTimeSlot::query()->create(['time_slot' => '09:00 - 09:20']);
        $vt = VehicleType::query()->create(['price' => 10]);

        // Past date => realized by definition (day < today).
        $past = Carbon::now()->subDays(10)->toDateString();
        Reservation::query()->create([
            'merchant_transaction_id' => 'mt-analytics-min-1',
            'drop_off_time_slot_id' => $slot->id,
            'pick_up_time_slot_id' => $slot->id,
            'reservation_date' => $past,
            'user_name' => 'X',
            'country' => 'ME',
            'license_plate' => 'KO1',
            'vehicle_type_id' => $vt->id,
            'email' => 'x@example.com',
            'status' => 'paid',
            'invoice_amount' => '0.00',
            'email_sent' => Reservation::EMAIL_NOT_SENT,
        ]);

        $this->get(route('panel_admin.analytics', [], false))
            ->assertOk()
            ->assertSee('name="date_from"', false)
            ->assertSee('min="'.$past.'"', false);
    }

    public function test_ops_indicator_counts_paid_reservations_fully_in_free_zones(): void
    {
        $admin = $this->seedAdmin();
        $this->actingAs($admin, 'panel_admin');

        $slotMorning = ListOfTimeSlot::query()->create(['time_slot' => '06:00 - 06:20']);
        $slotEvening = ListOfTimeSlot::query()->create(['time_slot' => '21:00 - 21:20']);
        $slotDayA = ListOfTimeSlot::query()->create(['time_slot' => '08:00 - 08:20']);
        $slotDayB = ListOfTimeSlot::query()->create(['time_slot' => '09:00 - 09:20']);

        $vt = VehicleType::query()->create(['price' => 10]);
        foreach (['cg', 'en'] as $loc) {
            VehicleTypeTranslation::query()->create([
                'vehicle_type_id' => $vt->id,
                'locale' => $loc,
                'name' => 'VT',
                'description' => 'd',
            ]);
        }

        $d = Carbon::now()->addDays(2)->toDateString();
        foreach ([$slotMorning, $slotEvening, $slotDayA, $slotDayB] as $s) {
            DailyParkingData::query()->create([
                'date' => $d,
                'time_slot_id' => $s->id,
                'capacity' => 5,
                'reserved' => 0,
                'pending' => 0,
                'is_blocked' => false,
            ]);
        }

        // Should count: paid, both slots in free zones (morning + evening).
        Reservation::query()->create([
            'merchant_transaction_id' => 'mt-analytics-freezone-paid-1',
            'drop_off_time_slot_id' => $slotMorning->id,
            'pick_up_time_slot_id' => $slotEvening->id,
            'reservation_date' => $d,
            'user_name' => 'A',
            'country' => 'ME',
            'license_plate' => 'KO1',
            'vehicle_type_id' => $vt->id,
            'email' => 'a@example.com',
            'status' => 'paid',
            'invoice_amount' => '10.00',
            'email_sent' => Reservation::EMAIL_NOT_SENT,
        ]);

        // Should NOT count: paid, mixed free morning + paid day window.
        Reservation::query()->create([
            'merchant_transaction_id' => 'mt-analytics-freezone-paid-mixed',
            'drop_off_time_slot_id' => $slotMorning->id,
            'pick_up_time_slot_id' => $slotDayA->id,
            'reservation_date' => $d,
            'user_name' => 'B',
            'country' => 'ME',
            'license_plate' => 'KO2',
            'vehicle_type_id' => $vt->id,
            'email' => 'b@example.com',
            'status' => 'paid',
            'invoice_amount' => '20.00',
            'email_sent' => Reservation::EMAIL_NOT_SENT,
        ]);

        // Should NOT count: free, fully in free zones.
        Reservation::query()->create([
            'merchant_transaction_id' => 'mt-analytics-freezone-free-1',
            'drop_off_time_slot_id' => $slotMorning->id,
            'pick_up_time_slot_id' => $slotEvening->id,
            'reservation_date' => $d,
            'user_name' => 'C',
            'country' => 'ME',
            'license_plate' => 'KO3',
            'vehicle_type_id' => $vt->id,
            'email' => 'c@example.com',
            'status' => 'free',
            'invoice_amount' => '0.00',
            'email_sent' => Reservation::EMAIL_NOT_SENT,
        ]);

        $html = $this->get(route('panel_admin.analytics', [
            'show' => 1,
            'date_from' => $d,
            'date_to' => $d,
            'include_free' => 1,
        ], false))->assertOk()->getContent();

        $this->assertStringContainsString('Paid rezervacije u free terminima', $html);
        $this->assertMatchesRegularExpression(
            '/Paid rezervacije u free terminima[\s\S]*?<td[^>]*>\s*1\s*<\/td>/i',
            $html,
        );
    }

    public function test_ops_indicator_counts_double_paid_same_slot_pairs(): void
    {
        $admin = $this->seedAdmin();
        $this->actingAs($admin, 'panel_admin');

        $slot1 = ListOfTimeSlot::query()->create(['time_slot' => '08:00 - 08:20']);
        $slot2 = ListOfTimeSlot::query()->create(['time_slot' => '09:00 - 09:20']);
        $slot3 = ListOfTimeSlot::query()->create(['time_slot' => '10:00 - 10:20']);

        $vt = VehicleType::query()->create(['price' => 10]);
        foreach (['cg', 'en'] as $loc) {
            VehicleTypeTranslation::query()->create([
                'vehicle_type_id' => $vt->id,
                'locale' => $loc,
                'name' => 'VT',
                'description' => 'd',
            ]);
        }

        $d = Carbon::now()->addDays(3)->toDateString();
        foreach ([$slot1, $slot2, $slot3] as $s) {
            DailyParkingData::query()->create([
                'date' => $d,
                'time_slot_id' => $s->id,
                'capacity' => 5,
                'reserved' => 0,
                'pending' => 0,
                'is_blocked' => false,
            ]);
        }

        // Pair that SHOULD count (same date, same plate, same drop-off).
        Reservation::query()->create([
            'merchant_transaction_id' => 'mt-dbl-1',
            'drop_off_time_slot_id' => $slot1->id,
            'pick_up_time_slot_id' => $slot2->id,
            'reservation_date' => $d,
            'user_name' => 'A',
            'country' => 'ME',
            'license_plate' => 'KO123',
            'vehicle_type_id' => $vt->id,
            'email' => 'a@example.com',
            'status' => 'paid',
            'invoice_amount' => '10.00',
            'email_sent' => Reservation::EMAIL_NOT_SENT,
        ]);
        Reservation::query()->create([
            'merchant_transaction_id' => 'mt-dbl-2',
            'drop_off_time_slot_id' => $slot1->id,
            'pick_up_time_slot_id' => $slot3->id,
            'reservation_date' => $d,
            'user_name' => 'B',
            'country' => 'ME',
            'license_plate' => 'KO123',
            'vehicle_type_id' => $vt->id,
            'email' => 'b@example.com',
            'status' => 'paid',
            'invoice_amount' => '10.00',
            'email_sent' => Reservation::EMAIL_NOT_SENT,
        ]);

        // Same date/plate but only cross-match (pick-up of first == drop-off of second) -> should NOT count.
        Reservation::query()->create([
            'merchant_transaction_id' => 'mt-dbl-x1',
            'drop_off_time_slot_id' => $slot2->id,
            'pick_up_time_slot_id' => $slot3->id,
            'reservation_date' => $d,
            'user_name' => 'X1',
            'country' => 'ME',
            'license_plate' => 'KO555',
            'vehicle_type_id' => $vt->id,
            'email' => 'x1@example.com',
            'status' => 'paid',
            'invoice_amount' => '10.00',
            'email_sent' => Reservation::EMAIL_NOT_SENT,
        ]);
        Reservation::query()->create([
            'merchant_transaction_id' => 'mt-dbl-x2',
            'drop_off_time_slot_id' => $slot3->id,
            'pick_up_time_slot_id' => $slot1->id,
            'reservation_date' => $d,
            'user_name' => 'X2',
            'country' => 'ME',
            'license_plate' => 'KO555',
            'vehicle_type_id' => $vt->id,
            'email' => 'x2@example.com',
            'status' => 'paid',
            'invoice_amount' => '10.00',
            'email_sent' => Reservation::EMAIL_NOT_SENT,
        ]);

        // Same date/plate but NO overlap -> should NOT add a pair.
        Reservation::query()->create([
            'merchant_transaction_id' => 'mt-dbl-3',
            'drop_off_time_slot_id' => $slot2->id,
            'pick_up_time_slot_id' => $slot2->id,
            'reservation_date' => $d,
            'user_name' => 'C',
            'country' => 'ME',
            'license_plate' => 'KO999',
            'vehicle_type_id' => $vt->id,
            'email' => 'c@example.com',
            'status' => 'paid',
            'invoice_amount' => '10.00',
            'email_sent' => Reservation::EMAIL_NOT_SENT,
        ]);
        Reservation::query()->create([
            'merchant_transaction_id' => 'mt-dbl-4',
            'drop_off_time_slot_id' => $slot3->id,
            'pick_up_time_slot_id' => $slot3->id,
            'reservation_date' => $d,
            'user_name' => 'D',
            'country' => 'ME',
            'license_plate' => 'KO999',
            'vehicle_type_id' => $vt->id,
            'email' => 'd@example.com',
            'status' => 'paid',
            'invoice_amount' => '10.00',
            'email_sent' => Reservation::EMAIL_NOT_SENT,
        ]);

        // paid + free should NOT count
        Reservation::query()->create([
            'merchant_transaction_id' => 'mt-dbl-5',
            'drop_off_time_slot_id' => $slot1->id,
            'pick_up_time_slot_id' => $slot1->id,
            'reservation_date' => $d,
            'user_name' => 'E',
            'country' => 'ME',
            'license_plate' => 'KO777',
            'vehicle_type_id' => $vt->id,
            'email' => 'e@example.com',
            'status' => 'paid',
            'invoice_amount' => '10.00',
            'email_sent' => Reservation::EMAIL_NOT_SENT,
        ]);
        Reservation::query()->create([
            'merchant_transaction_id' => 'mt-dbl-6',
            'drop_off_time_slot_id' => $slot1->id,
            'pick_up_time_slot_id' => $slot1->id,
            'reservation_date' => $d,
            'user_name' => 'F',
            'country' => 'ME',
            'license_plate' => 'KO777',
            'vehicle_type_id' => $vt->id,
            'email' => 'f@example.com',
            'status' => 'free',
            'invoice_amount' => '0.00',
            'email_sent' => Reservation::EMAIL_NOT_SENT,
        ]);

        // Same plate, different date -> should NOT count
        $d2 = Carbon::now()->addDays(4)->toDateString();
        foreach ([$slot1, $slot2, $slot3] as $s) {
            DailyParkingData::query()->create([
                'date' => $d2,
                'time_slot_id' => $s->id,
                'capacity' => 5,
                'reserved' => 0,
                'pending' => 0,
                'is_blocked' => false,
            ]);
        }
        Reservation::query()->create([
            'merchant_transaction_id' => 'mt-dbl-7',
            'drop_off_time_slot_id' => $slot1->id,
            'pick_up_time_slot_id' => $slot1->id,
            'reservation_date' => $d2,
            'user_name' => 'G',
            'country' => 'ME',
            'license_plate' => 'KO123',
            'vehicle_type_id' => $vt->id,
            'email' => 'g@example.com',
            'status' => 'paid',
            'invoice_amount' => '10.00',
            'email_sent' => Reservation::EMAIL_NOT_SENT,
        ]);

        $html = $this->get(route('panel_admin.analytics', [
            'show' => 1,
            'date_from' => $d,
            'date_to' => $d,
            // include_free should not affect this indicator
            'include_free' => 0,
        ], false))->assertOk()->getContent();

        $this->assertStringContainsString('Duplo plaćanje istog termina', $html);
        $this->assertMatchesRegularExpression(
            '/Duplo plaćanje istog termina[\s\S]*?<td[^>]*>\s*1\s*<\/td>/i',
            $html,
        );
    }

    public function test_advance_balances_section_is_hidden_when_feature_flag_off(): void
    {
        config(['features.advance_payments' => false]);

        $admin = $this->seedAdmin();
        $this->actingAs($admin, 'panel_admin');

        $d = Carbon::now()->addDay()->toDateString();

        $this->get(route('panel_admin.analytics', [
            'show' => 1,
            'date_from' => $d,
            'date_to' => $d,
            'include_free' => 0,
        ], false))
            ->assertOk()
            ->assertDontSee('Stanje avansa po agencijama', false);
    }

    public function test_advance_balances_section_shows_totals_by_agency_and_sorts_by_balance_desc_and_ignores_topups_table(): void
    {
        config(['features.advance_payments' => true]);

        $admin = $this->seedAdmin();
        $this->actingAs($admin, 'panel_admin');

        $a1 = User::factory()->create(['name' => 'Agency A', 'email' => 'a@ex.com']);
        $a2 = User::factory()->create(['name' => 'Agency B', 'email' => 'b@ex.com']);
        $noLedger = User::factory()->create(['name' => 'Agency NoLedger', 'email' => 'n@ex.com']);

        // A1: topup 100, usage -30, correction -5 => balance 65
        AgencyAdvanceTransaction::query()->create([
            'agency_user_id' => $a1->id,
            'amount' => '100.00',
            'type' => AgencyAdvanceTransaction::TYPE_TOPUP,
            'created_at' => now()->subDays(2),
        ]);
        AgencyAdvanceTransaction::query()->create([
            'agency_user_id' => $a1->id,
            'amount' => '-30.00',
            'type' => AgencyAdvanceTransaction::TYPE_USAGE,
            'created_at' => now()->subDays(1),
        ]);
        AgencyAdvanceTransaction::query()->create([
            'agency_user_id' => $a1->id,
            'amount' => '-5.00',
            'type' => AgencyAdvanceTransaction::TYPE_CORRECTION,
            'created_at' => now()->subHours(3),
        ]);

        // A2: topup 50 => balance 50 (should be below A1)
        AgencyAdvanceTransaction::query()->create([
            'agency_user_id' => $a2->id,
            'amount' => '50.00',
            'type' => AgencyAdvanceTransaction::TYPE_TOPUP,
            'created_at' => now()->subHours(1),
        ]);

        // Topup attempt without ledger must NOT affect analytics
        AgencyAdvanceTopup::query()->create([
            'agency_user_id' => $noLedger->id,
            'merchant_transaction_id' => 'mtid-pending',
            'amount' => '999.00',
            'status' => AgencyAdvanceTopup::STATUS_PENDING,
        ]);

        $d = Carbon::now()->addDay()->toDateString();
        $html = $this->get(route('panel_admin.analytics', [
            'show' => 1,
            'date_from' => $d,
            'date_to' => $d,
            'include_free' => 0,
        ], false))->assertOk()->getContent();

        $this->assertStringContainsString('Stanje avansa po agencijama', $html);
        $this->assertStringContainsString('Ukupno stanje avansa:', $html);
        $this->assertStringContainsString('115.00 EUR', $html); // 65 + 50

        // A1 computed columns
        $this->assertStringContainsString('Agency A', $html);
        $this->assertStringContainsString('100.00 EUR', $html); // topup
        $this->assertStringContainsString('30.00 EUR', $html); // usage displayed positive
        $this->assertStringContainsString('-5.00 EUR', $html); // correction signed
        $this->assertStringContainsString('65.00 EUR', $html); // balance

        // A2 row exists, NoLedger does not.
        $this->assertStringContainsString('Agency B', $html);
        $this->assertStringNotContainsString('Agency NoLedger', $html);

        // Sorting: A1 (65) should appear before A2 (50)
        $posA1 = strpos($html, 'Agency A');
        $posA2 = strpos($html, 'Agency B');
        $this->assertIsInt($posA1);
        $this->assertIsInt($posA2);
        $this->assertTrue($posA1 < $posA2);
    }

    public function test_limo_fiscalized_in_period_increases_limo_revenue_and_count(): void
    {
        $admin = $this->seedAdmin();
        $this->actingAs($admin, 'panel_admin');

        $d = '2026-06-15';
        Carbon::setTestNow(Carbon::parse($d.' 14:00:00', 'Europe/Podgorica'));

        $this->seedMinimalLimoPickup(
            occurredAt: Carbon::parse($d.' 11:00:00', 'Europe/Podgorica'),
            amount: '22.50',
            source: 'qr',
            status: 'fiscalized',
        );

        $dataset = app(AdminAnalyticsService::class)->build($d, $d, false);
        $this->assertSame(22.5, $dataset['limo']['revenue_total']);
        $this->assertSame(1, $dataset['limo']['pickup_count']);
        $this->assertSame(1, $dataset['limo']['fiscalized_count']);
        $this->assertSame(22.5, $dataset['kpi']['limo_revenue_total']);
        $this->assertSame(22.5, $dataset['kpi']['revenue_grand_total']);

        $this->get(route('panel_admin.analytics', [
            'show' => 1,
            'date_from' => $d,
            'date_to' => $d,
            'include_free' => 0,
        ], false))->assertOk()->assertSee('Limo pickup', false);

        Carbon::setTestNow();
    }

    public function test_limo_pending_and_fiscal_failed_count_toward_revenue(): void
    {
        $admin = $this->seedAdmin();
        $this->actingAs($admin, 'panel_admin');

        $d = '2026-06-16';
        $this->seedMinimalLimoPickup(
            occurredAt: Carbon::parse($d.' 09:00:00', 'Europe/Podgorica'),
            amount: '10.00',
            source: 'qr',
            status: 'pending_fiscal',
        );
        $this->seedMinimalLimoPickup(
            occurredAt: Carbon::parse($d.' 10:00:00', 'Europe/Podgorica'),
            amount: '5.50',
            source: 'plate',
            status: 'fiscal_failed',
        );

        $dataset = app(AdminAnalyticsService::class)->build($d, $d, false);
        $this->assertSame(15.5, $dataset['limo']['revenue_total']);
        $this->assertSame(2, $dataset['limo']['pickup_count']);
        $this->assertSame(1, $dataset['limo']['qr_count']);
        $this->assertSame(1, $dataset['limo']['plate_count']);
    }

    public function test_limo_incident_status_excluded_from_revenue_and_counts(): void
    {
        $admin = $this->seedAdmin();
        $this->actingAs($admin, 'panel_admin');

        $d = '2026-06-17';
        $this->seedMinimalLimoPickup(
            occurredAt: Carbon::parse($d.' 12:00:00', 'Europe/Podgorica'),
            amount: '99.00',
            source: 'qr',
            status: 'incident',
        );

        $dataset = app(AdminAnalyticsService::class)->build($d, $d, false);
        $this->assertSame(0.0, $dataset['limo']['revenue_total']);
        $this->assertSame(0, $dataset['limo']['pickup_count']);
    }

    public function test_limo_outside_selected_period_excluded(): void
    {
        $admin = $this->seedAdmin();
        $this->actingAs($admin, 'panel_admin');

        $this->seedMinimalLimoPickup(
            occurredAt: Carbon::parse('2026-06-20 12:00:00', 'Europe/Podgorica'),
            amount: '40.00',
            source: 'qr',
            status: 'fiscalized',
        );

        $dataset = app(AdminAnalyticsService::class)->build('2026-06-21', '2026-06-22', false);
        $this->assertSame(0.0, $dataset['limo']['revenue_total']);
    }

    public function test_limo_does_not_affect_reservation_slot_or_vehicle_type_counts(): void
    {
        $admin = $this->seedAdmin();
        $this->actingAs($admin, 'panel_admin');

        $slot = ListOfTimeSlot::query()->create(['time_slot' => '08:00 - 08:20']);
        $vt = VehicleType::query()->create(['price' => 50]);
        foreach (['cg', 'en'] as $loc) {
            VehicleTypeTranslation::query()->create([
                'vehicle_type_id' => $vt->id,
                'locale' => $loc,
                'name' => 'VT',
                'description' => 'd',
            ]);
        }

        $d = '2026-06-18';
        Reservation::query()->create([
            'merchant_transaction_id' => 'mt-limo-isolation',
            'drop_off_time_slot_id' => $slot->id,
            'pick_up_time_slot_id' => $slot->id,
            'reservation_date' => $d,
            'user_name' => 'Iso',
            'country' => 'ME',
            'license_plate' => 'ISO1',
            'vehicle_type_id' => $vt->id,
            'email' => 'iso@example.com',
            'status' => 'paid',
            'invoice_amount' => '50.00',
            'email_sent' => Reservation::EMAIL_NOT_SENT,
        ]);

        $this->seedMinimalLimoPickup(
            occurredAt: Carbon::parse($d.' 13:00:00', 'Europe/Podgorica'),
            amount: '15.00',
            source: 'plate',
            status: 'fiscalized',
        );

        $dataset = app(AdminAnalyticsService::class)->build($d, $d, false);
        $this->assertSame(1, $dataset['kpi']['reservations_total']);
        $this->assertSame(1, $dataset['kpi']['occupied_slots_total']);
        $this->assertSame(50.0, $dataset['kpi']['revenue_reservations']);
        $this->assertSame(15.0, $dataset['limo']['revenue_total']);
        $this->assertSame(65.0, $dataset['kpi']['revenue_grand_total']);
        $this->assertSame(1, $dataset['by_vehicle_type'][0]['reservations'] ?? 0);
    }

    public function test_analytics_pdf_template_includes_limo_block_when_dataset_has_limo(): void
    {
        $dataset = app(AdminAnalyticsService::class)->build(
            Carbon::today('Europe/Podgorica')->toDateString(),
            Carbon::today('Europe/Podgorica')->toDateString(),
            false,
        );

        $this->assertArrayHasKey('limo', $dataset);

        $html = view('pdf.admin-analytics-report', [
            'dataset' => $dataset,
            'logoDataUri' => '',
        ])->render();

        $this->assertStringContainsString('Limo pickup', $html);
        $this->assertStringContainsString('Pickup preko tablice', $html);

        $binary = app(AdminAnalyticsPdfGenerator::class)->renderBinary($dataset);
        $this->assertNotSame('', $binary);
        $this->assertStringStartsWith('%PDF', $binary);
    }

    /**
     * Minimal Limo pickup row for analytics (same shape as production seeds).
     */
    private function seedMinimalLimoPickup(
        Carbon $occurredAt,
        string $amount,
        string $source,
        string $status,
    ): LimoPickupEvent {
        $recorder = Admin::query()->create([
            'username' => 'limo_an_'.Str::random(5),
            'email' => 'limo-an-'.Str::random(5).'@test.local',
            'password' => bcrypt('secret'),
            'control_access' => false,
            'admin_access' => true,
            'limo_access' => true,
        ]);

        $user = User::factory()->create([
            'name' => 'Agency Analytics',
            'email' => 'agency-an-'.Str::random(4).'@test.local',
            'country' => 'ME',
        ]);

        return LimoPickupEvent::query()->create([
            'merchant_transaction_id' => (string) Str::uuid(),
            'agency_user_id' => $user->id,
            'agency_name_snapshot' => 'Agency Analytics',
            'agency_email_snapshot' => $user->email,
            'agency_country_snapshot' => 'ME',
            'source' => $source,
            'qr_token_hash' => null,
            'qr_valid_on' => Carbon::today('Europe/Podgorica'),
            'license_plate_snapshot' => 'KO999XX',
            'amount_snapshot' => $amount,
            'service_name_snapshot' => LimoPickupService::SERVICE_NAME,
            'occurred_at' => $occurredAt,
            'recorded_by_limo_admin_id' => $recorder->id,
            'status' => $status,
        ]);
    }
}

