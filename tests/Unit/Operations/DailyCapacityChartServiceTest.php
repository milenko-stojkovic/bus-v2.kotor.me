<?php

namespace Tests\Unit\Operations;

use App\Models\DailyParkingData;
use App\Models\ListOfTimeSlot;
use App\Models\Reservation;
use App\Models\SystemConfig;
use App\Models\VehicleType;
use App\Services\Operations\DailyCapacityChartService;
use App\Support\ReservationKind;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

final class DailyCapacityChartServiceTest extends TestCase
{
    use RefreshDatabase;

    public function test_service_returns_slots_1_to_41_and_preserves_over_capacity_totals(): void
    {
        for ($i = 1; $i <= 41; $i++) {
            ListOfTimeSlot::query()->create(['time_slot' => 'T'.$i]);
        }

        // Capacity fallback: if missing or 0 -> 9
        SystemConfig::setValue('available_parking_slots', 0);

        $svc = new DailyCapacityChartService();
        $day = Carbon::parse('2026-04-26')->startOfDay();

        $slot1 = ListOfTimeSlot::query()->orderBy('id')->firstOrFail();
        DailyParkingData::query()->create([
            'date' => $day->toDateString(),
            'time_slot_id' => $slot1->id,
            'capacity' => 9,
            'reserved' => 10,
            'pending' => 2,
            'is_blocked' => false,
        ]);

        $data = $svc->forDate($day);

        $this->assertSame('2026-04-26', $data['date']);
        $this->assertSame(9, (int) $data['capacity']);
        $this->assertCount(41, $data['slots']);
        $this->assertSame(1, $data['slots'][0]['slot_number']);
        $this->assertSame(41, $data['slots'][40]['slot_number']);

        $this->assertSame(10, $data['slots'][0]['reserved']);
        $this->assertSame(2, $data['slots'][0]['pending']);
        $this->assertSame(12, $data['slots'][0]['total']);
        $this->assertSame(0, $data['meta']['reservations_total']);
    }

    public function test_service_counts_paid_and_free_time_slots_reservations_for_the_day(): void
    {
        for ($i = 1; $i <= 2; $i++) {
            ListOfTimeSlot::query()->create(['time_slot' => 'T'.$i]);
        }

        $vt = VehicleType::query()->create(['price' => 10]);
        $slot1 = ListOfTimeSlot::query()->orderBy('id')->firstOrFail();
        $slot2 = ListOfTimeSlot::query()->orderBy('id')->skip(1)->firstOrFail();
        $day = Carbon::parse('2026-04-26')->startOfDay();
        $date = $day->toDateString();

        $base = [
            'drop_off_time_slot_id' => $slot1->id,
            'pick_up_time_slot_id' => $slot2->id,
            'reservation_date' => $date,
            'user_name' => 'Test',
            'country' => 'ME',
            'license_plate' => 'KO1',
            'vehicle_type_id' => $vt->id,
            'email' => 'test@example.com',
            'email_sent' => Reservation::EMAIL_NOT_SENT,
            'reservation_kind' => ReservationKind::TIME_SLOTS,
        ];

        Reservation::query()->create(array_merge($base, [
            'merchant_transaction_id' => 'mt-paid-1',
            'status' => 'paid',
            'invoice_amount' => '10.00',
        ]));
        Reservation::query()->create(array_merge($base, [
            'merchant_transaction_id' => 'mt-free-1',
            'status' => 'free',
            'invoice_amount' => '0.00',
        ]));
        Reservation::query()->create(array_merge($base, [
            'merchant_transaction_id' => 'mt-daily-1',
            'status' => 'paid',
            'invoice_amount' => '10.00',
            'reservation_kind' => ReservationKind::DAILY_TICKET,
            'drop_off_time_slot_id' => null,
            'pick_up_time_slot_id' => null,
        ]));

        $data = (new DailyCapacityChartService())->forDate($day);

        $this->assertSame(2, $data['meta']['reservations_total']);
    }
}

