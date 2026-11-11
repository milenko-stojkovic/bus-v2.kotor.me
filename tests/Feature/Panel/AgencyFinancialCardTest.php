<?php

namespace Tests\Feature\Panel;

use App\Models\AgencyAdvanceTopup;
use App\Models\AgencyAdvanceTransaction;
use App\Models\ListOfTimeSlot;
use App\Models\Reservation;
use App\Models\User;
use App\Models\VehicleType;
use App\Services\Pdf\AgencyFinancialCardPdfGenerator;
use App\Services\Reservation\AgencyFinancialCardService;
use App\Services\Reservation\AgencyFinancialCardWindow;
use App\Support\ReservationKind;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class AgencyFinancialCardTest extends TestCase
{
    use RefreshDatabase;

    private ListOfTimeSlot $slot;

    private VehicleType $type;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow(Carbon::parse('2026-11-11 12:00:00', 'Europe/Podgorica'));
        app()->setLocale('en');

        $this->slot = ListOfTimeSlot::query()->create(['time_slot' => '10:00 - 10:20']);
        $this->type = VehicleType::query()->create(['price' => 15]);
    }

    public function test_attributed_historical_paid_reservation_is_an_incoming_purchase_on_its_recorded_date(): void
    {
        $agency = $this->agency('hist@example.com');
        $this->reservation($agency, [
            'merchant_transaction_id' => 'mt-hist-1',
            'reservation_date' => '2025-08-10',
            'license_plate' => 'HIST1',
            'invoice_amount' => '15.00',
            'created_at' => '2025-08-01 09:15:00',
        ]);

        $card = $this->card($agency, '2025-08-01', '2025-08-01');

        $this->assertSame('15.00', $card['total_incoming']);
        $this->assertSame('0.00', $card['total_services']);
        $this->assertCount(1, $card['lines']);
        $this->assertSame('card_purchase', $card['lines'][0]['kind']);
        $this->assertSame('2025-08-01', $card['lines'][0]['date']);
        $this->assertSame('mt-hist-1', $card['lines'][0]['merchant_transaction_id']);
        $this->assertSame('HIST1', $card['lines'][0]['license_plate']);
        $this->assertTrue($card['historical_notice']);
    }

    public function test_historical_service_uses_reservation_date_not_purchase_date(): void
    {
        $agency = $this->agency('hist-svc@example.com');
        $this->reservation($agency, [
            'merchant_transaction_id' => 'mt-hist-svc',
            'reservation_date' => '2025-08-10',
            'invoice_amount' => '20.00',
            'created_at' => '2025-08-01 09:15:00',
        ]);

        $service = $this->card($agency, '2025-08-10', '2025-08-10');

        $this->assertSame('0.00', $service['total_incoming']);
        $this->assertSame('20.00', $service['total_services']);
        $this->assertSame('service', $service['lines'][0]['kind']);
        $this->assertSame('2025-08-10', $service['lines'][0]['date']);
    }

    public function test_native_v2_card_reservation_is_incoming_and_can_be_a_separate_service_line(): void
    {
        $agency = $this->agency('card@example.com');
        $this->reservation($agency, [
            'merchant_transaction_id' => 'mt-v2-card',
            'reservation_date' => '2026-07-02',
            'invoice_amount' => '40.00',
            'payment_method' => null,
            'created_at' => '2026-07-01 08:00:00',
        ]);

        $both = $this->card($agency, '2026-07-01', '2026-07-02');

        $this->assertSame('40.00', $both['total_incoming']);
        $this->assertSame('40.00', $both['total_services']);
        $this->assertSame(['card_purchase', 'service'], array_column($both['lines'], 'kind'));
        $this->assertFalse($both['historical_notice']);
    }

    public function test_advance_paid_reservation_is_not_a_second_incoming_card_purchase(): void
    {
        $agency = $this->agency('adv@example.com');
        $reservation = $this->reservation($agency, [
            'merchant_transaction_id' => 'mt-adv-res',
            'reservation_date' => '2026-07-03',
            'invoice_amount' => '15.00',
            'payment_method' => 'advance',
            'created_at' => '2026-07-03 11:00:00',
        ]);
        AgencyAdvanceTransaction::query()->create([
            'agency_user_id' => $agency->id,
            'amount' => '-15.00',
            'type' => AgencyAdvanceTransaction::TYPE_USAGE,
            'reference_type' => 'reservation',
            'reference_id' => $reservation->id,
            'merchant_transaction_id' => 'mt-adv-res',
        ]);
        AgencyAdvanceTransaction::query()->create([
            'agency_user_id' => $agency->id,
            'amount' => '15.00',
            'type' => AgencyAdvanceTransaction::TYPE_TOPUP,
            'reference_type' => 'advance_topup',
            'reference_id' => 1,
            'merchant_transaction_id' => 'mt-topup-ledger',
        ]);

        $card = $this->card($agency, '2026-07-03', '2026-07-03');

        $this->assertSame('0.00', $card['total_incoming']);
        $this->assertSame('15.00', $card['total_services']);
        $this->assertSame(['service'], array_column($card['lines'], 'kind'));
    }

    public function test_paid_topup_is_counted_once_on_paid_at(): void
    {
        $agency = $this->agency('topup@example.com');
        AgencyAdvanceTopup::query()->create([
            'agency_user_id' => $agency->id,
            'merchant_transaction_id' => 'mt-topup-1',
            'amount' => '50.00',
            'status' => AgencyAdvanceTopup::STATUS_PAID,
            'paid_at' => '2026-09-01 14:00:00',
        ]);
        AgencyAdvanceTransaction::query()->create([
            'agency_user_id' => $agency->id,
            'amount' => '50.00',
            'type' => AgencyAdvanceTransaction::TYPE_TOPUP,
            'reference_type' => 'advance_topup',
            'reference_id' => 9,
            'merchant_transaction_id' => 'mt-topup-1',
        ]);

        $card = $this->card($agency, '2026-09-01', '2026-09-01');

        $this->assertSame('50.00', $card['total_incoming']);
        $this->assertSame('0.00', $card['total_services']);
        $this->assertCount(1, $card['lines']);
        $this->assertSame('topup', $card['lines'][0]['kind']);
        $this->assertSame('mt-topup-1', $card['lines'][0]['merchant_transaction_id']);
    }

    public function test_free_reservation_is_excluded_from_money_totals(): void
    {
        $agency = $this->agency('free@example.com');
        $this->reservation($agency, [
            'merchant_transaction_id' => 'mt-free',
            'reservation_date' => '2026-07-04',
            'status' => 'free',
            'invoice_amount' => '15.00',
            'created_at' => '2026-07-04 09:00:00',
        ]);

        $card = $this->card($agency, '2026-07-01', '2026-07-31');

        $this->assertSame([], $card['lines']);
        $this->assertSame('0.00', $card['total_incoming']);
        $this->assertSame('0.00', $card['total_services']);
    }

    public function test_correction_is_separate_and_keeps_its_sign(): void
    {
        $agency = $this->agency('corr@example.com');
        $correction = AgencyAdvanceTransaction::query()->create([
            'agency_user_id' => $agency->id,
            'amount' => '-5.50',
            'type' => AgencyAdvanceTransaction::TYPE_CORRECTION,
            'note' => 'Manual decrease',
        ]);
        DB::table('agency_advance_transactions')->where('id', $correction->id)->update([
            'created_at' => '2026-09-05 16:00:00',
            'updated_at' => '2026-09-05 16:00:00',
        ]);

        $card = $this->card($agency, '2026-09-05', '2026-09-05');

        $this->assertSame('0.00', $card['total_incoming']);
        $this->assertSame('0.00', $card['total_services']);
        $this->assertSame('-5.50', $card['total_corrections']);
        $this->assertSame('correction', $card['lines'][0]['kind']);
        $this->assertSame('Manual decrease', $card['lines'][0]['note']);
        $this->assertNull($card['lines'][0]['merchant_transaction_id']);
    }

    public function test_unrealized_reservation_has_no_service_line(): void
    {
        $agency = $this->agency('future@example.com');
        $this->reservation($agency, [
            'merchant_transaction_id' => 'mt-future',
            'reservation_date' => '2026-11-12',
            'invoice_amount' => '15.00',
            'created_at' => '2026-11-11 09:00:00',
        ]);

        $card = $this->card($agency, '2026-11-11', '2026-11-12');

        $this->assertSame(['card_purchase'], array_column($card['lines'], 'kind'));
        $this->assertSame('15.00', $card['total_incoming']);
        $this->assertSame('0.00', $card['total_services']);
    }

    public function test_same_day_time_slot_is_realized_only_after_pickup_end(): void
    {
        $agency = $this->agency('slot@example.com');
        $this->reservation($agency, [
            'merchant_transaction_id' => 'mt-slot',
            'reservation_date' => '2026-11-11',
            'invoice_amount' => '15.00',
            'created_at' => '2026-11-10 09:00:00',
        ]);

        Carbon::setTestNow(Carbon::parse('2026-11-11 10:19:59', 'Europe/Podgorica'));
        $before = $this->card($agency, '2026-11-11', '2026-11-11');
        $this->assertSame([], array_column($before['lines'], 'kind'));

        Carbon::setTestNow(Carbon::parse('2026-11-11 10:20:00', 'Europe/Podgorica'));
        $after = $this->card($agency, '2026-11-11', '2026-11-11');
        $this->assertSame(['service'], array_column($after['lines'], 'kind'));
        $this->assertSame('15.00', $after['total_services']);
    }

    public function test_daily_ticket_is_realized_on_the_following_calendar_day(): void
    {
        $agency = $this->agency('daily@example.com');
        $this->reservation($agency, [
            'merchant_transaction_id' => 'mt-daily',
            'reservation_kind' => ReservationKind::DAILY_TICKET,
            'drop_off_time_slot_id' => null,
            'pick_up_time_slot_id' => null,
            'reservation_date' => '2026-11-11',
            'invoice_amount' => '15.00',
            'created_at' => '2026-11-10 09:00:00',
        ]);

        Carbon::setTestNow(Carbon::parse('2026-11-11 23:59:00', 'Europe/Podgorica'));
        $this->assertSame('0.00', $this->card($agency, '2026-11-11', '2026-11-11')['total_services']);

        Carbon::setTestNow(Carbon::parse('2026-11-12 00:05:00', 'Europe/Podgorica'));
        $this->assertSame('15.00', $this->card($agency, '2026-11-11', '2026-11-11')['total_services']);
    }

    public function test_each_line_type_follows_its_own_date(): void
    {
        $agency = $this->agency('range@example.com');
        $this->reservation($agency, [
            'merchant_transaction_id' => 'mt-range-buy',
            'reservation_date' => '2026-08-03',
            'invoice_amount' => '10.00',
            'created_at' => '2026-08-01 08:00:00',
        ]);
        AgencyAdvanceTopup::query()->create([
            'agency_user_id' => $agency->id,
            'merchant_transaction_id' => 'mt-range-top',
            'amount' => '25.00',
            'status' => AgencyAdvanceTopup::STATUS_PAID,
            'paid_at' => '2026-08-02 12:00:00',
        ]);
        $correction = AgencyAdvanceTransaction::query()->create([
            'agency_user_id' => $agency->id,
            'amount' => '3.00',
            'type' => AgencyAdvanceTransaction::TYPE_CORRECTION,
            'note' => 'Adjust',
        ]);
        DB::table('agency_advance_transactions')->where('id', $correction->id)->update([
            'created_at' => '2026-08-04 18:00:00',
        ]);

        $this->assertSame(['card_purchase'], array_column($this->card($agency, '2026-08-01', '2026-08-01')['lines'], 'kind'));
        $this->assertSame(['topup'], array_column($this->card($agency, '2026-08-02', '2026-08-02')['lines'], 'kind'));
        $this->assertSame(['service'], array_column($this->card($agency, '2026-08-03', '2026-08-03')['lines'], 'kind'));
        $this->assertSame(['correction'], array_column($this->card($agency, '2026-08-04', '2026-08-04')['lines'], 'kind'));
    }

    public function test_timestamp_bounds_keep_civil_dates_across_dst(): void
    {
        $window = app(AgencyFinancialCardWindow::class);
        $spring = $window->timestampBounds(
            Carbon::parse('2026-03-29', 'Europe/Podgorica'),
            Carbon::parse('2026-03-29', 'Europe/Podgorica'),
        );
        $this->assertSame('2026-03-29 00:00:00', $spring['start']);
        $this->assertSame('2026-03-30 00:00:00', $spring['end_exclusive']);

        $autumn = $window->timestampBounds(
            Carbon::parse('2026-10-25', 'Europe/Podgorica'),
            Carbon::parse('2026-10-25', 'Europe/Podgorica'),
        );
        $this->assertSame('2026-10-25 00:00:00', $autumn['start']);
        $this->assertSame('2026-10-26 00:00:00', $autumn['end_exclusive']);

        $agency = $this->agency('dst@example.com');
        $this->reservation($agency, [
            'merchant_transaction_id' => 'mt-dst-before',
            'reservation_date' => '2026-03-28',
            'invoice_amount' => '1.00',
            'created_at' => '2026-03-28 23:30:00',
        ]);
        $this->reservation($agency, [
            'merchant_transaction_id' => 'mt-dst-spring',
            'reservation_date' => '2026-03-29',
            'invoice_amount' => '2.00',
            'created_at' => '2026-03-29 01:30:00',
        ]);
        $this->reservation($agency, [
            'merchant_transaction_id' => 'mt-dst-autumn',
            'reservation_date' => '2026-10-25',
            'invoice_amount' => '3.00',
            'created_at' => '2026-10-25 23:30:00',
        ]);
        $this->reservation($agency, [
            'merchant_transaction_id' => 'mt-dst-next',
            'reservation_date' => '2026-10-26',
            'invoice_amount' => '4.00',
            'created_at' => '2026-10-26 00:30:00',
        ]);

        $march = $this->card($agency, '2026-03-29', '2026-03-29');
        $marchPurchases = array_values(array_filter(
            $march['lines'],
            fn (array $line): bool => $line['kind'] === 'card_purchase',
        ));
        $this->assertSame(['mt-dst-spring'], array_column($marchPurchases, 'merchant_transaction_id'));

        $october = $this->card($agency, '2026-10-25', '2026-10-25');
        $purchaseIds = array_values(array_filter(
            $october['lines'],
            fn (array $line): bool => $line['kind'] === 'card_purchase',
        ));
        $this->assertSame(['mt-dst-autumn'], array_column($purchaseIds, 'merchant_transaction_id'));
    }

    public function test_historical_notice_is_only_for_the_overlapping_period(): void
    {
        $window = app(AgencyFinancialCardWindow::class);
        $this->assertTrue($window->overlapsHistoricalNotice(
            Carbon::parse('2026-06-17', 'Europe/Podgorica'),
            Carbon::parse('2026-06-17', 'Europe/Podgorica'),
        ));
        $this->assertFalse($window->overlapsHistoricalNotice(
            Carbon::parse('2026-06-18', 'Europe/Podgorica'),
            Carbon::parse('2026-06-18', 'Europe/Podgorica'),
        ));

        $agency = $this->agency('notice@example.com');
        $this->reservation($agency, [
            'reservation_date' => '2025-08-01',
            'created_at' => '2025-08-01 10:00:00',
            'invoice_amount' => '15.00',
        ]);

        $this->actingAs($agency);
        $overlap = $this->get(route('panel.statistics', [
            'report' => 'financial_card',
            'date_from' => '2025-08-01',
            'date_to' => '2025-08-01',
        ], false));
        $overlap->assertOk();
        $overlap->assertSee('may not yet appear on this agency financial card', false);
        $overlap->assertDontSee('mt-other-agency', false);

        $later = $this->get(route('panel.statistics', [
            'report' => 'financial_card',
            'date_from' => '2026-08-01',
            'date_to' => '2026-08-02',
        ], false));
        $later->assertOk();
        $later->assertDontSee('may not yet appear on this agency financial card', false);
    }

    public function test_missing_mtid_and_undated_paid_topup_are_reported_without_a_fabricated_date(): void
    {
        $agency = $this->agency('optional@example.com');
        $this->reservation($agency, [
            'merchant_transaction_id' => null,
            'reservation_date' => '2026-07-08',
            'license_plate' => 'NOMTID',
            'invoice_amount' => '15.00',
            'created_at' => '2026-07-08 10:00:00',
        ]);
        AgencyAdvanceTopup::query()->create([
            'agency_user_id' => $agency->id,
            'merchant_transaction_id' => 'mt-undated',
            'amount' => '80.00',
            'status' => AgencyAdvanceTopup::STATUS_PAID,
            'paid_at' => null,
        ]);

        $card = $this->card($agency, '2026-07-08', '2026-07-08');

        $this->assertNull($card['lines'][0]['merchant_transaction_id']);
        $this->assertSame('NOMTID', $card['lines'][0]['license_plate']);
        $this->assertSame(1, $card['undated_paid_topup_count']);
        $this->assertSame('15.00', $card['total_incoming']);
        $this->assertNotContains('80.00', array_column($card['lines'], 'incoming'));

        $html = $this->actingAs($agency)->get(route('panel.statistics', [
            'report' => 'financial_card',
            'date_from' => '2026-07-08',
            'date_to' => '2026-07-08',
        ], false));
        $html->assertOk();
        $html->assertSee('not only the selected period: 1', false);
        $html->assertDontSee('80.00', false);
    }

    public function test_other_agency_events_are_excluded_from_html_and_pdf(): void
    {
        $agency = $this->agency('owner@example.com');
        $other = $this->agency('other@example.com');
        $this->reservation($agency, [
            'merchant_transaction_id' => 'mt-owner',
            'reservation_date' => '2026-07-09',
            'license_plate' => 'OWN1',
            'invoice_amount' => '15.00',
            'created_at' => '2026-07-09 10:00:00',
        ]);
        $this->reservation($other, [
            'merchant_transaction_id' => 'mt-other-agency',
            'reservation_date' => '2026-07-09',
            'license_plate' => 'OTHER1',
            'invoice_amount' => '999.00',
            'created_at' => '2026-07-09 10:00:00',
        ]);
        AgencyAdvanceTopup::query()->create([
            'agency_user_id' => $other->id,
            'merchant_transaction_id' => 'mt-other-topup',
            'amount' => '70.00',
            'status' => AgencyAdvanceTopup::STATUS_PAID,
            'paid_at' => '2026-07-09 11:00:00',
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
        $html = $this->get(route('panel.statistics', [
            'report' => 'financial_card',
            'date_from' => '2026-07-09',
            'date_to' => '2026-07-09',
        ], false));
        $html->assertOk();
        $html->assertSee('OWN1', false);
        $html->assertSee('mt-owner', false);
        $html->assertDontSee('OTHER1', false);
        $html->assertDontSee('mt-other-agency', false);
        $html->assertDontSee('70.00', false);
        $html->assertDontSee('999.00', false);

        $pdf = $this->get(route('panel.statistics.pdf', [
            'report' => 'financial_card',
            'date_from' => '2026-07-09',
            'date_to' => '2026-07-09',
        ], false));
        $pdf->assertOk();
        $pdf->assertHeader('content-type', 'application/pdf');

        $expected = app(AgencyFinancialCardService::class)->build(
            $agency,
            Carbon::parse('2026-07-09', 'Europe/Podgorica')->startOfDay(),
            Carbon::parse('2026-07-09', 'Europe/Podgorica')->startOfDay(),
        );
        $this->assertIsArray($captured);
        $this->assertSame($expected['lines'], $captured['lines']);
        $this->assertSame($expected['total_incoming'], $captured['total_incoming']);
        $this->assertSame($expected['total_services'], $captured['total_services']);
        $this->assertSame($expected['total_corrections'], $captured['total_corrections']);
        $this->assertSame($agency->name, $captured['agency_name']);
    }

    /**
     * @param  array<string, mixed>  $overrides
     */
    private function reservation(User $agency, array $overrides): Reservation
    {
        $createdAt = $overrides['created_at'] ?? '2026-07-01 10:00:00';
        unset($overrides['created_at']);

        $reservation = Reservation::query()->create(array_merge([
            'user_id' => $agency->id,
            'merchant_transaction_id' => 'mt-'.uniqid(),
            'drop_off_time_slot_id' => $this->slot->id,
            'pick_up_time_slot_id' => $this->slot->id,
            'reservation_date' => '2026-07-01',
            'user_name' => $agency->name,
            'country' => 'ME',
            'license_plate' => 'KO123',
            'vehicle_type_id' => $this->type->id,
            'email' => $agency->email,
            'status' => 'paid',
            'invoice_amount' => '15.00',
            'email_sent' => Reservation::EMAIL_NOT_SENT,
        ], $overrides));

        DB::table('reservations')->where('id', $reservation->id)->update([
            'created_at' => $createdAt,
            'updated_at' => $createdAt,
        ]);

        return $reservation->fresh();
    }

    private function agency(string $email): User
    {
        return User::query()->create([
            'name' => 'Agency '.$email,
            'email' => $email,
            'password' => bcrypt('secret'),
            'country' => 'ME',
            'lang' => 'en',
            'email_verified_at' => now(),
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function card(User $agency, string $from, string $to): array
    {
        return app(AgencyFinancialCardService::class)->build(
            $agency,
            Carbon::parse($from, 'Europe/Podgorica')->startOfDay(),
            Carbon::parse($to, 'Europe/Podgorica')->startOfDay(),
        );
    }
}
