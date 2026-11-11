<?php

namespace Tests\Feature\Panel;

use App\Models\ListOfTimeSlot;
use App\Models\Reservation;
use App\Models\User;
use App\Models\VehicleType;
use App\Models\VehicleTypeTranslation;
use App\Services\Pdf\AgencyLicensePlateReportPdfGenerator;
use App\Services\Reservation\AgencyLicensePlateReportService;
use App\Support\ReservationKind;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class AgencyLicensePlateReportTest extends TestCase
{
    use RefreshDatabase;

    private ListOfTimeSlot $arrival;

    private ListOfTimeSlot $departure;

    private VehicleType $type;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow(Carbon::parse('2026-11-11 12:00:00', 'Europe/Podgorica'));
        app()->setLocale('en');

        $this->arrival = ListOfTimeSlot::query()->create(['time_slot' => '10:00 - 10:20']);
        $this->departure = ListOfTimeSlot::query()->create(['time_slot' => '14:00 - 14:20']);
        $this->type = VehicleType::query()->create(['price' => 15]);
        VehicleTypeTranslation::query()->create([
            'vehicle_type_id' => $this->type->id,
            'locale' => 'en',
            'name' => 'Car',
            'description' => '',
        ]);
    }

    public function test_paid_v2_time_slot_reservation_is_listed_with_slots_and_amount(): void
    {
        $agency = $this->agency('paid@example.com');
        $this->reservation($agency, [
            'license_plate' => 'KO12AB',
            'merchant_transaction_id' => 'mt-paid',
            'reservation_date' => '2026-11-10',
            'invoice_amount' => '15.00',
            'created_at' => '2026-11-09 08:00:00',
        ]);

        $report = $this->report($agency, 'KO12AB', '2026-11-10', '2026-11-10');

        $this->assertSame(1, $report['reservation_count']);
        $this->assertSame('15.00', $report['amount_total']);
        $this->assertSame('paid', $report['lines'][0]['status']);
        $this->assertSame('10:00 - 10:20', $report['lines'][0]['drop_off_slot']);
        $this->assertSame('14:00 - 14:20', $report['lines'][0]['pick_up_slot']);
        $this->assertFalse($report['lines'][0]['daily_ticket']);
        $this->assertSame('Car', $report['lines'][0]['category']);
        $this->assertSame('mt-paid', $report['lines'][0]['merchant_transaction_id']);
    }

    public function test_attributed_historical_reservation_is_included_and_unattributed_is_not(): void
    {
        $agency = $this->agency('hist@example.com');
        $this->reservation($agency, [
            'license_plate' => 'HIST1',
            'merchant_transaction_id' => 'mt-attributed',
            'reservation_date' => '2026-11-08',
            'invoice_amount' => '20.00',
            'created_at' => '2025-08-01 09:00:00',
        ]);
        $this->reservation(null, [
            'license_plate' => 'HIST1',
            'merchant_transaction_id' => 'mt-unattributed',
            'reservation_date' => '2026-11-08',
            'invoice_amount' => '99.00',
            'created_at' => '2025-08-02 09:00:00',
        ]);

        $report = $this->report($agency, 'HIST1', '2026-11-08', '2026-11-08');

        $this->assertSame(['mt-attributed'], array_column($report['lines'], 'merchant_transaction_id'));
        $this->assertSame('20.00', $report['amount_total']);
    }

    public function test_same_plate_on_another_agency_does_not_leak(): void
    {
        $agency = $this->agency('owner@example.com');
        $other = $this->agency('other@example.com');
        $this->reservation($agency, [
            'license_plate' => 'SHARED1',
            'merchant_transaction_id' => 'mt-owner',
            'reservation_date' => '2026-11-09',
            'invoice_amount' => '11.00',
        ]);
        $this->reservation($other, [
            'license_plate' => 'SHARED1',
            'merchant_transaction_id' => 'mt-other',
            'reservation_date' => '2026-11-09',
            'invoice_amount' => '99.00',
        ]);

        $report = $this->report($agency, 'SHARED1', '2026-11-09', '2026-11-09');

        $this->assertSame(['mt-owner'], array_column($report['lines'], 'merchant_transaction_id'));
        $this->assertSame('11.00', $report['amount_total']);

        $this->actingAs($agency)->get(route('panel.statistics', [
            'report' => 'license_plate',
            'license_plate' => 'SHARED1',
            'date_from' => '2026-11-09',
            'date_to' => '2026-11-09',
        ], false))->assertOk()->assertDontSee('mt-other', false)->assertDontSee('99.00', false);
    }

    public function test_differently_cased_stored_plates_are_not_merged(): void
    {
        $agency = $this->agency('case@example.com');
        $this->reservation($agency, [
            'license_plate' => 'KO12AB',
            'merchant_transaction_id' => 'mt-upper',
            'reservation_date' => '2026-11-09',
            'invoice_amount' => '15.00',
        ]);
        $this->reservation($agency, [
            'license_plate' => 'ko12ab',
            'merchant_transaction_id' => 'mt-lower',
            'reservation_date' => '2026-11-09',
            'invoice_amount' => '30.00',
        ]);

        $upper = $this->report($agency, 'KO12AB', '2026-11-09', '2026-11-09');
        $lower = $this->report($agency, 'ko12ab', '2026-11-09', '2026-11-09');
        $plates = app(AgencyLicensePlateReportService::class)->platesFor($agency);

        $this->assertSame(['mt-upper'], array_column($upper['lines'], 'merchant_transaction_id'));
        $this->assertSame(['KO12AB'], array_column($upper['lines'], 'license_plate'));
        $this->assertSame('15.00', $upper['amount_total']);
        $this->assertSame(['mt-lower'], array_column($lower['lines'], 'merchant_transaction_id'));
        $this->assertSame(['ko12ab'], array_column($lower['lines'], 'license_plate'));
        $this->assertSame('30.00', $lower['amount_total']);
        $this->assertContains('KO12AB', $plates);
        $this->assertContains('ko12ab', $plates);
        $this->assertTrue(AgencyLicensePlateReportService::driverNeedsBinaryPlateMatch('mysql'));
        $this->assertTrue(AgencyLicensePlateReportService::driverNeedsBinaryPlateMatch('mariadb'));
        $this->assertFalse(AgencyLicensePlateReportService::driverNeedsBinaryPlateMatch('sqlite'));
        $this->assertSame(
            'CAST(license_plate AS BINARY) = CAST(? AS BINARY)',
            AgencyLicensePlateReportService::MYSQL_EXACT_PLATE,
        );

        $this->actingAs($agency)->get(route('panel.statistics', [
            'report' => 'license_plate',
            'license_plate' => 'KO12Ab',
            'date_from' => '2026-11-09',
            'date_to' => '2026-11-09',
        ], false))->assertSessionHasErrors('license_plate');
    }

    public function test_stored_plates_differing_only_by_trailing_space_are_not_merged(): void
    {
        $agency = $this->agency('space@example.com');
        $this->reservation($agency, [
            'license_plate' => 'KO12AB',
            'merchant_transaction_id' => 'mt-plain',
            'reservation_date' => '2026-11-09',
            'invoice_amount' => '15.00',
        ]);
        $this->reservation($agency, [
            'license_plate' => 'KO12AB ',
            'merchant_transaction_id' => 'mt-space',
            'reservation_date' => '2026-11-09',
            'invoice_amount' => '30.00',
        ]);

        $plain = $this->report($agency, 'KO12AB', '2026-11-09', '2026-11-09');
        $spaced = $this->report($agency, 'KO12AB ', '2026-11-09', '2026-11-09');
        $plates = app(AgencyLicensePlateReportService::class)->platesFor($agency);
        $service = app(AgencyLicensePlateReportService::class);

        $this->assertSame(['mt-plain'], array_column($plain['lines'], 'merchant_transaction_id'));
        $this->assertSame(['KO12AB'], array_column($plain['lines'], 'license_plate'));
        $this->assertSame('15.00', $plain['amount_total']);
        $this->assertSame(['mt-space'], array_column($spaced['lines'], 'merchant_transaction_id'));
        $this->assertSame(['KO12AB '], array_column($spaced['lines'], 'license_plate'));
        $this->assertSame('30.00', $spaced['amount_total']);
        $this->assertContains('KO12AB', $plates);
        $this->assertContains('KO12AB ', $plates);
        $this->assertTrue($service->ownsPlate($agency, 'KO12AB'));
        $this->assertTrue($service->ownsPlate($agency, 'KO12AB '));
        $this->assertFalse($service->ownsPlate($agency, 'KO12AB  '));

        $this->actingAs($agency)->get(route('panel.statistics', [
            'report' => 'license_plate',
            'license_plate' => 'KO12AB ',
            'date_from' => '2026-11-09',
            'date_to' => '2026-11-09',
        ], false))->assertOk()->assertSee('mt-space', false)->assertDontSee('mt-plain', false);

        $this->actingAs($agency)->get(route('panel.statistics', [
            'report' => 'license_plate',
            'license_plate' => 'KO12AB  ',
            'date_from' => '2026-11-09',
            'date_to' => '2026-11-09',
        ], false))->assertSessionHasErrors('license_plate');
    }

    public function test_plate_match_is_exact_and_foreign_plate_is_rejected(): void
    {
        $agency = $this->agency('exact@example.com');
        $other = $this->agency('foreign@example.com');
        $this->reservation($agency, [
            'license_plate' => 'KO12AB',
            'reservation_date' => '2026-11-09',
        ]);
        $this->reservation($other, [
            'license_plate' => 'OTHER1',
            'merchant_transaction_id' => 'mt-foreign',
            'reservation_date' => '2026-11-09',
            'invoice_amount' => '80.00',
        ]);

        $this->actingAs($agency)->get(route('panel.statistics', [
            'report' => 'license_plate',
            'license_plate' => 'ko12ab',
            'date_from' => '2026-11-09',
            'date_to' => '2026-11-09',
        ], false))->assertSessionHasErrors('license_plate');

        $this->actingAs($agency)->get(route('panel.statistics', [
            'report' => 'license_plate',
            'license_plate' => 'OTHER1',
            'date_from' => '2026-11-09',
            'date_to' => '2026-11-09',
        ], false))->assertSessionHasErrors('license_plate');
    }

    public function test_free_reservation_displays_zero_without_changing_the_stored_amount(): void
    {
        $agency = $this->agency('free@example.com');
        $reservation = $this->reservation($agency, [
            'license_plate' => 'FREE1',
            'status' => 'free',
            'invoice_amount' => '20.00',
            'reservation_date' => '2026-11-09',
            'merchant_transaction_id' => null,
        ]);

        $report = $this->report($agency, 'FREE1', '2026-11-09', '2026-11-09');

        $this->assertSame('0.00', $report['lines'][0]['amount']);
        $this->assertSame('0.00', $report['amount_total']);
        $this->assertSame('free', $report['lines'][0]['status']);
        $this->assertNull($report['lines'][0]['merchant_transaction_id']);
        $this->assertSame('20.00', Reservation::query()->find($reservation->id)?->invoice_amount);
    }

    public function test_daily_fee_does_not_show_time_slots_and_future_rows_stay_included(): void
    {
        $agency = $this->agency('daily@example.com');
        $this->reservation($agency, [
            'license_plate' => 'DAY1',
            'reservation_kind' => ReservationKind::DAILY_TICKET,
            'drop_off_time_slot_id' => null,
            'pick_up_time_slot_id' => null,
            'reservation_date' => '2026-12-20',
            'invoice_amount' => '40.00',
            'merchant_transaction_id' => 'mt-daily',
        ]);

        $report = $this->report($agency, 'DAY1', '2026-12-20', '2026-12-20');

        $this->assertTrue($report['lines'][0]['daily_ticket']);
        $this->assertNull($report['lines'][0]['drop_off_slot']);
        $this->assertNull($report['lines'][0]['pick_up_slot']);
        $this->assertSame('2026-12-20', $report['lines'][0]['date']);
        $this->assertSame('40.00', $report['amount_total']);
    }

    public function test_date_boundaries_and_unknown_status_are_preserved(): void
    {
        $agency = $this->agency('bounds@example.com');
        $this->reservation($agency, [
            'license_plate' => 'BOUND1',
            'reservation_date' => '2026-11-01',
            'merchant_transaction_id' => 'mt-before',
        ]);
        $this->reservation($agency, [
            'license_plate' => 'BOUND1',
            'reservation_date' => '2026-11-02',
            'merchant_transaction_id' => 'mt-on-from',
            'status' => 'storno',
            'invoice_amount' => '7.50',
        ]);
        $this->reservation($agency, [
            'license_plate' => 'BOUND1',
            'reservation_date' => '2026-11-03',
            'merchant_transaction_id' => 'mt-on-to',
        ]);
        $this->reservation($agency, [
            'license_plate' => 'BOUND1',
            'reservation_date' => '2026-11-04',
            'merchant_transaction_id' => 'mt-after',
        ]);

        $report = $this->report($agency, 'BOUND1', '2026-11-02', '2026-11-03');

        $this->assertSame(['mt-on-from', 'mt-on-to'], array_column($report['lines'], 'merchant_transaction_id'));
        $this->assertSame('storno', $report['lines'][0]['status']);
        $this->assertSame('7.50', $report['lines'][0]['amount']);
    }

    public function test_empty_period_and_preserved_filters_share_the_pdf_dataset(): void
    {
        $agency = $this->agency('empty@example.com');
        $this->reservation($agency, [
            'license_plate' => 'EMPTY1',
            'reservation_date' => '2026-11-01',
            'merchant_transaction_id' => 'mt-outside',
            'invoice_amount' => '15.00',
        ]);

        $empty = $this->report($agency, 'EMPTY1', '2026-12-01', '2026-12-02');
        $this->assertSame([], $empty['lines']);
        $this->assertSame('0.00', $empty['amount_total']);

        $captured = null;
        $this->app->bind(AgencyLicensePlateReportPdfGenerator::class, function () use (&$captured) {
            return new class($captured) extends AgencyLicensePlateReportPdfGenerator
            {
                public function __construct(private mixed &$captured) {}

                public function renderBinary(array $dataset, string $locale = 'cg'): string
                {
                    $this->captured = $dataset;

                    return '%PDF-stub';
                }
            };
        });

        $query = [
            'report' => 'license_plate',
            'license_plate' => 'EMPTY1',
            'date_from' => '2026-12-01',
            'date_to' => '2026-12-02',
        ];
        $html = $this->actingAs($agency)->get(route('panel.statistics', $query, false));
        $html->assertOk();
        $html->assertSee('No reservations in the selected period.', false);
        $html->assertSee('value="EMPTY1"', false);
        $html->assertSee('value="license_plate"', false);
        $html->assertSee('license_plate=EMPTY1', false);
        $html->assertSee('date_from=2026-12-01', false);
        $html->assertDontSee('mt-outside', false);

        $pdf = $this->get(route('panel.statistics.pdf', $query, false));
        $pdf->assertOk();
        $pdf->assertHeader('content-type', 'application/pdf');

        $expected = app(AgencyLicensePlateReportService::class)->build(
            $agency,
            'EMPTY1',
            Carbon::parse('2026-12-01', 'Europe/Podgorica')->startOfDay(),
            Carbon::parse('2026-12-02', 'Europe/Podgorica')->startOfDay(),
            'en',
        );
        $this->assertSame($expected['lines'], $captured['lines']);
        $this->assertSame($expected['amount_total'], $captured['amount_total']);
        $this->assertSame($expected['reservation_count'], $captured['reservation_count']);
        $this->assertSame($agency->name, $captured['agency_name']);
        $this->assertSame('EMPTY1', $captured['license_plate']);
    }

    /**
     * @param  array<string, mixed>  $overrides
     */
    private function reservation(?User $agency, array $overrides): Reservation
    {
        $createdAt = $overrides['created_at'] ?? '2026-11-01 10:00:00';
        unset($overrides['created_at']);

        $reservation = Reservation::query()->create(array_merge([
            'user_id' => $agency?->id,
            'merchant_transaction_id' => 'mt-'.uniqid(),
            'drop_off_time_slot_id' => $this->arrival->id,
            'pick_up_time_slot_id' => $this->departure->id,
            'reservation_date' => '2026-11-10',
            'user_name' => $agency?->name ?? 'Guest',
            'country' => 'ME',
            'license_plate' => 'KO12AB',
            'vehicle_type_id' => $this->type->id,
            'email' => $agency?->email ?? 'guest@example.com',
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
    private function report(User $agency, string $plate, string $from, string $to): array
    {
        return app(AgencyLicensePlateReportService::class)->build(
            $agency,
            $plate,
            Carbon::parse($from, 'Europe/Podgorica')->startOfDay(),
            Carbon::parse($to, 'Europe/Podgorica')->startOfDay(),
            'en',
        );
    }
}
