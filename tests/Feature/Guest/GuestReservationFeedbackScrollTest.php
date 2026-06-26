<?php

namespace Tests\Feature\Guest;

use App\Contracts\PaymentService;
use App\Models\DailyParkingData;
use App\Models\ListOfTimeSlot;
use App\Models\Reservation;
use App\Models\VehicleType;
use App\Models\VehicleTypeTranslation;
use App\Services\Reservation\DuplicateReservationAttemptService;
use App\Support\ReservationKind;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

final class GuestReservationFeedbackScrollTest extends TestCase
{
    use RefreshDatabase;

    /** @return array{low: VehicleType, high: VehicleType, drop: ListOfTimeSlot, pick: ListOfTimeSlot, date: string} */
    private function seedFixtures(): array
    {
        $low = VehicleType::query()->create(['price' => 15.00]);
        $high = VehicleType::query()->create(['price' => 40.00]);

        foreach ([[$low, 'Niža'], [$high, 'Viša']] as [$type, $name]) {
            VehicleTypeTranslation::query()->create([
                'vehicle_type_id' => $type->id,
                'locale' => 'cg',
                'name' => $name,
                'description' => null,
            ]);
        }

        $drop = ListOfTimeSlot::query()->create(['time_slot' => '10:00 - 10:20']);
        $pick = ListOfTimeSlot::query()->create(['time_slot' => '11:00 - 11:20']);
        $date = now()->addDays(3)->toDateString();

        foreach ([$drop->id, $pick->id] as $slotId) {
            DailyParkingData::query()->create([
                'date' => $date,
                'time_slot_id' => $slotId,
                'capacity' => 5,
                'reserved' => 0,
                'pending' => 0,
                'is_blocked' => false,
            ]);
        }

        return compact('low', 'high', 'drop', 'pick', 'date');
    }

    public function test_blocked_guest_checkout_renders_validation_message_with_feedback_anchor(): void
    {
        $fixtures = $this->seedFixtures();
        $plate = 'PGSCROLL1';

        Reservation::query()->create([
            'user_id' => null,
            'merchant_transaction_id' => 'mt-scroll-hist',
            'drop_off_time_slot_id' => $fixtures['drop']->id,
            'pick_up_time_slot_id' => $fixtures['pick']->id,
            'reservation_date' => '2026-06-01',
            'user_name' => 'Hist',
            'country' => 'ME',
            'license_plate' => DuplicateReservationAttemptService::normalizeLicensePlate($plate),
            'vehicle_type_id' => $fixtures['high']->id,
            'email' => 'hist@example.com',
            'status' => 'paid',
            'invoice_amount' => 40.00,
        ]);

        $payment = \Mockery::mock(PaymentService::class);
        $payment->shouldNotReceive('createSession');
        $this->app->instance(PaymentService::class, $payment);

        $this->get('/locale/cg')->assertRedirect();

        $html = $this->followingRedirects()
            ->from('/guest/reserve')
            ->post(route('checkout.store', [], false), [
                'reservation_kind' => ReservationKind::TIME_SLOTS,
                'reservation_date' => $fixtures['date'],
                'drop_off_time_slot_id' => $fixtures['drop']->id,
                'pick_up_time_slot_id' => $fixtures['pick']->id,
                'vehicle_type_id' => $fixtures['low']->id,
                'name' => 'Guest',
                'country' => 'ME',
                'license_plate' => $plate,
                'email' => 'guest@example.com',
                'accept_terms' => 1,
                'accept_privacy' => 1,
            ])
            ->assertOk()
            ->getContent();

        $this->assertStringContainsString('id="guest-reservation-feedback"', $html);
        $this->assertStringContainsString('data-guest-reservation-feedback', $html);
        $this->assertStringContainsString('višoj kategoriji vozila', $html);
    }

    public function test_guest_reserve_includes_feedback_scroll_logic_in_bundled_js_source(): void
    {
        $js = file_get_contents(base_path('resources/js/reservationFormScroll.js'));

        $this->assertIsString($js);
        $this->assertStringContainsString('scrollToGuestReservationFeedback', $js);
        $this->assertStringContainsString('data-guest-reservation-feedback', $js);
        $this->assertStringContainsString('scrollIntoView', $js);
    }

    public function test_guest_reserve_without_validation_does_not_render_feedback_scroll_marker(): void
    {
        $html = $this->get(route('guest.reserve', [], false))
            ->assertOk()
            ->getContent();

        $this->assertStringContainsString('id="guest-reservation-feedback"', $html);
        $this->assertStringNotContainsString('data-guest-reservation-feedback', $html);
    }

    public function test_guest_daily_fee_checkout_success_path_unchanged_without_feedback_scroll_marker(): void
    {
        $vt = VehicleType::query()->create(['price' => 22.00]);
        $date = now()->addDays(4)->toDateString();

        $payment = \Mockery::mock(PaymentService::class);
        $payment->shouldReceive('createSession')
            ->once()
            ->andReturn(new \App\Contracts\PaymentSessionResult(true, 'https://bank.example/pay', null));
        $this->app->instance(PaymentService::class, $payment);

        $this->post(route('checkout.store', [], false), [
            'reservation_kind' => ReservationKind::DAILY_TICKET,
            'reservation_date' => $date,
            'vehicle_type_id' => $vt->id,
            'name' => 'Guest Daily',
            'country' => 'ME',
            'license_plate' => 'GUESTOK9',
            'email' => 'guest-daily@example.com',
            'accept_terms' => 1,
        ])->assertRedirect('https://bank.example/pay');
    }

    protected function tearDown(): void
    {
        \Mockery::close();
        parent::tearDown();
    }
}
