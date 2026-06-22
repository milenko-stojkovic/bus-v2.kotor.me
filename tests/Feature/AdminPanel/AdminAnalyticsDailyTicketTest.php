<?php

namespace Tests\Feature\AdminPanel;

use App\Models\Admin;
use App\Models\ListOfTimeSlot;
use App\Models\Reservation;
use App\Models\User;
use App\Models\VehicleType;
use App\Models\VehicleTypeTranslation;
use App\Services\AdminPanel\Analytics\AdminAnalyticsDefinitions;
use App\Services\AdminPanel\Analytics\AdminAnalyticsService;
use App\Services\Pdf\AdminAnalyticsPdfGenerator;
use App\Services\Reservation\ReservationVehicleEligibilityService;
use App\Support\ReservationKind;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

final class AdminAnalyticsDailyTicketTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        ReservationVehicleEligibilityService::clearCache();
    }

    private function buildDataset(string $date, bool $includeFree = false): array
    {
        return app(AdminAnalyticsService::class)->build($date, $date, $includeFree);
    }

    private function seedSlotPair(): array
    {
        $drop = ListOfTimeSlot::query()->create(['time_slot' => '10:00 - 10:20']);
        $pick = ListOfTimeSlot::query()->create(['time_slot' => '11:00 - 11:20']);

        return [$drop, $pick];
    }

    private function createLimoPassengerType(): VehicleType
    {
        $vt = VehicleType::query()->create(['price' => '15.00']);
        foreach (['cg', 'en'] as $locale) {
            VehicleTypeTranslation::query()->create([
                'vehicle_type_id' => $vt->id,
                'locale' => $locale,
                'name' => $locale === 'cg' ? 'Putničko vozilo' : 'Personal vehicle',
                'description' => $locale === 'cg' ? '4+1 do 7+1 sjedišta' : 'Passenger car (4+1 to 7+1 seats)',
            ]);
        }

        return $vt;
    }

    private function createMinibusType(): VehicleType
    {
        $vt = VehicleType::query()->create(['price' => '25.00']);
        foreach (['cg', 'en'] as $locale) {
            VehicleTypeTranslation::query()->create([
                'vehicle_type_id' => $vt->id,
                'locale' => $locale,
                'name' => 'Mini bus',
                'description' => $locale === 'cg' ? 'Mini bus (8+1 sjedište)' : 'Mini bus (8+1 seats)',
            ]);
        }

        return $vt;
    }

    private function createMediumBusType(): VehicleType
    {
        $vt = VehicleType::query()->create(['price' => '40.00']);
        foreach (['cg', 'en'] as $locale) {
            VehicleTypeTranslation::query()->create([
                'vehicle_type_id' => $vt->id,
                'locale' => $locale,
                'name' => $locale === 'cg' ? 'Srednji autobus' : 'Medium bus',
                'description' => $locale === 'cg' ? 'Autobus (9–23 sjedišta)' : 'Bus (9–23 seats)',
            ]);
        }

        return $vt;
    }

    /**
     * @param  array<string, mixed>  $overrides
     */
    private function createReservation(array $overrides): Reservation
    {
        return Reservation::query()->create(array_merge([
            'user_name' => 'Test',
            'country' => 'ME',
            'license_plate' => 'KO-'.Str::random(6),
            'email' => 't@test.local',
            'email_sent' => Reservation::EMAIL_NOT_SENT,
        ], $overrides));
    }

    public function test_analytics_counts_time_slots_and_daily_ticket_separately(): void
    {
        [$drop, $pick] = $this->seedSlotPair();
        $vt = VehicleType::query()->create(['price' => 30]);
        $date = '2026-08-01';

        $this->createReservation([
            'merchant_transaction_id' => 'mt-ts-'.Str::random(4),
            'reservation_kind' => ReservationKind::TIME_SLOTS,
            'drop_off_time_slot_id' => $drop->id,
            'pick_up_time_slot_id' => $pick->id,
            'reservation_date' => $date,
            'vehicle_type_id' => $vt->id,
            'status' => 'paid',
            'invoice_amount' => '30.00',
        ]);

        $this->createReservation([
            'merchant_transaction_id' => 'mt-dt-'.Str::random(4),
            'reservation_kind' => ReservationKind::DAILY_TICKET,
            'drop_off_time_slot_id' => null,
            'pick_up_time_slot_id' => null,
            'reservation_date' => $date,
            'vehicle_type_id' => $vt->id,
            'status' => 'paid',
            'invoice_amount' => '45.00',
        ]);

        $dataset = $this->buildDataset($date);

        $this->assertSame(1, $dataset['kpi']['time_slots_count']);
        $this->assertSame(1, $dataset['kpi']['daily_ticket_count']);
        $this->assertSame(30.0, $dataset['kpi']['time_slots_revenue']);
        $this->assertSame(45.0, $dataset['kpi']['daily_ticket_revenue']);
        $this->assertSame(75.0, $dataset['kpi']['total_revenue']);
    }

    public function test_paid_time_slots_appear_under_termini_kpi(): void
    {
        [$drop, $pick] = $this->seedSlotPair();
        $vt = VehicleType::query()->create(['price' => 20]);
        $date = '2026-08-10';

        $this->createReservation([
            'merchant_transaction_id' => 'mt-ts-kpi',
            'reservation_kind' => ReservationKind::TIME_SLOTS,
            'drop_off_time_slot_id' => $drop->id,
            'pick_up_time_slot_id' => $pick->id,
            'reservation_date' => $date,
            'vehicle_type_id' => $vt->id,
            'status' => 'paid',
            'invoice_amount' => '55.00',
        ]);

        $dataset = $this->buildDataset($date);
        $this->assertSame(1, $dataset['reservation_kinds']['time_slots']['count']);
        $this->assertSame(55.0, $dataset['reservation_kinds']['time_slots']['revenue']);
    }

    public function test_daily_fee_limo_passenger_and_minibus_classification(): void
    {
        $limoPassenger = $this->createLimoPassengerType();
        $minibus = $this->createMinibusType();
        $date = '2026-08-11';

        $this->createReservation([
            'merchant_transaction_id' => 'mt-dn-limo-p',
            'reservation_kind' => ReservationKind::DAILY_TICKET,
            'drop_off_time_slot_id' => null,
            'pick_up_time_slot_id' => null,
            'reservation_date' => $date,
            'vehicle_type_id' => $limoPassenger->id,
            'status' => 'paid',
            'invoice_amount' => '15.00',
        ]);

        $this->createReservation([
            'merchant_transaction_id' => 'mt-dn-limo-m',
            'reservation_kind' => ReservationKind::DAILY_TICKET,
            'drop_off_time_slot_id' => null,
            'pick_up_time_slot_id' => null,
            'reservation_date' => $date,
            'vehicle_type_id' => $minibus->id,
            'status' => 'paid',
            'invoice_amount' => '25.00',
        ]);

        $dataset = $this->buildDataset($date);

        $this->assertSame(2, $dataset['kpi']['daily_fee_limo_count']);
        $this->assertSame(40.0, $dataset['kpi']['daily_fee_limo_revenue']);
        $this->assertSame(0, $dataset['kpi']['daily_fee_buses_count']);
    }

    public function test_daily_fee_bus_category_appears_under_autobusi(): void
    {
        $bus = $this->createMediumBusType();
        $date = '2026-08-12';

        $this->createReservation([
            'merchant_transaction_id' => 'mt-dn-bus',
            'reservation_kind' => ReservationKind::DAILY_TICKET,
            'drop_off_time_slot_id' => null,
            'pick_up_time_slot_id' => null,
            'reservation_date' => $date,
            'vehicle_type_id' => $bus->id,
            'status' => 'paid',
            'invoice_amount' => '40.00',
        ]);

        $dataset = $this->buildDataset($date);
        $this->assertSame(1, $dataset['kpi']['daily_fee_buses_count']);
        $this->assertSame(40.0, $dataset['kpi']['daily_fee_buses_revenue']);
    }

    public function test_daily_ticket_not_counted_as_occupied_slot(): void
    {
        $vt = VehicleType::query()->create(['price' => 20]);
        $date = '2026-08-02';

        $this->createReservation([
            'merchant_transaction_id' => 'mt-dt-only',
            'reservation_kind' => ReservationKind::DAILY_TICKET,
            'drop_off_time_slot_id' => null,
            'pick_up_time_slot_id' => null,
            'reservation_date' => $date,
            'vehicle_type_id' => $vt->id,
            'status' => 'paid',
            'invoice_amount' => '20.00',
        ]);

        $dataset = $this->buildDataset($date);
        $this->assertSame(0, $dataset['kpi']['occupied_slots_total']);
    }

    public function test_time_slots_still_count_occupied_slots(): void
    {
        [$drop, $pick] = $this->seedSlotPair();
        $vt = VehicleType::query()->create(['price' => 20]);
        $date = '2026-08-03';

        $this->createReservation([
            'merchant_transaction_id' => 'mt-ts-only',
            'reservation_kind' => ReservationKind::TIME_SLOTS,
            'drop_off_time_slot_id' => $drop->id,
            'pick_up_time_slot_id' => $pick->id,
            'reservation_date' => $date,
            'vehicle_type_id' => $vt->id,
            'status' => 'paid',
            'invoice_amount' => '20.00',
        ]);

        $dataset = $this->buildDataset($date);
        $this->assertSame(2, $dataset['kpi']['occupied_slots_total']);
    }

    public function test_daily_fee_excluded_from_day_parts_slot_windows(): void
    {
        [$drop, $pick] = $this->seedSlotPair();
        $vt = VehicleType::query()->create(['price' => 10]);
        $date = '2026-08-04';

        $this->createReservation([
            'merchant_transaction_id' => 'mt-ts-dp',
            'reservation_kind' => ReservationKind::TIME_SLOTS,
            'drop_off_time_slot_id' => $drop->id,
            'pick_up_time_slot_id' => $pick->id,
            'reservation_date' => $date,
            'vehicle_type_id' => $vt->id,
            'status' => 'paid',
            'invoice_amount' => '10.00',
        ]);

        $this->createReservation([
            'merchant_transaction_id' => 'mt-dt-dp',
            'reservation_kind' => ReservationKind::DAILY_TICKET,
            'drop_off_time_slot_id' => null,
            'pick_up_time_slot_id' => null,
            'reservation_date' => $date,
            'vehicle_type_id' => $vt->id,
            'status' => 'paid',
            'invoice_amount' => '25.00',
        ]);

        $dataset = $this->buildDataset($date);
        $dayParts = collect($dataset['day_parts']);
        $this->assertNull($dayParts->firstWhere('key', AdminAnalyticsDefinitions::PART_DAILY_TICKET));

        $slotWindowReservations = $dayParts->sum('reservations');
        $this->assertSame(1, $slotWindowReservations);

        $dailyRows = collect($dataset['daily_fee']['rows']);
        $this->assertSame(25.0, (float) $dailyRows->firstWhere('label', 'Ukupno')['revenue']);
    }

    public function test_double_paid_same_slot_pairs_ignore_daily_ticket(): void
    {
        [$drop, $pick] = $this->seedSlotPair();
        $vt = VehicleType::query()->create(['price' => 10]);
        $date = '2026-08-05';
        $plate = 'KO777GG';

        foreach (['mt-dp-1', 'mt-dp-2'] as $mtid) {
            $this->createReservation([
                'merchant_transaction_id' => $mtid,
                'reservation_kind' => ReservationKind::DAILY_TICKET,
                'drop_off_time_slot_id' => null,
                'pick_up_time_slot_id' => null,
                'reservation_date' => $date,
                'vehicle_type_id' => $vt->id,
                'license_plate' => $plate,
                'status' => 'paid',
                'invoice_amount' => '10.00',
            ]);
        }

        foreach (['mt-ts-a', 'mt-ts-b'] as $mtid) {
            $this->createReservation([
                'merchant_transaction_id' => $mtid,
                'reservation_kind' => ReservationKind::TIME_SLOTS,
                'drop_off_time_slot_id' => $drop->id,
                'pick_up_time_slot_id' => $pick->id,
                'reservation_date' => $date,
                'vehicle_type_id' => $vt->id,
                'license_plate' => $plate,
                'status' => 'paid',
                'invoice_amount' => '10.00',
            ]);
        }

        $dataset = $this->buildDataset($date);
        $this->assertSame(1, $dataset['ops']['double_paid_same_slot_pairs']);
    }

    public function test_free_reservations_excluded_from_paid_revenue(): void
    {
        [$drop, $pick] = $this->seedSlotPair();
        $vt = VehicleType::query()->create(['price' => 10]);
        $date = '2026-08-13';

        $this->createReservation([
            'merchant_transaction_id' => 'mt-free-rev',
            'reservation_kind' => ReservationKind::TIME_SLOTS,
            'drop_off_time_slot_id' => $drop->id,
            'pick_up_time_slot_id' => $pick->id,
            'reservation_date' => $date,
            'vehicle_type_id' => $vt->id,
            'status' => 'free',
            'invoice_amount' => '0.00',
        ]);

        $dataset = $this->buildDataset($date, includeFree: true);
        $this->assertSame(0.0, $dataset['kpi']['time_slots_revenue']);
        $this->assertSame(1, $dataset['kpi']['time_slots_count']);
    }

    public function test_total_revenue_equals_termini_plus_daily_fee_limo_plus_autobusi(): void
    {
        [$drop, $pick] = $this->seedSlotPair();
        $limo = $this->createLimoPassengerType();
        $bus = $this->createMediumBusType();
        $date = '2026-08-14';

        $this->createReservation([
            'merchant_transaction_id' => 'mt-total-ts',
            'reservation_kind' => ReservationKind::TIME_SLOTS,
            'drop_off_time_slot_id' => $drop->id,
            'pick_up_time_slot_id' => $pick->id,
            'reservation_date' => $date,
            'vehicle_type_id' => $bus->id,
            'status' => 'paid',
            'invoice_amount' => '50.00',
        ]);

        $this->createReservation([
            'merchant_transaction_id' => 'mt-total-limo',
            'reservation_kind' => ReservationKind::DAILY_TICKET,
            'drop_off_time_slot_id' => null,
            'pick_up_time_slot_id' => null,
            'reservation_date' => $date,
            'vehicle_type_id' => $limo->id,
            'status' => 'paid',
            'invoice_amount' => '15.00',
        ]);

        $this->createReservation([
            'merchant_transaction_id' => 'mt-total-bus',
            'reservation_kind' => ReservationKind::DAILY_TICKET,
            'drop_off_time_slot_id' => null,
            'pick_up_time_slot_id' => null,
            'reservation_date' => $date,
            'vehicle_type_id' => $bus->id,
            'status' => 'paid',
            'invoice_amount' => '40.00',
        ]);

        $dataset = $this->buildDataset($date);
        $sum = $dataset['kpi']['time_slots_revenue']
            + $dataset['kpi']['daily_fee_limo_revenue']
            + $dataset['kpi']['daily_fee_buses_revenue'];
        $this->assertSame(105.0, $sum);
        $this->assertSame(105.0, $dataset['kpi']['total_revenue']);
    }

    public function test_analytics_pdf_includes_daily_fee_and_termini_split(): void
    {
        $admin = Admin::query()->create([
            'username' => 'anpdf',
            'email' => 'anpdf@test.local',
            'password' => bcrypt('secret'),
            'control_access' => false,
            'admin_access' => true,
        ]);
        $this->actingAs($admin, 'panel_admin');

        $limo = $this->createLimoPassengerType();
        $date = '2026-08-15';

        $this->createReservation([
            'merchant_transaction_id' => 'mt-pdf-dn',
            'reservation_kind' => ReservationKind::DAILY_TICKET,
            'drop_off_time_slot_id' => null,
            'pick_up_time_slot_id' => null,
            'reservation_date' => $date,
            'vehicle_type_id' => $limo->id,
            'status' => 'paid',
            'invoice_amount' => '15.00',
        ]);

        $dataset = $this->buildDataset($date);
        $binary = app(AdminAnalyticsPdfGenerator::class)->renderBinary($dataset);
        $this->assertNotEmpty($binary);

        $html = view('pdf.admin-analytics-report', ['dataset' => $dataset])->render();
        $this->assertStringContainsString('Dnevna naknada', $html);
        $this->assertStringContainsString('DN — Limo', $html);
        $this->assertStringContainsString('Termini — broj / prihod', $html);
    }

    public function test_agency_breakdown_separates_termini_and_daily_fee_limo_autobusi(): void
    {
        $user = User::factory()->create();
        [$drop, $pick] = $this->seedSlotPair();
        $limo = $this->createLimoPassengerType();
        $bus = $this->createMediumBusType();
        $date = '2026-08-06';

        $this->createReservation([
            'user_id' => $user->id,
            'merchant_transaction_id' => 'mt-ag-ts',
            'reservation_kind' => ReservationKind::TIME_SLOTS,
            'drop_off_time_slot_id' => $drop->id,
            'pick_up_time_slot_id' => $pick->id,
            'reservation_date' => $date,
            'user_name' => $user->name,
            'vehicle_type_id' => $bus->id,
            'email' => $user->email,
            'status' => 'paid',
            'invoice_amount' => '15.00',
        ]);

        $this->createReservation([
            'user_id' => $user->id,
            'merchant_transaction_id' => 'mt-ag-limo',
            'reservation_kind' => ReservationKind::DAILY_TICKET,
            'drop_off_time_slot_id' => null,
            'pick_up_time_slot_id' => null,
            'reservation_date' => $date,
            'user_name' => $user->name,
            'vehicle_type_id' => $limo->id,
            'email' => $user->email,
            'status' => 'paid',
            'invoice_amount' => '20.00',
        ]);

        $this->createReservation([
            'user_id' => $user->id,
            'merchant_transaction_id' => 'mt-ag-bus',
            'reservation_kind' => ReservationKind::DAILY_TICKET,
            'drop_off_time_slot_id' => null,
            'pick_up_time_slot_id' => null,
            'reservation_date' => $date,
            'user_name' => $user->name,
            'vehicle_type_id' => $bus->id,
            'email' => $user->email,
            'status' => 'paid',
            'invoice_amount' => '40.00',
        ]);

        $dataset = $this->buildDataset($date);
        $agency = collect($dataset['by_agency'])->firstWhere('user_id', $user->id);
        $this->assertNotNull($agency);
        $this->assertSame(1, $agency['time_slots_count']);
        $this->assertSame(15.0, $agency['time_slots_revenue']);
        $this->assertSame(1, $agency['daily_fee_limo_count']);
        $this->assertSame(20.0, $agency['daily_fee_limo_revenue']);
        $this->assertSame(1, $agency['daily_fee_buses_count']);
        $this->assertSame(40.0, $agency['daily_fee_buses_revenue']);
        $this->assertSame(2, $agency['daily_ticket_count']);
        $this->assertSame(60.0, $agency['daily_ticket_revenue']);
        $this->assertSame(2, $agency['occupied_slots']);
        $this->assertSame(75.0, $agency['revenue']);
    }
}
