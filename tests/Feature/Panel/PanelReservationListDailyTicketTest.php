<?php

namespace Tests\Feature\Panel;

use App\Models\ListOfTimeSlot;
use App\Models\Reservation;
use App\Models\User;
use App\Models\VehicleType;
use App\Services\Reservation\PanelReservationListService;
use App\Support\ReservationKind;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

final class PanelReservationListDailyTicketTest extends TestCase
{
    use RefreshDatabase;

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    public function test_daily_ticket_for_today_is_upcoming(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-07-10 23:59:00', 'Europe/Podgorica'));

        $r = $this->dailyReservation('2026-07-10');
        $this->assertTrue(PanelReservationListService::isUpcoming($r));
        $this->assertFalse(PanelReservationListService::isRealized($r));
    }

    public function test_daily_ticket_for_future_date_is_upcoming(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-07-10 10:00:00', 'Europe/Podgorica'));

        $r = $this->dailyReservation('2026-07-15');
        $this->assertTrue(PanelReservationListService::isUpcoming($r));
    }

    public function test_daily_ticket_for_yesterday_is_realized(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-07-10 08:00:00', 'Europe/Podgorica'));

        $r = $this->dailyReservation('2026-07-09');
        $this->assertFalse(PanelReservationListService::isUpcoming($r));
        $this->assertTrue(PanelReservationListService::isRealized($r));
    }

    public function test_time_slots_upcoming_and_realized_unchanged_by_pickup_end(): void
    {
        $drop = ListOfTimeSlot::query()->create(['time_slot' => '08:00 - 08:20']);
        $pick = ListOfTimeSlot::query()->create(['time_slot' => '18:00 - 18:20']);
        $vt = VehicleType::query()->create(['price' => 10]);

        Carbon::setTestNow(Carbon::parse('2026-07-10 12:00:00', 'Europe/Podgorica'));

        $upcoming = Reservation::query()->create([
            'merchant_transaction_id' => 'mt-slot-up-'.Str::random(4),
            'reservation_kind' => ReservationKind::TIME_SLOTS,
            'drop_off_time_slot_id' => $drop->id,
            'pick_up_time_slot_id' => $pick->id,
            'reservation_date' => '2026-07-10',
            'user_name' => 'A',
            'country' => 'ME',
            'license_plate' => 'KO111AA',
            'vehicle_type_id' => $vt->id,
            'email' => 'a@test.local',
            'status' => 'paid',
            'invoice_amount' => '10.00',
            'email_sent' => Reservation::EMAIL_NOT_SENT,
        ]);
        $upcoming->load(['pickUpTimeSlot', 'dropOffTimeSlot']);
        $this->assertTrue(PanelReservationListService::isUpcoming($upcoming));

        Carbon::setTestNow(Carbon::parse('2026-07-10 19:00:00', 'Europe/Podgorica'));
        $this->assertFalse(PanelReservationListService::isUpcoming($upcoming->fresh(['pickUpTimeSlot', 'dropOffTimeSlot'])));
        $this->assertTrue(PanelReservationListService::isRealized($upcoming->fresh(['pickUpTimeSlot', 'dropOffTimeSlot'])));
    }

    public function test_upcoming_page_lists_daily_ticket_for_today(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-07-10 14:00:00', 'Europe/Podgorica'));

        $user = User::factory()->create(['email_verified_at' => now(), 'lang' => 'cg']);
        $r = $this->dailyReservation('2026-07-10', ['user_id' => $user->id]);

        $html = $this->actingAs($user)
            ->get(route('panel.upcoming', [], false))
            ->assertOk()
            ->getContent();

        $this->assertTrue(
            str_contains($html, 'Dnevna karta') || str_contains($html, 'Daily ticket'),
            'Expected daily ticket label in upcoming list',
        );
        $this->assertStringContainsString($r->license_plate, $html);
    }

    private function dailyReservation(string $date, array $overrides = []): Reservation
    {
        $vt = VehicleType::query()->create(['price' => 10]);

        $r = Reservation::query()->create(array_merge([
            'merchant_transaction_id' => 'mt-daily-list-'.Str::random(6),
            'reservation_kind' => ReservationKind::DAILY_TICKET,
            'drop_off_time_slot_id' => null,
            'pick_up_time_slot_id' => null,
            'reservation_date' => $date,
            'user_name' => 'Agency',
            'country' => 'ME',
            'license_plate' => 'KO888DD',
            'vehicle_type_id' => $vt->id,
            'email' => 'list@test.local',
            'status' => 'paid',
            'invoice_amount' => '10.00',
            'email_sent' => Reservation::EMAIL_NOT_SENT,
        ], $overrides));

        return $r->fresh();
    }
}
