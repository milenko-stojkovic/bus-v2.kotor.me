<?php

namespace Tests\Feature\AdminPanel;

use App\Models\ListOfTimeSlot;
use App\Models\Reservation;
use App\Models\User;
use App\Models\VehicleType;
use App\Services\AdminPanel\Analytics\AdminAnalyticsDefinitions;
use App\Services\AdminPanel\Analytics\AdminAnalyticsService;
use App\Support\ReservationKind;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

final class AdminAnalyticsDailyTicketTest extends TestCase
{
    use RefreshDatabase;

    private function buildDataset(string $date): array
    {
        return app(AdminAnalyticsService::class)->build($date, $date, false);
    }

    private function seedSlotPair(): array
    {
        $drop = ListOfTimeSlot::query()->create(['time_slot' => '10:00 - 10:20']);
        $pick = ListOfTimeSlot::query()->create(['time_slot' => '11:00 - 11:20']);

        return [$drop, $pick];
    }

    public function test_analytics_counts_time_slots_and_daily_ticket_separately(): void
    {
        [$drop, $pick] = $this->seedSlotPair();
        $vt = VehicleType::query()->create(['price' => 30]);
        $date = '2026-08-01';

        Reservation::query()->create([
            'merchant_transaction_id' => 'mt-ts-'.Str::random(4),
            'reservation_kind' => ReservationKind::TIME_SLOTS,
            'drop_off_time_slot_id' => $drop->id,
            'pick_up_time_slot_id' => $pick->id,
            'reservation_date' => $date,
            'user_name' => 'A',
            'country' => 'ME',
            'license_plate' => 'KO111AA',
            'vehicle_type_id' => $vt->id,
            'email' => 'a@test.local',
            'status' => 'paid',
            'invoice_amount' => '30.00',
            'email_sent' => Reservation::EMAIL_NOT_SENT,
        ]);

        Reservation::query()->create([
            'merchant_transaction_id' => 'mt-dt-'.Str::random(4),
            'reservation_kind' => ReservationKind::DAILY_TICKET,
            'drop_off_time_slot_id' => null,
            'pick_up_time_slot_id' => null,
            'reservation_date' => $date,
            'user_name' => 'B',
            'country' => 'ME',
            'license_plate' => 'KO222BB',
            'vehicle_type_id' => $vt->id,
            'email' => 'b@test.local',
            'status' => 'paid',
            'invoice_amount' => '45.00',
            'email_sent' => Reservation::EMAIL_NOT_SENT,
        ]);

        $dataset = $this->buildDataset($date);

        $this->assertSame(1, $dataset['kpi']['time_slots_count']);
        $this->assertSame(1, $dataset['kpi']['daily_ticket_count']);
        $this->assertSame(30.0, $dataset['kpi']['time_slots_revenue']);
        $this->assertSame(45.0, $dataset['kpi']['daily_ticket_revenue']);
        $this->assertSame(75.0, $dataset['kpi']['total_revenue']);
        $this->assertSame(75.0, $dataset['kpi']['revenue_reservations']);
    }

    public function test_daily_ticket_not_counted_as_occupied_slot(): void
    {
        [$drop, $pick] = $this->seedSlotPair();
        $vt = VehicleType::query()->create(['price' => 20]);
        $date = '2026-08-02';

        Reservation::query()->create([
            'merchant_transaction_id' => 'mt-dt-only',
            'reservation_kind' => ReservationKind::DAILY_TICKET,
            'drop_off_time_slot_id' => null,
            'pick_up_time_slot_id' => null,
            'reservation_date' => $date,
            'user_name' => 'Only Daily',
            'country' => 'ME',
            'license_plate' => 'KO333CC',
            'vehicle_type_id' => $vt->id,
            'email' => 'only@test.local',
            'status' => 'paid',
            'invoice_amount' => '20.00',
            'email_sent' => Reservation::EMAIL_NOT_SENT,
        ]);

        $dataset = $this->buildDataset($date);
        $this->assertSame(0, $dataset['kpi']['occupied_slots_total']);
    }

    public function test_time_slots_still_count_occupied_slots(): void
    {
        [$drop, $pick] = $this->seedSlotPair();
        $vt = VehicleType::query()->create(['price' => 20]);
        $date = '2026-08-03';

        Reservation::query()->create([
            'merchant_transaction_id' => 'mt-ts-only',
            'reservation_kind' => ReservationKind::TIME_SLOTS,
            'drop_off_time_slot_id' => $drop->id,
            'pick_up_time_slot_id' => $pick->id,
            'reservation_date' => $date,
            'user_name' => 'Slots',
            'country' => 'ME',
            'license_plate' => 'KO444DD',
            'vehicle_type_id' => $vt->id,
            'email' => 'slots@test.local',
            'status' => 'paid',
            'invoice_amount' => '20.00',
            'email_sent' => Reservation::EMAIL_NOT_SENT,
        ]);

        $dataset = $this->buildDataset($date);
        $this->assertSame(2, $dataset['kpi']['occupied_slots_total']);
    }

    public function test_daily_ticket_in_day_parts_row_not_slot_windows(): void
    {
        [$drop, $pick] = $this->seedSlotPair();
        $vt = VehicleType::query()->create(['price' => 10]);
        $date = '2026-08-04';

        Reservation::query()->create([
            'merchant_transaction_id' => 'mt-ts-dp',
            'reservation_kind' => ReservationKind::TIME_SLOTS,
            'drop_off_time_slot_id' => $drop->id,
            'pick_up_time_slot_id' => $pick->id,
            'reservation_date' => $date,
            'user_name' => 'TS',
            'country' => 'ME',
            'license_plate' => 'KO555EE',
            'vehicle_type_id' => $vt->id,
            'email' => 'ts@test.local',
            'status' => 'paid',
            'invoice_amount' => '10.00',
            'email_sent' => Reservation::EMAIL_NOT_SENT,
        ]);

        Reservation::query()->create([
            'merchant_transaction_id' => 'mt-dt-dp',
            'reservation_kind' => ReservationKind::DAILY_TICKET,
            'drop_off_time_slot_id' => null,
            'pick_up_time_slot_id' => null,
            'reservation_date' => $date,
            'user_name' => 'DT',
            'country' => 'ME',
            'license_plate' => 'KO666FF',
            'vehicle_type_id' => $vt->id,
            'email' => 'dt@test.local',
            'status' => 'paid',
            'invoice_amount' => '25.00',
            'email_sent' => Reservation::EMAIL_NOT_SENT,
        ]);

        $dataset = $this->buildDataset($date);
        $dayParts = collect($dataset['day_parts']);
        $dailyRow = $dayParts->firstWhere('key', AdminAnalyticsDefinitions::PART_DAILY_TICKET);
        $this->assertNotNull($dailyRow);
        $this->assertSame(1, $dailyRow['reservations']);
        $this->assertSame(0, $dailyRow['occupied_slots']);
        $this->assertSame(25.0, $dailyRow['revenue']);

        $slotWindowReservations = $dayParts
            ->where('is_daily_ticket', false)
            ->sum('reservations');
        $this->assertSame(1, $slotWindowReservations);
    }

    public function test_double_paid_same_slot_pairs_ignore_daily_ticket(): void
    {
        [$drop, $pick] = $this->seedSlotPair();
        $vt = VehicleType::query()->create(['price' => 10]);
        $date = '2026-08-05';
        $plate = 'KO777GG';

        foreach (['mt-dp-1', 'mt-dp-2'] as $mtid) {
            Reservation::query()->create([
                'merchant_transaction_id' => $mtid,
                'reservation_kind' => ReservationKind::DAILY_TICKET,
                'drop_off_time_slot_id' => null,
                'pick_up_time_slot_id' => null,
                'reservation_date' => $date,
                'user_name' => 'DT',
                'country' => 'ME',
                'license_plate' => $plate,
                'vehicle_type_id' => $vt->id,
                'email' => 'dt2@test.local',
                'status' => 'paid',
                'invoice_amount' => '10.00',
                'email_sent' => Reservation::EMAIL_NOT_SENT,
            ]);
        }

        Reservation::query()->create([
            'merchant_transaction_id' => 'mt-ts-a',
            'reservation_kind' => ReservationKind::TIME_SLOTS,
            'drop_off_time_slot_id' => $drop->id,
            'pick_up_time_slot_id' => $pick->id,
            'reservation_date' => $date,
            'user_name' => 'TS A',
            'country' => 'ME',
            'license_plate' => $plate,
            'vehicle_type_id' => $vt->id,
            'email' => 'tsa@test.local',
            'status' => 'paid',
            'invoice_amount' => '10.00',
            'email_sent' => Reservation::EMAIL_NOT_SENT,
        ]);

        Reservation::query()->create([
            'merchant_transaction_id' => 'mt-ts-b',
            'reservation_kind' => ReservationKind::TIME_SLOTS,
            'drop_off_time_slot_id' => $drop->id,
            'pick_up_time_slot_id' => $pick->id,
            'reservation_date' => $date,
            'user_name' => 'TS B',
            'country' => 'ME',
            'license_plate' => $plate,
            'vehicle_type_id' => $vt->id,
            'email' => 'tsb@test.local',
            'status' => 'paid',
            'invoice_amount' => '10.00',
            'email_sent' => Reservation::EMAIL_NOT_SENT,
        ]);

        $dataset = $this->buildDataset($date);
        $this->assertSame(1, $dataset['ops']['double_paid_same_slot_pairs']);
    }

    public function test_agency_breakdown_includes_daily_ticket_metrics(): void
    {
        $user = User::factory()->create();
        [$drop, $pick] = $this->seedSlotPair();
        $vt = VehicleType::query()->create(['price' => 15]);
        $date = '2026-08-06';

        Reservation::query()->create([
            'user_id' => $user->id,
            'merchant_transaction_id' => 'mt-ag-ts',
            'reservation_kind' => ReservationKind::TIME_SLOTS,
            'drop_off_time_slot_id' => $drop->id,
            'pick_up_time_slot_id' => $pick->id,
            'reservation_date' => $date,
            'user_name' => $user->name,
            'country' => 'ME',
            'license_plate' => 'KO888HH',
            'vehicle_type_id' => $vt->id,
            'email' => $user->email,
            'status' => 'paid',
            'invoice_amount' => '15.00',
            'email_sent' => Reservation::EMAIL_NOT_SENT,
        ]);

        Reservation::query()->create([
            'user_id' => $user->id,
            'merchant_transaction_id' => 'mt-ag-dt',
            'reservation_kind' => ReservationKind::DAILY_TICKET,
            'drop_off_time_slot_id' => null,
            'pick_up_time_slot_id' => null,
            'reservation_date' => $date,
            'user_name' => $user->name,
            'country' => 'ME',
            'license_plate' => 'KO999II',
            'vehicle_type_id' => $vt->id,
            'email' => $user->email,
            'status' => 'paid',
            'invoice_amount' => '40.00',
            'email_sent' => Reservation::EMAIL_NOT_SENT,
        ]);

        $dataset = $this->buildDataset($date);
        $agency = collect($dataset['by_agency'])->firstWhere('user_id', $user->id);
        $this->assertNotNull($agency);
        $this->assertSame(1, $agency['daily_ticket_count']);
        $this->assertSame(40.0, $agency['daily_ticket_revenue']);
        $this->assertSame(2, $agency['occupied_slots']);
        $this->assertSame(55.0, $agency['revenue']);
    }
}
