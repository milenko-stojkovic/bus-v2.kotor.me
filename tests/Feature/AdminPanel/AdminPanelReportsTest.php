<?php

namespace Tests\Feature\AdminPanel;

use App\Models\Admin;
use App\Models\AgencyAdvanceTopup;
use App\Models\AgencyAdvanceTransaction;
use App\Models\DailyParkingData;
use App\Models\ListOfTimeSlot;
use App\Models\Reservation;
use App\Models\User;
use App\Models\VehicleType;
use App\Models\VehicleTypeTranslation;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use App\Services\AdminPanel\Reports\AdminReportsService;
use Tests\TestCase;

class AdminPanelReportsTest extends TestCase
{
    use RefreshDatabase;

    private function seedAdmin(): Admin
    {
        return Admin::query()->create([
            'username' => 'reportsadmin',
            'email' => 'reports-admin@example.com',
            'password' => bcrypt('secret-password-rep'),
            'control_access' => false,
            'admin_access' => true,
        ]);
    }

    public function test_reports_page_renders(): void
    {
        $admin = $this->seedAdmin();
        $this->actingAs($admin, 'panel_admin');

        $this->get(route('panel_admin.reports', [], false))
            ->assertOk()
            ->assertSee('Izvještaji', false);
    }

    public function test_reports_wizard_wires_iso_date_inputs_for_alpine_pdf_enablement(): void
    {
        $admin = $this->seedAdmin();
        $this->actingAs($admin, 'panel_admin');

        $html = $this->get(route('panel_admin.reports', [], false))
            ->assertOk()
            ->getContent();

        $this->assertStringContainsString('placeholder="dd/mm/yyyy"', $html);
        $this->assertStringContainsString('x-model="dailyDate"', $html);
        $this->assertStringContainsString('x-model="dateFrom"', $html);
        $this->assertStringContainsString('x-model="dateTo"', $html);
        $this->assertStringContainsString('@input="dailyDate = $event.target.value"', $html);
        $this->assertStringContainsString('@input="dateFrom = $event.target.value"', $html);
        $this->assertStringContainsString('@input="dateTo = $event.target.value"', $html);
        $this->assertStringContainsString('isoHiddenValue(id)', $html);
        $this->assertStringContainsString('name="date"', $html);
        $this->assertStringContainsString('data-iso-date-hidden', $html);
        $this->assertStringContainsString(':disabled="!canGeneratePdf()"', $html);
    }

    public function test_advance_obligations_option_hidden_and_pdf_blocked_when_feature_flag_off(): void
    {
        config(['features.advance_payments' => false]);

        $admin = $this->seedAdmin();
        $this->actingAs($admin, 'panel_admin');

        $html = $this->get(route('panel_admin.reports', [], false))->assertOk()->getContent();
        $this->assertStringNotContainsString('Obaveze po avansima', $html);

        $this->get(route('panel_admin.reports.pdf', [
            'when' => 'daily',
            'kind' => 'advance_obligations',
            'date' => Carbon::now()->toDateString(),
        ], false))->assertNotFound();
    }

    public function test_advance_obligations_snapshot_computes_from_ledger_only_and_pdf_generates_when_feature_on(): void
    {
        config(['features.advance_payments' => true]);

        $admin = $this->seedAdmin();
        $this->actingAs($admin, 'panel_admin');

        $a = User::factory()->create(['name' => 'Agency A', 'email' => 'a@ex.com']);
        $b = User::factory()->create(['name' => 'Agency B', 'email' => 'b@ex.com']);
        $noLedger = User::factory()->create(['name' => 'Agency NoLedger', 'email' => 'n@ex.com']);

        $snapshotDay = Carbon::parse('2026-04-10')->startOfDay();
        $before = $snapshotDay->copy()->subDay()->setTime(12, 0, 0);
        $after = $snapshotDay->copy()->addDay()->setTime(12, 0, 0);

        // Agency A: before snapshot included
        $tx1 = AgencyAdvanceTransaction::query()->create([
            'agency_user_id' => $a->id,
            'amount' => '100.00',
            'type' => AgencyAdvanceTransaction::TYPE_TOPUP,
        ]);
        $tx1->forceFill(['created_at' => $before, 'updated_at' => $before])->save();

        $tx2 = AgencyAdvanceTransaction::query()->create([
            'agency_user_id' => $a->id,
            'amount' => '-30.00',
            'type' => AgencyAdvanceTransaction::TYPE_USAGE,
        ]);
        $tx2->forceFill(['created_at' => $before, 'updated_at' => $before])->save();

        $tx3 = AgencyAdvanceTransaction::query()->create([
            'agency_user_id' => $a->id,
            'amount' => '-5.00',
            'type' => AgencyAdvanceTransaction::TYPE_CORRECTION,
        ]);
        $tx3->forceFill(['created_at' => $before, 'updated_at' => $before])->save();

        // After snapshot: must be excluded
        $txAfter = AgencyAdvanceTransaction::query()->create([
            'agency_user_id' => $a->id,
            'amount' => '999.00',
            'type' => AgencyAdvanceTransaction::TYPE_TOPUP,
        ]);
        $txAfter->forceFill(['created_at' => $after, 'updated_at' => $after])->save();

        // Agency B: balance 50
        $txB = AgencyAdvanceTransaction::query()->create([
            'agency_user_id' => $b->id,
            'amount' => '50.00',
            'type' => AgencyAdvanceTransaction::TYPE_TOPUP,
        ]);
        $txB->forceFill(['created_at' => $before, 'updated_at' => $before])->save();

        // Pending topup without ledger must not show up
        AgencyAdvanceTopup::query()->create([
            'agency_user_id' => $noLedger->id,
            'merchant_transaction_id' => 'mtid-pending-1',
            'amount' => '777.00',
            'status' => AgencyAdvanceTopup::STATUS_PENDING,
        ]);

        $svc = app(AdminReportsService::class);
        $snap = $svc->advanceObligationsSnapshot($snapshotDay->copy()->endOfDay());
        $this->assertSame('2026-04-10 23:59:59', substr($snap['as_of'], 0, 19));

        $this->assertSame(115.0, (float) $snap['total_obligations_eur']); // A: 65, B: 50
        $names = array_map(fn ($r) => $r['agency'], $snap['rows']);
        $this->assertContains('Agency A', $names);
        $this->assertContains('Agency B', $names);
        $this->assertNotContains('Agency NoLedger', $names);

        // Sorted by balance desc: A(65) before B(50)
        $this->assertSame('Agency A', $snap['rows'][0]['agency']);

        // PDF endpoint should work (daily only)
        $pdf = $this->get(route('panel_admin.reports.pdf', [
            'when' => 'daily',
            'kind' => 'advance_obligations',
            'date' => $snapshotDay->toDateString(),
        ], false));
        $pdf->assertOk();
        $this->assertSame('application/pdf', $pdf->headers->get('Content-Type'));
    }

    public function test_advance_obligations_empty_snapshot_generates_and_total_is_zero(): void
    {
        config(['features.advance_payments' => true]);

        $admin = $this->seedAdmin();
        $this->actingAs($admin, 'panel_admin');

        // With no reservations/advance tx, bounds fallback to today.
        $d = Carbon::now()->startOfDay();
        $svc = app(AdminReportsService::class);
        $snap = $svc->advanceObligationsSnapshot($d->copy()->endOfDay());

        $this->assertSame(0.0, (float) $snap['total_obligations_eur']);
        $this->assertCount(0, $snap['rows']);

        $pdf = $this->get(route('panel_admin.reports.pdf', [
            'when' => 'daily',
            'kind' => 'advance_obligations',
            'date' => $d->toDateString(),
        ], false));
        $pdf->assertOk();
        $this->assertSame('application/pdf', $pdf->headers->get('Content-Type'));
    }

    public function test_reports_pdf_exports_work_for_all_kinds(): void
    {
        $admin = $this->seedAdmin();
        $this->actingAs($admin, 'panel_admin');

        $slotMorning = ListOfTimeSlot::query()->create(['time_slot' => '06:00 - 06:20']);
        $slotDay = ListOfTimeSlot::query()->create(['time_slot' => '10:00 - 10:20']);

        $types = [];
        foreach ([10, 20, 30, 40] as $price) {
            $types[] = VehicleType::query()->create(['price' => $price]);
        }
        foreach ($types as $i => $vt) {
            VehicleTypeTranslation::query()->create([
                'vehicle_type_id' => $vt->id,
                'locale' => 'cg',
                'name' => 'VT'.($i + 1),
                'description' => 'd'.($i + 1),
            ]);
        }

        $created = Carbon::now()->subDays(5)->setTime(12, 0, 0);
        $dCreated = $created->toDateString();

        // Payment report uses created_at date part and paid only.
        $rPay = Reservation::query()->create([
            'merchant_transaction_id' => 'mt-rep-pay-1',
            'drop_off_time_slot_id' => $slotDay->id,
            'pick_up_time_slot_id' => $slotDay->id,
            'reservation_date' => Carbon::now()->addDays(2)->toDateString(),
            'user_name' => 'P',
            'country' => 'ME',
            'license_plate' => 'KO1',
            'vehicle_type_id' => $types[0]->id,
            'email' => 'p@example.com',
            'status' => 'paid',
            'invoice_amount' => '50.00',
            'email_sent' => Reservation::EMAIL_NOT_SENT,
        ]);
        $rPay->forceFill(['created_at' => $created, 'updated_at' => $created])->save();

        // Realization/vehicle-type report uses realized reservations by reservation_date.
        $realizedDate = Carbon::now()->subDays(2)->toDateString(); // always realized (past day)
        DailyParkingData::query()->create([
            'date' => $realizedDate,
            'time_slot_id' => $slotMorning->id,
            'capacity' => 5,
            'reserved' => 0,
            'pending' => 0,
            'is_blocked' => false,
        ]);
        DailyParkingData::query()->create([
            'date' => $realizedDate,
            'time_slot_id' => $slotDay->id,
            'capacity' => 5,
            'reserved' => 0,
            'pending' => 0,
            'is_blocked' => false,
        ]);

        $rReal = Reservation::query()->create([
            'merchant_transaction_id' => 'mt-rep-real-1',
            'drop_off_time_slot_id' => $slotMorning->id,
            'pick_up_time_slot_id' => $slotDay->id,
            'reservation_date' => $realizedDate,
            'user_name' => 'R',
            'country' => 'ME',
            'license_plate' => 'KO2',
            'vehicle_type_id' => $types[1]->id,
            'email' => 'r@example.com',
            'status' => 'paid',
            'invoice_amount' => '80.00',
            'email_sent' => Reservation::EMAIL_NOT_SENT,
        ]);
        $rReal->forceFill(['created_at' => $created, 'updated_at' => $created])->save();

        // Ensure created_at bounds allow selecting realizedDate (bounds are based on created_at).
        Reservation::query()->create([
            'merchant_transaction_id' => 'mt-rep-bounds-max',
            'drop_off_time_slot_id' => $slotDay->id,
            'pick_up_time_slot_id' => $slotDay->id,
            'reservation_date' => Carbon::now()->addDays(3)->toDateString(),
            'user_name' => 'B',
            'country' => 'ME',
            'license_plate' => 'KO9',
            'vehicle_type_id' => $types[2]->id,
            'email' => 'bounds@example.com',
            'status' => 'free',
            'invoice_amount' => '0.00',
            'email_sent' => Reservation::EMAIL_NOT_SENT,
        ]);

        // By payment (daily)
        $pdf1 = $this->get(route('panel_admin.reports.pdf', [
            'when' => 'daily',
            'kind' => 'by_payment',
            'date' => $dCreated,
        ], false));
        $pdf1->assertOk();
        $this->assertSame('application/pdf', $pdf1->headers->get('Content-Type'));

        // By realization (period)
        $pdf2 = $this->get(route('panel_admin.reports.pdf', [
            'when' => 'period',
            'kind' => 'by_realization',
            'date_from' => $realizedDate,
            'date_to' => $realizedDate,
        ], false));
        $pdf2->assertOk();
        $this->assertSame('application/pdf', $pdf2->headers->get('Content-Type'));

        // By vehicle type (period)
        $pdf3 = $this->get(route('panel_admin.reports.pdf', [
            'when' => 'period',
            'kind' => 'by_vehicle_type',
            'date_from' => $realizedDate,
            'date_to' => $realizedDate,
        ], false));
        $pdf3->assertOk();
        $this->assertSame('application/pdf', $pdf3->headers->get('Content-Type'));

        // Yearly should be allowed even if bounds are within the year (overlap semantics).
        $pdf4 = $this->get(route('panel_admin.reports.pdf', [
            'when' => 'yearly',
            'kind' => 'by_payment',
            'year' => (int) Carbon::parse($dCreated)->format('Y'),
        ], false));
        $pdf4->assertOk();
        $this->assertSame('application/pdf', $pdf4->headers->get('Content-Type'));
    }
}

