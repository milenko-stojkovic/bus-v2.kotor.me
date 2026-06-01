<?php

namespace Tests\Unit\Models;

use App\Models\ListOfTimeSlot;
use App\Models\Reservation;
use App\Models\TempData;
use App\Models\VehicleType;
use App\Support\ReservationKind;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

final class ReservationKindFoundationTest extends TestCase
{
    use RefreshDatabase;

    public function test_reservation_defaults_to_time_slots_when_kind_omitted(): void
    {
        $reservation = $this->createTimeSlotsReservation();

        $reservation->refresh();

        $this->assertSame(ReservationKind::TIME_SLOTS, $reservation->reservation_kind);
        $this->assertTrue($reservation->isTimeSlots());
        $this->assertFalse($reservation->isDailyTicket());
    }

    public function test_temp_data_defaults_to_time_slots_when_kind_omitted(): void
    {
        $drop = ListOfTimeSlot::query()->create(['time_slot' => '08:00 - 08:20']);
        $pick = ListOfTimeSlot::query()->create(['time_slot' => '18:00 - 18:20']);
        $vt = VehicleType::query()->create(['price' => '10.00']);

        $temp = TempData::query()->create([
            'merchant_transaction_id' => (string) Str::uuid(),
            'drop_off_time_slot_id' => $drop->id,
            'pick_up_time_slot_id' => $pick->id,
            'reservation_date' => Carbon::today()->toDateString(),
            'user_name' => 'Agency',
            'country' => 'ME',
            'license_plate' => 'KO123AB',
            'vehicle_type_id' => $vt->id,
            'email' => 'agency@test.local',
            'status' => TempData::STATUS_PENDING,
        ]);

        $temp->refresh();

        $this->assertSame(ReservationKind::TIME_SLOTS, $temp->reservation_kind);
        $this->assertTrue($temp->isTimeSlots());
        $this->assertFalse($temp->isDailyTicket());
    }

    public function test_can_create_daily_ticket_reservation_with_null_slots(): void
    {
        $vt = VehicleType::query()->create(['price' => '25.00']);

        $reservation = Reservation::query()->create([
            'merchant_transaction_id' => 'mt-daily-'.Str::random(8),
            'reservation_kind' => Reservation::KIND_DAILY_TICKET,
            'drop_off_time_slot_id' => null,
            'pick_up_time_slot_id' => null,
            'reservation_date' => Carbon::today()->addDay()->toDateString(),
            'user_name' => 'Agency Daily',
            'country' => 'ME',
            'license_plate' => 'KO456CD',
            'vehicle_type_id' => $vt->id,
            'email' => 'daily@test.local',
            'status' => 'paid',
            'invoice_amount' => '25.00',
            'email_sent' => Reservation::EMAIL_NOT_SENT,
        ]);

        $this->assertTrue($reservation->isDailyTicket());
        $this->assertFalse($reservation->isTimeSlots());
        $this->assertNull($reservation->drop_off_time_slot_id);
        $this->assertNull($reservation->pick_up_time_slot_id);
    }

    public function test_can_create_daily_ticket_temp_data_with_null_slots(): void
    {
        $vt = VehicleType::query()->create(['price' => '30.00']);

        $temp = TempData::query()->create([
            'merchant_transaction_id' => (string) Str::uuid(),
            'reservation_kind' => TempData::KIND_DAILY_TICKET,
            'drop_off_time_slot_id' => null,
            'pick_up_time_slot_id' => null,
            'reservation_date' => Carbon::today()->toDateString(),
            'user_name' => 'Agency Daily',
            'country' => 'ME',
            'license_plate' => 'KO789EF',
            'vehicle_type_id' => $vt->id,
            'email' => 'daily-temp@test.local',
            'status' => TempData::STATUS_PENDING,
        ]);

        $this->assertTrue($temp->isDailyTicket());
        $this->assertNull($temp->drop_off_time_slot_id);
        $this->assertNull($temp->pick_up_time_slot_id);
    }

    public function test_time_slots_reservation_with_slots_still_persists(): void
    {
        $reservation = $this->createTimeSlotsReservation();

        $this->assertNotNull($reservation->drop_off_time_slot_id);
        $this->assertNotNull($reservation->pick_up_time_slot_id);
        $this->assertTrue($reservation->dropOffTimeSlot()->exists());
        $this->assertTrue($reservation->pickUpTimeSlot()->exists());
    }

    public function test_reservation_kind_constants_match_support_class(): void
    {
        $this->assertSame(ReservationKind::TIME_SLOTS, Reservation::KIND_TIME_SLOTS);
        $this->assertSame(ReservationKind::DAILY_TICKET, Reservation::KIND_DAILY_TICKET);
        $this->assertSame(ReservationKind::TIME_SLOTS, TempData::KIND_TIME_SLOTS);
        $this->assertSame(ReservationKind::DAILY_TICKET, TempData::KIND_DAILY_TICKET);
    }

    private function createTimeSlotsReservation(): Reservation
    {
        $drop = ListOfTimeSlot::query()->create(['time_slot' => '09:00 - 09:20']);
        $pick = ListOfTimeSlot::query()->create(['time_slot' => '17:00 - 17:20']);
        $vt = VehicleType::query()->create(['price' => '15.00']);

        return Reservation::query()->create([
            'merchant_transaction_id' => 'mt-slots-'.Str::random(8),
            'drop_off_time_slot_id' => $drop->id,
            'pick_up_time_slot_id' => $pick->id,
            'reservation_date' => Carbon::today()->addDays(3)->toDateString(),
            'user_name' => 'Agency Slots',
            'country' => 'ME',
            'license_plate' => 'KO111AA',
            'vehicle_type_id' => $vt->id,
            'email' => 'slots@test.local',
            'status' => 'paid',
            'invoice_amount' => '15.00',
            'email_sent' => Reservation::EMAIL_NOT_SENT,
        ]);
    }
}
