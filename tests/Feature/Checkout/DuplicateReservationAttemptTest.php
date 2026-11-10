<?php

namespace Tests\Feature\Checkout;

use App\Contracts\PaymentService;
use App\Models\ListOfTimeSlot;
use App\Models\Reservation;
use App\Models\TempData;
use App\Models\User;
use App\Models\Vehicle;
use App\Models\VehicleType;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Mockery;
use Tests\TestCase;

class DuplicateReservationAttemptTest extends TestCase
{
    use RefreshDatabase;

    private function mockPaymentServiceNotCalled(): void
    {
        $mock = Mockery::mock(PaymentService::class);
        $mock->shouldNotReceive('createSession');
        $this->app->instance(PaymentService::class, $mock);
    }

    private function seedMinimalSlots(): array
    {
        $drop = ListOfTimeSlot::query()->create(['time_slot' => '10:00 - 10:20']);
        $pick = ListOfTimeSlot::query()->create(['time_slot' => '11:00 - 11:20']);
        $other = ListOfTimeSlot::query()->create(['time_slot' => '12:00 - 12:20']);

        return [$drop, $pick, $other];
    }

    public function test_guest_checkout_blocked_for_same_date_plate_and_same_drop(): void
    {
        $this->mockPaymentServiceNotCalled();

        [$drop, $pick] = $this->seedMinimalSlots();
        $vt = VehicleType::query()->create(['price' => 10]);
        $d = now()->addDays(3)->toDateString();

        Reservation::query()->create([
            'merchant_transaction_id' => 'mt-existing-1',
            'drop_off_time_slot_id' => $drop->id,
            'pick_up_time_slot_id' => $pick->id,
            'reservation_date' => $d,
            'user_name' => 'Existing',
            'country' => 'ME',
            'license_plate' => 'KO123AB',
            'vehicle_type_id' => $vt->id,
            'email' => 'ex@example.com',
            'status' => 'paid',
            'invoice_amount' => '10.00',
            'email_sent' => Reservation::EMAIL_NOT_SENT,
            'created_by_admin' => false,
        ]);

        $this->from('/guest/reserve')
            ->post(route('checkout.store', [], false), [
                'reservation_date' => $d,
                'drop_off_time_slot_id' => $drop->id, // same drop => block
                'pick_up_time_slot_id' => $pick->id,
                'vehicle_type_id' => $vt->id,
                'name' => 'NN',
                'country' => 'ME',
                'license_plate' => 'ko-123-ab', // normalization should match
                'email' => 'n@example.com',
                'accept_terms' => 1,
                'accept_privacy' => 1,
            ])
            ->assertRedirect('/guest/reserve')
            ->assertSessionHas('error')
            ->assertSessionHasErrors();

        $this->assertSame(0, TempData::query()->count());
    }

    public function test_guest_checkout_blocked_for_same_date_plate_and_same_pick(): void
    {
        $this->mockPaymentServiceNotCalled();

        [$drop, $pick, $other] = $this->seedMinimalSlots();
        $vt = VehicleType::query()->create(['price' => 10]);
        $d = now()->addDays(3)->toDateString();

        Reservation::query()->create([
            'merchant_transaction_id' => 'mt-existing-2',
            'drop_off_time_slot_id' => $drop->id,
            'pick_up_time_slot_id' => $pick->id,
            'reservation_date' => $d,
            'user_name' => 'Existing',
            'country' => 'ME',
            'license_plate' => 'KO999',
            'vehicle_type_id' => $vt->id,
            'email' => 'ex@example.com',
            'status' => 'paid',
            'invoice_amount' => '10.00',
            'email_sent' => Reservation::EMAIL_NOT_SENT,
            'created_by_admin' => false,
        ]);

        $this->from('/guest/reserve')
            ->post(route('checkout.store', [], false), [
                'reservation_date' => $d,
                'drop_off_time_slot_id' => $other->id, // different drop
                'pick_up_time_slot_id' => $pick->id, // same pick => block
                'vehicle_type_id' => $vt->id,
                'name' => 'NN',
                'country' => 'ME',
                'license_plate' => 'KO999',
                'email' => 'n@example.com',
                'accept_terms' => 1,
                'accept_privacy' => 1,
            ])
            ->assertRedirect('/guest/reserve')
            ->assertSessionHas('error')
            ->assertSessionHasErrors();

        $this->assertSame(0, TempData::query()->count());
    }

    public function test_guest_checkout_not_blocked_for_cross_match_drop_equals_existing_pick(): void
    {
        $this->mockPaymentServiceNotCalled();

        [$drop, $pick, $other] = $this->seedMinimalSlots();
        $vt = VehicleType::query()->create(['price' => 10]);
        $d = now()->addDays(3)->toDateString();

        // Existing: drop=drop, pick=pick
        Reservation::query()->create([
            'merchant_transaction_id' => 'mt-existing-3',
            'drop_off_time_slot_id' => $drop->id,
            'pick_up_time_slot_id' => $pick->id,
            'reservation_date' => $d,
            'user_name' => 'Existing',
            'country' => 'ME',
            'license_plate' => 'KO111',
            'vehicle_type_id' => $vt->id,
            'email' => 'ex@example.com',
            'status' => 'paid',
            'invoice_amount' => '10.00',
            'email_sent' => Reservation::EMAIL_NOT_SENT,
            'created_by_admin' => false,
        ]);

        // Attempt: drop == existing pick (cross) and pick different => should NOT be blocked by duplicate-attempt rule.
        // It will continue into the normal flow and likely fail later (no DailyParkingData seeded) with 422 capacity.
        $this->from('/guest/reserve')
            ->post(route('checkout.store', [], false), [
                'reservation_date' => $d,
                'drop_off_time_slot_id' => $pick->id, // cross-match only
                'pick_up_time_slot_id' => $other->id,
                'vehicle_type_id' => $vt->id,
                'name' => 'NN',
                'country' => 'ME',
                'license_plate' => 'KO111',
                'email' => 'n@example.com',
                'accept_terms' => 1,
                'accept_privacy' => 1,
                'confirmed_amount' => '10.00',
            ])
            ->assertStatus(422)
            ->assertDontSee('A reservation already exists for this license plate on the selected date with the same arrival time or the same departure time.', false);

        $this->assertSame(0, TempData::query()->count());
    }

    public function test_panel_checkout_blocked_for_same_date_plate_and_same_drop_or_pick_using_saved_vehicle(): void
    {
        $this->mockPaymentServiceNotCalled();

        [$drop, $pick] = $this->seedMinimalSlots();
        $vt = VehicleType::query()->create(['price' => 10]);
        $d = now()->addDays(3)->toDateString();

        $u = User::factory()->create(['country' => 'ME', 'lang' => 'en']);
        $vehicle = Vehicle::query()->create([
            'user_id' => $u->id,
            'license_plate' => 'KO777',
            'vehicle_type_id' => $vt->id,
        ]);

        Reservation::query()->create([
            'merchant_transaction_id' => 'mt-existing-4',
            'drop_off_time_slot_id' => $drop->id,
            'pick_up_time_slot_id' => $pick->id,
            'reservation_date' => $d,
            'user_name' => 'Existing',
            'country' => 'ME',
            'license_plate' => 'KO777',
            'vehicle_type_id' => $vt->id,
            'email' => 'ex@example.com',
            'status' => 'free',
            'invoice_amount' => '0.00',
            'email_sent' => Reservation::EMAIL_NOT_SENT,
            'created_by_admin' => true,
        ]);

        $this->actingAs($u);

        $this->from('/panel/reservations')
            ->post(route('checkout.store', [], false), [
                'auth_panel_booking' => 1,
                'reservation_date' => $d,
                'drop_off_time_slot_id' => $drop->id, // same drop => block
                'pick_up_time_slot_id' => $pick->id,
                'vehicle_id' => $vehicle->id,
                'accept_terms' => 1,
                'accept_privacy' => 1,
            ])
            ->assertRedirect('/panel/reservations')
            ->assertSessionHas('error')
            ->assertSessionHasErrors();

        $this->assertSame(0, TempData::query()->count());
    }
}

