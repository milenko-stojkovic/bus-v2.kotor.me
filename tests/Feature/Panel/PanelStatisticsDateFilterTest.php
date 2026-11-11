<?php

namespace Tests\Feature\Panel;

use App\Models\AgencyAdvanceTopup;
use App\Models\AgencyAdvanceTransaction;
use App\Models\ListOfTimeSlot;
use App\Models\Reservation;
use App\Models\User;
use App\Models\VehicleType;
use App\Models\VehicleTypeTranslation;
use App\Services\Pdf\AgencyFinancialCardPdfGenerator;
use App\Services\Pdf\PanelStatisticsPdfGenerator;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class PanelStatisticsDateFilterTest extends TestCase
{
    use RefreshDatabase;

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    public function test_statistics_filters_by_closed_interval_and_only_current_user(): void
    {
        $slot = ListOfTimeSlot::query()->create(['time_slot' => '10:00 - 10:20']);
        $vt = VehicleType::query()->create(['price' => 10]);
        VehicleTypeTranslation::query()->create([
            'vehicle_type_id' => $vt->id,
            'locale' => 'cg',
            'name' => 'VT1',
            'description' => 'd1',
        ]);

        $u1 = User::query()->create([
            'name' => 'A',
            'email' => 'a@example.com',
            'password' => bcrypt('secret'),
            'country' => 'ME',
            'lang' => 'cg',
            'email_verified_at' => now(),
        ]);
        $u2 = User::query()->create([
            'name' => 'B',
            'email' => 'b@example.com',
            'password' => bcrypt('secret'),
            'country' => 'ME',
            'lang' => 'cg',
            'email_verified_at' => now(),
        ]);

        // Realized reservations (past days).
        Reservation::query()->create([
            'user_id' => $u1->id,
            'merchant_transaction_id' => 'mt-stat-1',
            'drop_off_time_slot_id' => $slot->id,
            'pick_up_time_slot_id' => $slot->id,
            'reservation_date' => '2026-04-10',
            'user_name' => 'A',
            'country' => 'ME',
            'license_plate' => 'KO1',
            'vehicle_type_id' => $vt->id,
            'email' => 'a@example.com',
            'status' => 'paid',
            'invoice_amount' => '10.00',
            'email_sent' => Reservation::EMAIL_NOT_SENT,
        ]);
        Reservation::query()->create([
            'user_id' => $u1->id,
            'merchant_transaction_id' => 'mt-stat-2',
            'drop_off_time_slot_id' => $slot->id,
            'pick_up_time_slot_id' => $slot->id,
            'reservation_date' => '2026-04-15',
            'user_name' => 'A',
            'country' => 'ME',
            'license_plate' => 'KO1',
            'vehicle_type_id' => $vt->id,
            'email' => 'a@example.com',
            'status' => 'paid',
            'invoice_amount' => '20.00',
            'email_sent' => Reservation::EMAIL_NOT_SENT,
        ]);
        Reservation::query()->create([
            'user_id' => $u2->id,
            'merchant_transaction_id' => 'mt-stat-other',
            'drop_off_time_slot_id' => $slot->id,
            'pick_up_time_slot_id' => $slot->id,
            'reservation_date' => '2026-04-15',
            'user_name' => 'B',
            'country' => 'ME',
            'license_plate' => 'KO9',
            'vehicle_type_id' => $vt->id,
            'email' => 'b@example.com',
            'status' => 'paid',
            'invoice_amount' => '999.00',
            'email_sent' => Reservation::EMAIL_NOT_SENT,
        ]);

        Carbon::setTestNow(Carbon::parse('2026-04-20 12:00:00', 'Europe/Podgorica'));

        $this->actingAs($u1);

        $resp = $this->get(route('panel.statistics', [
            'date_from' => '2026-04-15',
            'date_to' => '2026-04-15',
        ], false));
        $resp->assertOk();

        // Only the 2026-04-15 reservation for u1 should count.
        $resp->assertSee('20.00');
        $resp->assertSee('1'); // visit_count
        $resp->assertDontSee('999.00');
    }

    public function test_statistics_page_shows_english_filter_labels_when_locale_is_en(): void
    {
        $u = User::factory()->create([
            'email_verified_at' => now(),
            'lang' => 'en',
        ]);

        $this->actingAs($u)
            ->withSession(['locale' => 'en']);

        $resp = $this->get(route('panel.statistics', [], false))->assertOk();
        $html = $resp->getContent();

        $this->assertMatchesRegularExpression(
            '/<label[^>]*for="date_from_display"[^>]*>\s*From\s*<\/label>/',
            $html,
        );
        $this->assertMatchesRegularExpression(
            '/<label[^>]*for="date_to_display"[^>]*>\s*To\s*<\/label>/',
            $html,
        );
        $this->assertMatchesRegularExpression(
            '/<button[^>]*type="submit"[^>]*>\s*Apply\s*<\/button>/',
            $html,
        );
        $this->assertDoesNotMatchRegularExpression(
            '/<label[^>]*for="date_from_display"[^>]*>\s*Od\s*<\/label>/',
            $html,
        );
        $this->assertDoesNotMatchRegularExpression(
            '/<label[^>]*for="date_to_display"[^>]*>\s*Do\s*<\/label>/',
            $html,
        );
        $this->assertDoesNotMatchRegularExpression(
            '/<button[^>]*type="submit"[^>]*>\s*Primijeni\s*<\/button>/',
            $html,
        );
    }

    public function test_statistics_page_uses_hybrid_iso_date_inputs_for_from_and_to(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-05-01 12:00:00', 'Europe/Podgorica'));

        $u = User::query()->create([
            'name' => 'A',
            'email' => 'a@example.com',
            'password' => bcrypt('secret'),
            'country' => 'ME',
            'lang' => 'cg',
            'email_verified_at' => now(),
        ]);

        $this->actingAs($u);

        $html = $this->get(route('panel.statistics', [
            'date_from' => '2026-05-16',
            'date_to' => '2026-05-20',
        ], false))->assertOk()->getContent();

        $this->assertStringContainsString('placeholder="dd/mm/yyyy"', $html);
        $this->assertStringContainsString('data-iso-date-display', $html);
        $this->assertStringContainsString('data-iso-date-hidden', $html);
        $this->assertStringContainsString('data-iso-date-picker', $html);
        $this->assertStringContainsString('data-iso-date-calendar-btn', $html);
        $this->assertStringContainsString('name="date_from"', $html);
        $this->assertStringContainsString('value="2026-05-16"', $html);
        $this->assertStringContainsString('name="date_to"', $html);
        $this->assertStringContainsString('value="2026-05-20"', $html);
        $this->assertStringContainsString('16/05/2026', $html);
        $this->assertSame(2, substr_count($html, 'data-iso-date-picker'));
    }

    public function test_validation_rejects_from_after_to(): void
    {
        $u = User::query()->create([
            'name' => 'A',
            'email' => 'a@example.com',
            'password' => bcrypt('secret'),
            'country' => 'ME',
            'lang' => 'cg',
            'email_verified_at' => now(),
        ]);

        $this->actingAs($u);

        $resp = $this->get(route('panel.statistics', [
            'date_from' => '2026-05-10',
            'date_to' => '2026-05-01',
        ], false));
        $resp->assertSessionHasErrors(['date_to']);
    }

    public function test_calendar_min_includes_attributed_historical_dates_for_the_authenticated_agency_only(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-11-11 12:00:00', 'Europe/Podgorica'));
        [$slot, $type] = $this->slotAndType();

        $agency = $this->agency('historical@example.com');
        $other = $this->agency('other-historical@example.com');
        $v2Only = $this->agency('v2-only@example.com');

        $this->reservation($agency, $slot, $type, [
            'reservation_date' => '2026-07-07',
            'created_at' => '2025-07-28 17:13:47',
            'license_plate' => 'HIST1',
            'invoice_amount' => '12.00',
        ]);
        $this->reservation($other, $slot, $type, [
            'reservation_date' => '2025-01-15',
            'created_at' => '2025-01-15 08:00:00',
            'license_plate' => 'OTHER1',
        ]);
        $this->reservation($v2Only, $slot, $type, [
            'reservation_date' => '2026-08-20',
            'created_at' => '2026-08-20 09:00:00',
            'license_plate' => 'V2ONLY',
        ]);

        $this->actingAs($agency);
        $html = $this->get(route('panel.statistics', [], false))->assertOk()->getContent();

        $this->assertStringContainsString('min="2025-01-15"', $html);
        $this->assertStringContainsString('data-min="2025-01-15"', $html);
        $this->assertStringContainsString('max="2027-02-09"', $html);
        $this->assertStringContainsString('value="2025-01-15"', $html);
        $this->assertStringNotContainsString('OTHER1', $html);
        $this->assertStringNotContainsString('min="2026-07-07"', $html);
        $this->assertStringNotContainsString('min="2025-07-28"', $html);

        $applied = $this->get(route('panel.statistics', [
            'date_from' => '2025-07-28',
            'date_to' => '2025-07-28',
        ], false))->assertOk()->getContent();
        $this->assertStringContainsString('value="2025-07-28"', $applied);
        $this->assertStringNotContainsString('OTHER1', $applied);

        $clamped = $this->get(route('panel.statistics', [
            'date_from' => '2020-01-01',
            'date_to' => '2025-07-28',
        ], false))->assertOk()->getContent();
        $this->assertStringContainsString('value="2025-01-15"', $clamped);
        $this->assertStringNotContainsString('value="2020-01-01"', $clamped);

        $captured = null;
        $this->app->bind(PanelStatisticsPdfGenerator::class, function () use (&$captured) {
            return new class($captured) extends PanelStatisticsPdfGenerator
            {
                public function __construct(private mixed &$captured) {}

                public function renderBinary(array $dataset, string $locale = 'cg'): string
                {
                    $this->captured = $dataset;

                    return '%PDF-stub';
                }
            };
        });

        $v2Html = $this->actingAs($v2Only)
            ->get(route('panel.statistics', [
                'date_from' => '2025-01-15',
                'date_to' => '2025-01-15',
            ], false))
            ->assertOk()
            ->getContent();
        $this->assertStringContainsString('min="2025-01-15"', $v2Html);
        $this->assertStringContainsString('data-min="2025-01-15"', $v2Html);
        $this->assertStringContainsString('value="2025-01-15"', $v2Html);
        $this->assertStringNotContainsString('OTHER1', $v2Html);
        $this->assertStringNotContainsString('HIST1', $v2Html);
        $this->assertStringNotContainsString('min="2026-08-20"', $v2Html);

        $this->get(route('panel.statistics.pdf', [
            'date_from' => '2025-01-15',
            'date_to' => '2025-01-15',
        ], false))->assertOk();
        $this->assertIsArray($captured);
        $this->assertSame('2025-01-15', $captured['date_from']);
        $this->assertSame('2025-01-15', $captured['date_to']);
        $this->assertSame(0, $captured['visit_count']);
    }

    public function test_calendar_min_uses_a_financial_event_before_the_earliest_service_date(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-11-11 12:00:00', 'Europe/Podgorica'));
        [$slot, $type] = $this->slotAndType();
        $agency = $this->agency('topup-before-service@example.com');

        $this->reservation($agency, $slot, $type, [
            'reservation_date' => '2026-09-01',
            'created_at' => '2026-09-01 10:00:00',
        ]);
        $foreign = $this->agency('foreign-v1@example.com');
        $this->reservation($foreign, $slot, $type, [
            'reservation_date' => '2025-05-20',
            'created_at' => '2025-05-01 08:00:00',
            'license_plate' => 'FOREIGN1',
            'invoice_amount' => '77.00',
        ]);
        $topup = AgencyAdvanceTopup::query()->create([
            'agency_user_id' => $agency->id,
            'merchant_transaction_id' => 'mt-early-topup',
            'amount' => '40.00',
            'status' => AgencyAdvanceTopup::STATUS_PAID,
            'paid_at' => '2026-09-01 11:00:00',
        ]);
        DB::table('agency_advance_topups')->where('id', $topup->id)->update([
            'paid_at' => '2025-03-04 11:00:00',
        ]);
        $correction = AgencyAdvanceTransaction::query()->create([
            'agency_user_id' => $agency->id,
            'amount' => '-1.00',
            'type' => AgencyAdvanceTransaction::TYPE_CORRECTION,
            'note' => 'Early correction',
        ]);
        DB::table('agency_advance_transactions')->where('id', $correction->id)->update([
            'created_at' => '2025-06-02 08:00:00',
            'updated_at' => '2025-06-02 08:00:00',
        ]);

        $html = $this->actingAs($agency)
            ->get(route('panel.statistics', ['report' => 'financial_card'], false))
            ->assertOk()
            ->getContent();

        $this->assertStringContainsString('min="2025-03-04"', $html);
        $this->assertStringContainsString('data-min="2025-03-04"', $html);
        $this->assertStringNotContainsString('min="2025-05-01"', $html);
        $this->assertStringNotContainsString('FOREIGN1', $html);
        $this->assertStringNotContainsString('77.00', $html);
        $this->assertStringNotContainsString('min="2026-09-01"', $html);
    }

    public function test_historical_min_is_the_same_for_html_and_pdf(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-11-11 12:00:00', 'Europe/Podgorica'));
        [$slot, $type] = $this->slotAndType();
        $agency = $this->agency('pdf-min@example.com');
        $this->reservation($agency, $slot, $type, [
            'reservation_date' => '2025-08-10',
            'created_at' => '2025-08-01 09:15:00',
            'invoice_amount' => '15.00',
            'merchant_transaction_id' => 'mt-pdf-min',
        ]);

        $captured = null;
        $this->app->bind(AgencyFinancialCardPdfGenerator::class, function () use (&$captured) {
            return new class($captured) extends AgencyFinancialCardPdfGenerator
            {
                public function __construct(private mixed &$captured) {}

                public function renderBinary(array $dataset, string $locale = 'cg'): string
                {
                    $this->captured = $dataset;

                    return '%PDF-stub';
                }
            };
        });

        $this->actingAs($agency);
        $this->get(route('panel.statistics.pdf', [
            'report' => 'financial_card',
            'date_from' => '2025-08-01',
            'date_to' => '2025-08-01',
        ], false))->assertOk();

        $this->assertIsArray($captured);
        $this->assertSame('2025-08-01', $captured['date_from']);
        $this->assertSame('2025-08-01', $captured['date_to']);
    }

    /**
     * @return array{0: ListOfTimeSlot, 1: VehicleType}
     */
    private function slotAndType(): array
    {
        $slot = ListOfTimeSlot::query()->create(['time_slot' => '10:00 - 10:20']);
        $type = VehicleType::query()->create(['price' => 10]);
        VehicleTypeTranslation::query()->create([
            'vehicle_type_id' => $type->id,
            'locale' => 'cg',
            'name' => 'VT1',
            'description' => 'd1',
        ]);

        return [$slot, $type];
    }

    private function agency(string $email): User
    {
        return User::query()->create([
            'name' => 'Agency',
            'email' => $email,
            'password' => bcrypt('secret'),
            'country' => 'ME',
            'lang' => 'cg',
            'email_verified_at' => now(),
        ]);
    }

    /**
     * @param  array<string, mixed>  $overrides
     */
    private function reservation(User $agency, ListOfTimeSlot $slot, VehicleType $type, array $overrides): void
    {
        $createdAt = $overrides['created_at'] ?? '2026-08-01 10:00:00';
        unset($overrides['created_at']);

        $reservation = Reservation::query()->create(array_merge([
            'user_id' => $agency->id,
            'merchant_transaction_id' => 'mt-'.uniqid(),
            'drop_off_time_slot_id' => $slot->id,
            'pick_up_time_slot_id' => $slot->id,
            'reservation_date' => '2026-08-01',
            'user_name' => $agency->name,
            'country' => 'ME',
            'license_plate' => 'KO1',
            'vehicle_type_id' => $type->id,
            'email' => $agency->email,
            'status' => 'paid',
            'invoice_amount' => '10.00',
            'email_sent' => Reservation::EMAIL_NOT_SENT,
        ], $overrides));

        DB::table('reservations')->where('id', $reservation->id)->update([
            'created_at' => $createdAt,
            'updated_at' => $createdAt,
        ]);
    }
}

