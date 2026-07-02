<?php

namespace Tests\Feature\AdminPanel;

use App\Models\Admin;
use App\Models\ListOfTimeSlot;
use App\Models\Reservation;
use App\Models\User;
use App\Models\Vehicle;
use App\Models\VehicleType;
use App\Services\AdminPanel\Agency\AgencyV1HistoricalEstimateService;
use App\Services\AdminPanel\Agency\AgencyV2ReservationStatisticsService;
use App\Support\AgencyHeuristicConfidence;
use App\Support\ReservationKind;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

final class AdminAgencyReservationStatisticsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'agency_statistics.v1_cutover_at' => '2099-01-01 00:00:00',
            'agency_statistics.heuristic_cache_ttl' => 0,
        ]);
        Cache::flush();
    }

    private function seedAdmin(): Admin
    {
        return Admin::query()->create([
            'username' => 'statsadmin',
            'email' => 'stats-admin@example.com',
            'password' => bcrypt('secret-password-stats'),
            'control_access' => false,
            'admin_access' => true,
        ]);
    }

    private function seedSlot(): ListOfTimeSlot
    {
        return ListOfTimeSlot::query()->create(['time_slot' => '10:00 - 10:20']);
    }

    /**
     * @param  array<string, mixed>  $overrides
     */
    private function createReservation(array $overrides): Reservation
    {
        $slot = $this->seedSlot();
        $vt = VehicleType::query()->first() ?? VehicleType::query()->create(['price' => 20]);

        return Reservation::query()->create(array_merge([
            'user_name' => 'Test User',
            'country' => 'ME',
            'license_plate' => 'KO'.Str::upper(Str::random(4)).'AA',
            'email' => 'guest@example.com',
            'vehicle_type_id' => $vt->id,
            'drop_off_time_slot_id' => $slot->id,
            'pick_up_time_slot_id' => $slot->id,
            'reservation_date' => now()->toDateString(),
            'status' => 'paid',
            'invoice_amount' => '20.00',
            'reservation_kind' => ReservationKind::TIME_SLOTS,
            'email_sent' => Reservation::EMAIL_NOT_SENT,
            'created_by_admin' => false,
            'created_at' => now()->subYear(),
            'updated_at' => now()->subYear(),
        ], $overrides));
    }

    public function test_a_v2_statistics_calculated_correctly_for_current_year(): void
    {
        $agency = User::factory()->create(['email' => 'agency@montetravel.me', 'name' => 'Monte Travel']);
        $slot = $this->seedSlot();
        $vt = VehicleType::query()->create(['price' => 15]);
        $year = (int) now()->year;

        Reservation::query()->create([
            'user_id' => $agency->id,
            'vehicle_type_id' => $vt->id,
            'drop_off_time_slot_id' => $slot->id,
            'pick_up_time_slot_id' => $slot->id,
            'reservation_date' => sprintf('%d-03-15', $year),
            'user_name' => $agency->name,
            'country' => 'ME',
            'license_plate' => 'KO111AA',
            'email' => $agency->email,
            'status' => 'paid',
            'invoice_amount' => '15.00',
            'reservation_kind' => ReservationKind::TIME_SLOTS,
            'email_sent' => 0,
            'created_by_admin' => false,
        ]);
        Reservation::query()->create([
            'user_id' => $agency->id,
            'vehicle_type_id' => $vt->id,
            'reservation_date' => sprintf('%d-06-01', $year),
            'drop_off_time_slot_id' => null,
            'pick_up_time_slot_id' => null,
            'user_name' => $agency->name,
            'country' => 'ME',
            'license_plate' => 'KO222BB',
            'email' => $agency->email,
            'status' => 'paid',
            'invoice_amount' => '50.00',
            'reservation_kind' => ReservationKind::DAILY_TICKET,
            'email_sent' => 0,
            'created_by_admin' => false,
        ]);
        Reservation::query()->create([
            'user_id' => $agency->id,
            'vehicle_type_id' => $vt->id,
            'drop_off_time_slot_id' => $slot->id,
            'pick_up_time_slot_id' => $slot->id,
            'reservation_date' => sprintf('%d-08-10', $year),
            'user_name' => $agency->name,
            'country' => 'ME',
            'license_plate' => 'KO111AA',
            'email' => $agency->email,
            'status' => 'free',
            'invoice_amount' => null,
            'reservation_kind' => ReservationKind::TIME_SLOTS,
            'email_sent' => 0,
            'created_by_admin' => false,
        ]);
        // Prior year — must not count
        Reservation::query()->create([
            'user_id' => $agency->id,
            'vehicle_type_id' => $vt->id,
            'drop_off_time_slot_id' => $slot->id,
            'pick_up_time_slot_id' => $slot->id,
            'reservation_date' => sprintf('%d-01-01', $year - 1),
            'user_name' => $agency->name,
            'country' => 'ME',
            'license_plate' => 'KO999ZZ',
            'email' => $agency->email,
            'status' => 'paid',
            'invoice_amount' => '99.00',
            'reservation_kind' => ReservationKind::TIME_SLOTS,
            'email_sent' => 0,
            'created_by_admin' => false,
        ]);

        Vehicle::query()->create([
            'user_id' => $agency->id,
            'license_plate' => 'KO111AA',
            'vehicle_type_id' => $vt->id,
            'status' => Vehicle::STATUS_ACTIVE,
        ]);

        $stats = app(AgencyV2ReservationStatisticsService::class)->compute($agency);

        $this->assertSame(3, $stats['total_reservations']);
        $this->assertSame(2, $stats['paid_reservations']);
        $this->assertSame(1, $stats['free_reservations']);
        $this->assertSame(2, $stats['time_slots_count']);
        $this->assertSame(1, $stats['daily_ticket_count']);
        $this->assertSame(65.0, $stats['total_paid_amount']);
        $this->assertSame(15.0, $stats['time_slots_paid_amount']);
        $this->assertSame(50.0, $stats['daily_ticket_paid_amount']);
        $this->assertSame(2, $stats['distinct_license_plates']);
        $this->assertSame(1, $stats['active_vehicles']);
        $this->assertSame(sprintf('%d-03-15', $year), $stats['first_reservation_date']);
        $this->assertSame(sprintf('%d-08-10', $year), $stats['last_reservation_date']);
    }

    public function test_b_guest_reservations_not_in_v2_official_only_in_heuristic_when_matched(): void
    {
        $agency = User::factory()->create(['email' => 'info@montetravel.me', 'name' => 'Monte Travel']);

        $guestUnmatched = $this->createReservation([
            'user_id' => null,
            'email' => 'random@other.test',
            'license_plate' => 'KO000XX',
        ]);

        $guestMatched = $this->createReservation([
            'user_id' => null,
            'email' => 'info@montetravel.me',
            'license_plate' => 'KO111AA',
        ]);

        $v2 = app(AgencyV2ReservationStatisticsService::class)->compute($agency);
        $v1 = app(AgencyV1HistoricalEstimateService::class)->compute($agency);

        $this->assertSame(0, $v2['total_reservations']);
        $this->assertSame(1, $v1['linked_total']);
        $this->assertContains($guestMatched->id, array_column($v1['linked_reservations'], 'id'));
        $this->assertNotContains($guestUnmatched->id, array_column($v1['linked_reservations'], 'id'));
    }

    public function test_c_email_exact_match_gives_high_confidence(): void
    {
        $agency = User::factory()->create(['email' => 'info@montetravel.me']);

        $this->createReservation([
            'user_id' => null,
            'email' => 'info@montetravel.me',
            'license_plate' => 'KO333CC',
        ]);

        $v1 = app(AgencyV1HistoricalEstimateService::class)->compute($agency);
        $row = $v1['linked_reservations'][0] ?? null;

        $this->assertNotNull($row);
        $this->assertSame(AgencyHeuristicConfidence::HIGH, $row['confidence']);
        $this->assertSame('Email match', $row['matching_reason']);
    }

    public function test_gmail_exact_match_is_high_confidence(): void
    {
        $agency = User::factory()->create(['email' => 'dubrovniksmiley@gmail.com', 'name' => 'DUBROVNIK SMILE']);

        $matched = $this->createReservation([
            'user_id' => null,
            'email' => 'dubrovniksmiley@gmail.com',
            'license_plate' => 'KOGL01',
            'country' => 'HR',
        ]);

        $v1 = app(AgencyV1HistoricalEstimateService::class)->compute($agency);

        $this->assertSame(1, $v1['linked_total']);
        $this->assertContains($matched->id, array_column($v1['linked_reservations'], 'id'));
        $row = $v1['linked_reservations'][0] ?? null;
        $this->assertNotNull($row);
        $this->assertSame(AgencyHeuristicConfidence::HIGH, $row['confidence']);
        $this->assertSame('Email match', $row['matching_reason']);
    }

    public function test_gmail_domain_match_is_ignored_and_excluded(): void
    {
        $agency = User::factory()->create(['email' => 'dubrovniksmiley@gmail.com', 'name' => 'DUBROVNIK SMILE']);

        $this->createReservation([
            'user_id' => null,
            'email' => 'somethingelse@gmail.com',
            'license_plate' => 'KONOMATCH',
            'country' => 'ME',
        ]);

        $v1 = app(AgencyV1HistoricalEstimateService::class)->compute($agency);

        $this->assertSame(0, $v1['linked_total']);
    }

    public function test_d_domain_match_gives_medium_confidence(): void
    {
        $agency = User::factory()->create(['email' => 'info@montetravel.me']);

        $this->createReservation([
            'user_id' => null,
            'email' => 'booking@montetravel.me',
            'license_plate' => 'KO444DD',
        ]);

        $v1 = app(AgencyV1HistoricalEstimateService::class)->compute($agency);
        $row = $v1['linked_reservations'][0] ?? null;

        $this->assertNotNull($row);
        $this->assertSame(AgencyHeuristicConfidence::MEDIUM, $row['confidence']);
        $this->assertSame('Email domain', $row['matching_reason']);
    }

    public function test_public_domain_is_not_used_in_sql_candidate_prefilter(): void
    {
        $agency = User::factory()->create(['email' => 'dubrovniksmiley@gmail.com', 'name' => 'DUBROVNIK SMILE']);

        DB::flushQueryLog();
        DB::enableQueryLog();

        app(AgencyV1HistoricalEstimateService::class)->compute($agency);

        $queries = DB::getQueryLog();
        $sql = implode("\n", array_map(static fn (array $q) => (string) ($q['query'] ?? ''), $queries));

        $this->assertStringNotContainsString('%@gmail.com', strtolower($sql));
        $this->assertStringNotContainsString('%@googlemail.com', strtolower($sql));
    }

    public function test_multiple_signals_does_not_count_public_domain_match(): void
    {
        $agency = User::factory()->create(['email' => 'dubrovniksmiley@gmail.com', 'name' => 'DUBROVNIK SMILE']);
        $slot = $this->seedSlot();
        $vt = VehicleType::query()->create(['price' => 15]);

        // Seed repeated plate in V2 for this agency (medium signal).
        Reservation::query()->create([
            'user_id' => $agency->id,
            'vehicle_type_id' => $vt->id,
            'drop_off_time_slot_id' => $slot->id,
            'pick_up_time_slot_id' => $slot->id,
            'reservation_date' => now()->toDateString(),
            'user_name' => $agency->name,
            'country' => 'ME',
            'license_plate' => 'KOREP01',
            'email' => $agency->email,
            'status' => 'paid',
            'invoice_amount' => '15.00',
            'reservation_kind' => ReservationKind::TIME_SLOTS,
            'email_sent' => 0,
            'created_by_admin' => false,
        ]);
        Reservation::query()->create([
            'user_id' => $agency->id,
            'vehicle_type_id' => $vt->id,
            'drop_off_time_slot_id' => $slot->id,
            'pick_up_time_slot_id' => $slot->id,
            'reservation_date' => now()->toDateString(),
            'user_name' => $agency->name,
            'country' => 'ME',
            'license_plate' => 'KOREP01',
            'email' => $agency->email,
            'status' => 'paid',
            'invoice_amount' => '15.00',
            'reservation_kind' => ReservationKind::TIME_SLOTS,
            'email_sent' => 0,
            'created_by_admin' => false,
        ]);

        // Candidate with same plate but unrelated gmail address must not get boosted by domain.
        $this->createReservation([
            'user_id' => null,
            'email' => 'unrelated@gmail.com',
            'license_plate' => 'KOREP01',
            'country' => 'ME',
        ]);

        $v1 = app(AgencyV1HistoricalEstimateService::class)->compute($agency);
        $row = $v1['linked_reservations'][0] ?? null;

        $this->assertNotNull($row);
        $this->assertSame(AgencyHeuristicConfidence::MEDIUM, $row['confidence']);
        $this->assertSame('Repeated plate in V2', $row['matching_reason']);
    }

    public function test_dubrovnik_smile_like_case_does_not_return_unrelated_gmail_reservations(): void
    {
        $agency = User::factory()->create(['email' => 'dubrovniksmiley@gmail.com', 'name' => 'DUBROVNIK SMILE']);

        // Many unrelated gmail reservations (different plates / countries).
        foreach (range(1, 20) as $i) {
            $this->createReservation([
                'user_id' => null,
                'email' => 'random'.$i.'@gmail.com',
                'license_plate' => 'KOX'.$i.'AA',
                'country' => $i % 2 === 0 ? 'ME' : 'HR',
            ]);
        }

        $v1 = app(AgencyV1HistoricalEstimateService::class)->compute($agency);
        $this->assertSame(0, $v1['linked_total']);
    }

    public function test_e_license_plate_match_on_active_vehicle_gives_high_confidence(): void
    {
        $agency = User::factory()->create(['email' => 'agency@other.test']);
        $vt = VehicleType::query()->create(['price' => 20]);

        Vehicle::query()->create([
            'user_id' => $agency->id,
            'license_plate' => 'KO555EE',
            'vehicle_type_id' => $vt->id,
            'status' => Vehicle::STATUS_ACTIVE,
        ]);

        $this->createReservation([
            'user_id' => null,
            'email' => 'unknown@freemail.com',
            'license_plate' => 'KO555EE',
        ]);

        $v1 = app(AgencyV1HistoricalEstimateService::class)->compute($agency);
        $row = $v1['linked_reservations'][0] ?? null;

        $this->assertNotNull($row);
        $this->assertSame(AgencyHeuristicConfidence::HIGH, $row['confidence']);
        $this->assertSame('Known vehicle', $row['matching_reason']);
    }

    public function test_exact_email_still_matches_even_if_country_differs(): void
    {
        $agency = User::factory()->create(['email' => 'info@montetravel.me', 'country' => 'ME', 'name' => 'Monte Travel']);

        $this->createReservation([
            'user_id' => null,
            'email' => 'info@montetravel.me',
            'license_plate' => 'KOCNTRY1',
            'country' => 'HR',
        ]);

        $v1 = app(AgencyV1HistoricalEstimateService::class)->compute($agency);
        $row = $v1['linked_reservations'][0] ?? null;
        $this->assertNotNull($row);
        $this->assertSame(AgencyHeuristicConfidence::HIGH, $row['confidence']);
        $this->assertSame('Email match', $row['matching_reason']);
    }

    public function test_domain_match_with_country_mismatch_does_not_upgrade_to_high(): void
    {
        $agency = User::factory()->create(['email' => 'info@montetravel.me', 'country' => 'ME', 'name' => 'Monte Travel DOO']);

        $this->createReservation([
            'user_id' => null,
            'email' => 'booking@montetravel.me', // domain match (medium)
            'user_name' => 'Monte Travel doo',   // name similarity (low)
            'license_plate' => 'KOCNTRY2',
            'country' => 'HR',
        ]);

        $v1 = app(AgencyV1HistoricalEstimateService::class)->compute($agency);
        $row = $v1['linked_reservations'][0] ?? null;

        $this->assertNotNull($row);
        $this->assertSame(AgencyHeuristicConfidence::MEDIUM, $row['confidence']);
        $this->assertSame('Multiple signals', $row['matching_reason']);
    }

    public function test_f_no_persisted_agency_assignment_on_page_load(): void
    {
        $admin = $this->seedAdmin();
        $this->actingAs($admin, 'panel_admin');

        $agency = User::factory()->create(['email' => 'info@montetravel.me']);

        $historical = $this->createReservation([
            'user_id' => null,
            'email' => 'info@montetravel.me',
        ]);

        $this->get(route('panel_admin.agencies.show', $agency, false))->assertOk();

        $historical->refresh();
        $this->assertNull($historical->user_id);
    }

    public function test_g_official_v2_statistics_unchanged_by_heuristic_candidates(): void
    {
        $agency = User::factory()->create(['email' => 'info@montetravel.me']);
        $slot = $this->seedSlot();
        $vt = VehicleType::query()->create(['price' => 30]);
        $year = (int) now()->year;

        Reservation::query()->create([
            'user_id' => $agency->id,
            'vehicle_type_id' => $vt->id,
            'drop_off_time_slot_id' => $slot->id,
            'pick_up_time_slot_id' => $slot->id,
            'reservation_date' => sprintf('%d-04-01', $year),
            'user_name' => $agency->name,
            'country' => 'ME',
            'license_plate' => 'KO777GG',
            'email' => $agency->email,
            'status' => 'paid',
            'invoice_amount' => '30.00',
            'reservation_kind' => ReservationKind::TIME_SLOTS,
            'email_sent' => 0,
            'created_by_admin' => false,
        ]);

        $v2Before = app(AgencyV2ReservationStatisticsService::class)->compute($agency);

        $this->createReservation([
            'user_id' => null,
            'email' => 'info@montetravel.me',
            'license_plate' => 'KO888HH',
        ]);

        app(AgencyV1HistoricalEstimateService::class)->compute($agency);

        $v2After = app(AgencyV2ReservationStatisticsService::class)->compute($agency);

        $this->assertSame($v2Before, $v2After);
        $this->assertSame(1, $v2After['total_reservations']);
    }

    public function test_h_estimated_table_sorts_correctly_by_confidence(): void
    {
        $agency = User::factory()->create(['email' => 'info@montetravel.me', 'name' => 'Monte Travel']);
        $vt = VehicleType::query()->create(['price' => 20]);

        Vehicle::query()->create([
            'user_id' => $agency->id,
            'license_plate' => 'KOPLATE1',
            'vehicle_type_id' => $vt->id,
            'status' => Vehicle::STATUS_ACTIVE,
        ]);

        $low = $this->createReservation([
            'user_id' => null,
            'email' => 'booking@montetravel.me',
            'license_plate' => 'KOLOW01',
            'reservation_date' => '2024-01-01',
        ]);

        $high = $this->createReservation([
            'user_id' => null,
            'email' => 'info@montetravel.me',
            'license_plate' => 'KOPLATE1',
            'reservation_date' => '2024-06-01',
        ]);

        $service = app(AgencyV1HistoricalEstimateService::class);
        $sorted = $service->compute($agency, 'confidence');

        $ids = array_column($sorted['linked_reservations'], 'id');
        $this->assertSame([$high->id, $low->id], $ids);
        $this->assertSame(AgencyHeuristicConfidence::HIGH, $sorted['linked_reservations'][0]['confidence']);
        $this->assertSame(AgencyHeuristicConfidence::MEDIUM, $sorted['linked_reservations'][1]['confidence']);
    }

    public function test_agency_detail_page_renders_reservation_statistics_section(): void
    {
        $admin = $this->seedAdmin();
        $this->actingAs($admin, 'panel_admin');

        config(['features.advance_payments' => false]);

        $agency = User::factory()->create(['name' => 'Stats Agency']);

        $this->get(route('panel_admin.agencies.show', $agency, false))
            ->assertOk()
            ->assertSee('Statistika rezervacija', false)
            ->assertSee('Službena V2 statistika', false)
            ->assertSee('Aktivnost agencije', false)
            ->assertSee('Procijenjena V1 istorija', false)
            ->assertSee('Procijenjena istorijska aktivnost', false)
            ->assertSee('Sažetak pouzdanosti', false)
            ->assertSee('heurističkim uparivanjem', false);
    }

    public function test_post_cutover_guest_reservations_excluded_from_v1_pool(): void
    {
        config(['agency_statistics.v1_cutover_at' => '2020-01-01 00:00:00']);

        $agency = User::factory()->create(['email' => 'info@montetravel.me']);

        $this->createReservation([
            'user_id' => null,
            'email' => 'info@montetravel.me',
            'created_at' => Carbon::parse('2025-06-01'),
            'updated_at' => Carbon::parse('2025-06-01'),
        ]);

        $v1 = app(AgencyV1HistoricalEstimateService::class)->compute($agency);

        $this->assertSame(0, $v1['linked_total']);
    }

    public function test_country_column_is_rendered_in_estimated_table(): void
    {
        $admin = $this->seedAdmin();
        $this->actingAs($admin, 'panel_admin');

        $agency = User::factory()->create(['email' => 'info@montetravel.me']);

        $this->createReservation([
            'user_id' => null,
            'email' => 'info@montetravel.me',
            'country' => 'HR',
        ]);

        $this->get(route('panel_admin.agencies.show', $agency, false))
            ->assertOk()
            ->assertSee('Država', false)
            ->assertSee('HR', false);
    }

    public function test_official_first_reservation_is_correct(): void
    {
        $agency = User::factory()->create();
        $slot = $this->seedSlot();
        $vt = VehicleType::query()->create(['price' => 20]);
        $year = (int) now()->year;

        Reservation::query()->create([
            'user_id' => $agency->id,
            'vehicle_type_id' => $vt->id,
            'drop_off_time_slot_id' => $slot->id,
            'pick_up_time_slot_id' => $slot->id,
            'reservation_date' => sprintf('%d-05-10', $year),
            'user_name' => $agency->name,
            'country' => 'ME',
            'license_plate' => 'KO100AA',
            'email' => $agency->email,
            'status' => 'paid',
            'invoice_amount' => '20.00',
            'reservation_kind' => ReservationKind::TIME_SLOTS,
            'email_sent' => 0,
            'created_by_admin' => false,
        ]);
        Reservation::query()->create([
            'user_id' => $agency->id,
            'vehicle_type_id' => $vt->id,
            'drop_off_time_slot_id' => $slot->id,
            'pick_up_time_slot_id' => $slot->id,
            'reservation_date' => sprintf('%d-09-20', $year),
            'user_name' => $agency->name,
            'country' => 'ME',
            'license_plate' => 'KO101BB',
            'email' => $agency->email,
            'status' => 'paid',
            'invoice_amount' => '20.00',
            'reservation_kind' => ReservationKind::TIME_SLOTS,
            'email_sent' => 0,
            'created_by_admin' => false,
        ]);

        $stats = app(AgencyV2ReservationStatisticsService::class)->compute($agency);

        $this->assertSame(sprintf('%d-05-10', $year), $stats['first_reservation_date']);
    }

    public function test_official_last_reservation_is_correct(): void
    {
        $agency = User::factory()->create();
        $slot = $this->seedSlot();
        $vt = VehicleType::query()->create(['price' => 20]);
        $year = (int) now()->year;

        Reservation::query()->create([
            'user_id' => $agency->id,
            'vehicle_type_id' => $vt->id,
            'drop_off_time_slot_id' => $slot->id,
            'pick_up_time_slot_id' => $slot->id,
            'reservation_date' => sprintf('%d-02-01', $year),
            'user_name' => $agency->name,
            'country' => 'ME',
            'license_plate' => 'KO200AA',
            'email' => $agency->email,
            'status' => 'paid',
            'invoice_amount' => '20.00',
            'reservation_kind' => ReservationKind::TIME_SLOTS,
            'email_sent' => 0,
            'created_by_admin' => false,
        ]);
        Reservation::query()->create([
            'user_id' => $agency->id,
            'vehicle_type_id' => $vt->id,
            'drop_off_time_slot_id' => $slot->id,
            'pick_up_time_slot_id' => $slot->id,
            'reservation_date' => sprintf('%d-11-30', $year),
            'user_name' => $agency->name,
            'country' => 'ME',
            'license_plate' => 'KO201BB',
            'email' => $agency->email,
            'status' => 'paid',
            'invoice_amount' => '20.00',
            'reservation_kind' => ReservationKind::TIME_SLOTS,
            'email_sent' => 0,
            'created_by_admin' => false,
        ]);

        $stats = app(AgencyV2ReservationStatisticsService::class)->compute($agency);

        $this->assertSame(sprintf('%d-11-30', $year), $stats['last_reservation_date']);
    }

    public function test_estimated_first_reservation_uses_heuristic_matches_only(): void
    {
        $agency = User::factory()->create(['email' => 'info@montetravel.me']);

        $this->createReservation([
            'user_id' => null,
            'email' => 'nobody@other.test',
            'license_plate' => 'KO999XX',
            'reservation_date' => '2018-01-01',
        ]);

        $this->createReservation([
            'user_id' => null,
            'email' => 'info@montetravel.me',
            'license_plate' => 'KO111AA',
            'reservation_date' => '2020-03-15',
        ]);

        $v1 = app(AgencyV1HistoricalEstimateService::class)->compute($agency);

        $this->assertSame('2020-03-15', $v1['estimated_first_reservation']);
        $this->assertSame(1, $v1['linked_total']);
    }

    public function test_estimated_last_reservation_uses_heuristic_matches_only(): void
    {
        $agency = User::factory()->create(['email' => 'info@montetravel.me']);

        $this->createReservation([
            'user_id' => null,
            'email' => 'nobody@other.test',
            'license_plate' => 'KO888XX',
            'reservation_date' => '2025-12-31',
        ]);

        $this->createReservation([
            'user_id' => null,
            'email' => 'info@montetravel.me',
            'license_plate' => 'KO222BB',
            'reservation_date' => '2019-07-01',
        ]);
        $this->createReservation([
            'user_id' => null,
            'email' => 'booking@montetravel.me',
            'license_plate' => 'KO333CC',
            'reservation_date' => '2021-08-20',
        ]);

        $v1 = app(AgencyV1HistoricalEstimateService::class)->compute($agency);

        $this->assertSame('2021-08-20', $v1['estimated_last_reservation']);
        $this->assertSame(2, $v1['linked_total']);
    }

    public function test_confidence_counts_are_correct(): void
    {
        $agency = User::factory()->create(['email' => 'info@montetravel.me', 'name' => 'Monte Travel DOO']);
        $slot = $this->seedSlot();
        $vt = VehicleType::query()->create(['price' => 20]);

        Reservation::query()->create([
            'user_id' => $agency->id,
            'vehicle_type_id' => $vt->id,
            'drop_off_time_slot_id' => $slot->id,
            'pick_up_time_slot_id' => $slot->id,
            'reservation_date' => now()->toDateString(),
            'user_name' => $agency->name,
            'country' => 'ME',
            'license_plate' => 'KOREP01',
            'email' => $agency->email,
            'status' => 'paid',
            'invoice_amount' => '20.00',
            'reservation_kind' => ReservationKind::TIME_SLOTS,
            'email_sent' => 0,
            'created_by_admin' => false,
        ]);
        Reservation::query()->create([
            'user_id' => $agency->id,
            'vehicle_type_id' => $vt->id,
            'drop_off_time_slot_id' => $slot->id,
            'pick_up_time_slot_id' => $slot->id,
            'reservation_date' => now()->toDateString(),
            'user_name' => $agency->name,
            'country' => 'ME',
            'license_plate' => 'KOREP01',
            'email' => $agency->email,
            'status' => 'paid',
            'invoice_amount' => '20.00',
            'reservation_kind' => ReservationKind::TIME_SLOTS,
            'email_sent' => 0,
            'created_by_admin' => false,
        ]);

        $this->createReservation([
            'user_id' => null,
            'email' => 'info@montetravel.me',
            'license_plate' => 'KO111AA',
        ]);

        $this->createReservation([
            'user_id' => null,
            'email' => 'booking@montetravel.me',
            'license_plate' => 'KO222BB',
        ]);

        $this->createReservation([
            'user_id' => null,
            'email' => 'other@freemail.com',
            'license_plate' => 'KOREP01',
            'user_name' => 'Unrelated Guest',
        ]);

        $v1 = app(AgencyV1HistoricalEstimateService::class)->compute($agency);

        $this->assertSame(3, $v1['linked_total']);
        $this->assertSame(1, $v1['high_confidence']);
        $this->assertSame(2, $v1['medium_confidence']);
        $this->assertSame(0, $v1['low_confidence']);
    }

    public function test_no_reservations_shows_dash_for_activity_dates(): void
    {
        $admin = $this->seedAdmin();
        $this->actingAs($admin, 'panel_admin');

        $agency = User::factory()->create(['name' => 'Empty Agency']);

        $stats = app(AgencyV2ReservationStatisticsService::class)->compute($agency);
        $v1 = app(AgencyV1HistoricalEstimateService::class)->compute($agency);

        $this->assertNull($stats['first_reservation_date']);
        $this->assertNull($stats['last_reservation_date']);
        $this->assertNull($v1['estimated_first_reservation']);
        $this->assertNull($v1['estimated_last_reservation']);

        $response = $this->get(route('panel_admin.agencies.show', $agency, false));
        $response->assertOk();

        $html = $response->getContent();
        $this->assertIsString($html);
        $this->assertGreaterThanOrEqual(4, substr_count($html, '—'));
    }
}
