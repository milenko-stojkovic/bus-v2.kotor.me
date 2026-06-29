<?php

namespace Tests\Feature\Checkout;

use App\Contracts\PaymentService;
use App\Contracts\PaymentSessionResult;
use App\Models\AgencyAdvanceTransaction;
use App\Models\DailyParkingData;
use App\Models\ListOfTimeSlot;
use App\Models\Reservation;
use App\Models\TempData;
use App\Models\User;
use App\Models\Vehicle;
use App\Models\VehicleType;
use App\Services\Payment\PaymentSuccessHandler;
use App\Services\Reservation\DuplicateReservationAttemptService;
use App\Support\ReservationKind;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Mockery;
use Tests\TestCase;

class TerminiDuplicateReservationTest extends TestCase
{
    use RefreshDatabase;

    private const BLOCKED_EN = 'A reservation already exists for this license plate on the selected date with the same arrival time or the same departure time.';

    private function mockPaymentServiceNotCalled(): void
    {
        $mock = Mockery::mock(PaymentService::class);
        $mock->shouldNotReceive('createSession');
        $this->app->instance(PaymentService::class, $mock);
    }

    private function mockPaymentRedirect(): void
    {
        $mock = Mockery::mock(PaymentService::class);
        $mock->shouldReceive('createSession')
            ->once()
            ->andReturn(new PaymentSessionResult(true, 'https://bank.test/pay', null));
        $this->app->instance(PaymentService::class, $mock);
    }

    /**
     * @return array{drop: ListOfTimeSlot, pick: ListOfTimeSlot, other: ListOfTimeSlot}
     */
    private function seedSlots(): array
    {
        $drop = ListOfTimeSlot::query()->create(['time_slot' => '10:00 - 10:20']);
        $pick = ListOfTimeSlot::query()->create(['time_slot' => '11:00 - 11:20']);
        $other = ListOfTimeSlot::query()->create(['time_slot' => '12:00 - 12:20']);

        return compact('drop', 'pick', 'other');
    }

    private function seedDailyCapacity(string $date, array $slotIds, int $capacity = 5): void
    {
        foreach ($slotIds as $slotId) {
            DailyParkingData::query()->create([
                'date' => $date,
                'time_slot_id' => $slotId,
                'capacity' => $capacity,
                'reserved' => 0,
                'pending' => 0,
                'is_blocked' => false,
            ]);
        }
    }

    private function makeTerminiReservation(array $overrides = []): Reservation
    {
        $vt = VehicleType::query()->create(['price' => 20]);

        return Reservation::query()->create(array_merge([
            'merchant_transaction_id' => 'mt-'.uniqid('', true),
            'reservation_kind' => ReservationKind::TIME_SLOTS,
            'drop_off_time_slot_id' => 1,
            'pick_up_time_slot_id' => 2,
            'reservation_date' => now()->addDays(3)->toDateString(),
            'user_name' => 'Existing',
            'country' => 'ME',
            'license_plate' => 'KO123AB',
            'vehicle_type_id' => $vt->id,
            'email' => 'ex@example.com',
            'status' => 'paid',
            'invoice_amount' => '20.00',
            'email_sent' => Reservation::EMAIL_NOT_SENT,
            'created_by_admin' => false,
        ], $overrides));
    }

    public function test_guest_blocked_for_same_arrival_slot(): void
    {
        $this->mockPaymentServiceNotCalled();
        ['drop' => $drop, 'pick' => $pick] = $this->seedSlots();
        $vt = VehicleType::query()->create(['price' => 10]);
        $d = now()->addDays(3)->toDateString();

        $this->makeTerminiReservation([
            'drop_off_time_slot_id' => $drop->id,
            'pick_up_time_slot_id' => $pick->id,
            'reservation_date' => $d,
            'license_plate' => 'KO123AB',
        ]);

        $this->from('/guest/reserve')
            ->post(route('checkout.store', [], false), [
                'reservation_date' => $d,
                'drop_off_time_slot_id' => $drop->id,
                'pick_up_time_slot_id' => $pick->id,
                'vehicle_type_id' => $vt->id,
                'name' => 'NN',
                'country' => 'ME',
                'license_plate' => 'ko-123-ab',
                'email' => 'n@example.com',
                'accept_terms' => 1,
                'accept_privacy' => 1,
            ])
            ->assertRedirect('/guest/reserve')
            ->assertSessionHas('error', self::BLOCKED_EN);

        $this->assertSame(0, TempData::query()->count());
    }

    public function test_guest_blocked_for_same_departure_slot(): void
    {
        $this->mockPaymentServiceNotCalled();
        ['drop' => $drop, 'pick' => $pick, 'other' => $other] = $this->seedSlots();
        $vt = VehicleType::query()->create(['price' => 10]);
        $d = now()->addDays(3)->toDateString();

        $this->makeTerminiReservation([
            'drop_off_time_slot_id' => $drop->id,
            'pick_up_time_slot_id' => $pick->id,
            'reservation_date' => $d,
            'license_plate' => 'KO999',
        ]);

        $this->from('/guest/reserve')
            ->post(route('checkout.store', [], false), [
                'reservation_date' => $d,
                'drop_off_time_slot_id' => $other->id,
                'pick_up_time_slot_id' => $pick->id,
                'vehicle_type_id' => $vt->id,
                'name' => 'NN',
                'country' => 'ME',
                'license_plate' => 'KO999',
                'email' => 'n@example.com',
                'accept_terms' => 1,
                'accept_privacy' => 1,
            ])
            ->assertRedirect('/guest/reserve')
            ->assertSessionHas('error', self::BLOCKED_EN);
    }

    public function test_guest_blocked_for_same_arrival_and_departure(): void
    {
        $this->mockPaymentServiceNotCalled();
        ['drop' => $drop, 'pick' => $pick] = $this->seedSlots();
        $vt = VehicleType::query()->create(['price' => 10]);
        $d = now()->addDays(3)->toDateString();

        $this->makeTerminiReservation([
            'drop_off_time_slot_id' => $drop->id,
            'pick_up_time_slot_id' => $pick->id,
            'reservation_date' => $d,
            'license_plate' => 'KO555',
        ]);

        $this->from('/guest/reserve')
            ->post(route('checkout.store', [], false), [
                'reservation_date' => $d,
                'drop_off_time_slot_id' => $drop->id,
                'pick_up_time_slot_id' => $pick->id,
                'vehicle_type_id' => $vt->id,
                'name' => 'NN',
                'country' => 'ME',
                'license_plate' => 'KO555',
                'email' => 'n@example.com',
                'accept_terms' => 1,
                'accept_privacy' => 1,
            ])
            ->assertRedirect('/guest/reserve')
            ->assertSessionHas('error', self::BLOCKED_EN);
    }

    public function test_guest_allows_both_slots_different(): void
    {
        $this->mockPaymentRedirect();
        ['drop' => $drop, 'pick' => $pick, 'other' => $other] = $this->seedSlots();
        $fourth = ListOfTimeSlot::query()->create(['time_slot' => '13:00 - 13:20']);
        $vt = VehicleType::query()->create(['price' => 10]);
        $d = now()->addDays(3)->toDateString();

        $this->makeTerminiReservation([
            'drop_off_time_slot_id' => $drop->id,
            'pick_up_time_slot_id' => $pick->id,
            'reservation_date' => $d,
            'license_plate' => 'KO444',
            'vehicle_type_id' => $vt->id,
            'invoice_amount' => '10.00',
        ]);

        $this->seedDailyCapacity($d, [$other->id, $fourth->id]);

        $this->from('/guest/reserve')
            ->post(route('checkout.store', [], false), [
                'reservation_date' => $d,
                'drop_off_time_slot_id' => $other->id,
                'pick_up_time_slot_id' => $fourth->id,
                'vehicle_type_id' => $vt->id,
                'name' => 'NN',
                'country' => 'ME',
                'license_plate' => 'KO444',
                'email' => 'n@example.com',
                'accept_terms' => 1,
                'accept_privacy' => 1,
            ])
            ->assertRedirect('https://bank.test/pay')
            ->assertSessionMissing('error');
    }

    public function test_guest_allows_cross_match_only(): void
    {
        $this->mockPaymentRedirect();
        ['drop' => $drop, 'pick' => $pick, 'other' => $other] = $this->seedSlots();
        $vt = VehicleType::query()->create(['price' => 10]);
        $d = now()->addDays(3)->toDateString();

        $this->makeTerminiReservation([
            'drop_off_time_slot_id' => $drop->id,
            'pick_up_time_slot_id' => $pick->id,
            'reservation_date' => $d,
            'license_plate' => 'KO111',
            'vehicle_type_id' => $vt->id,
            'invoice_amount' => '10.00',
        ]);

        $this->seedDailyCapacity($d, [$pick->id, $other->id]);

        $this->from('/guest/reserve')
            ->post(route('checkout.store', [], false), [
                'reservation_date' => $d,
                'drop_off_time_slot_id' => $pick->id,
                'pick_up_time_slot_id' => $other->id,
                'vehicle_type_id' => $vt->id,
                'name' => 'NN',
                'country' => 'ME',
                'license_plate' => 'KO111',
                'email' => 'n@example.com',
                'accept_terms' => 1,
                'accept_privacy' => 1,
            ])
            ->assertRedirect('https://bank.test/pay')
            ->assertSessionMissing('error');
    }

    public function test_agency_advance_blocked_for_duplicate_termini(): void
    {
        config(['features.advance_payments' => true]);
        $this->mockPaymentServiceNotCalled();
        ['drop' => $drop, 'pick' => $pick] = $this->seedSlots();
        $vt = VehicleType::query()->create(['price' => 10]);
        $d = now()->addDays(3)->toDateString();
        $this->seedDailyCapacity($d, [$drop->id, $pick->id]);

        $u = User::factory()->create(['country' => 'ME', 'lang' => 'en']);
        $vehicle = Vehicle::query()->create([
            'user_id' => $u->id,
            'license_plate' => 'KOADV1',
            'vehicle_type_id' => $vt->id,
        ]);
        AgencyAdvanceTransaction::query()->create([
            'agency_user_id' => $u->id,
            'amount' => '100.00',
            'type' => AgencyAdvanceTransaction::TYPE_TOPUP,
            'reference_type' => 'manual',
            'reference_id' => null,
            'merchant_transaction_id' => 'topup-1',
            'note' => 'seed',
        ]);

        $this->makeTerminiReservation([
            'drop_off_time_slot_id' => $drop->id,
            'pick_up_time_slot_id' => $pick->id,
            'reservation_date' => $d,
            'license_plate' => 'KOADV1',
            'status' => 'paid',
        ]);

        $this->actingAs($u)
            ->from('/panel/reservations')
            ->post(route('checkout.store', [], false), [
                'auth_panel_booking' => 1,
                'payment_method' => 'advance',
                'reservation_date' => $d,
                'drop_off_time_slot_id' => $drop->id,
                'pick_up_time_slot_id' => $pick->id,
                'vehicle_id' => $vehicle->id,
                'accept_terms' => 1,
                'accept_privacy' => 1,
            ])
            ->assertRedirect('/panel/reservations')
            ->assertSessionHas('error', self::BLOCKED_EN);

        $this->assertSame(1, Reservation::query()->where('license_plate', 'KOADV1')->count());
    }

    public function test_pending_temp_data_blocks_same_arrival(): void
    {
        $this->mockPaymentServiceNotCalled();
        ['drop' => $drop, 'pick' => $pick, 'other' => $other] = $this->seedSlots();
        $vt = VehicleType::query()->create(['price' => 10]);
        $d = now()->addDays(3)->toDateString();

        TempData::query()->create([
            'merchant_transaction_id' => 'pending-temp-1',
            'retry_token' => 'retry-1',
            'reservation_kind' => ReservationKind::TIME_SLOTS,
            'drop_off_time_slot_id' => $drop->id,
            'pick_up_time_slot_id' => $pick->id,
            'reservation_date' => $d,
            'user_name' => 'Pending',
            'country' => 'ME',
            'license_plate' => 'KOPEND1',
            'vehicle_type_id' => $vt->id,
            'invoice_amount_snapshot' => '10.00',
            'email' => 'p@example.com',
            'status' => TempData::STATUS_PENDING,
        ]);

        $this->from('/guest/reserve')
            ->post(route('checkout.store', [], false), [
                'reservation_date' => $d,
                'drop_off_time_slot_id' => $drop->id,
                'pick_up_time_slot_id' => $other->id,
                'vehicle_type_id' => $vt->id,
                'name' => 'NN',
                'country' => 'ME',
                'license_plate' => 'KOPEND1',
                'email' => 'n@example.com',
                'accept_terms' => 1,
                'accept_privacy' => 1,
            ])
            ->assertRedirect('/guest/reserve')
            ->assertSessionHas('error', self::BLOCKED_EN);

        $this->assertSame(1, TempData::query()->where('status', TempData::STATUS_PENDING)->count());
    }

    public function test_expired_temp_data_does_not_block(): void
    {
        $this->mockPaymentRedirect();
        ['drop' => $drop, 'pick' => $pick, 'other' => $other] = $this->seedSlots();
        $vt = VehicleType::query()->create(['price' => 10]);
        $d = now()->addDays(3)->toDateString();
        $this->seedDailyCapacity($d, [$drop->id, $other->id]);

        TempData::query()->create([
            'merchant_transaction_id' => 'expired-temp-1',
            'retry_token' => 'retry-exp',
            'reservation_kind' => ReservationKind::TIME_SLOTS,
            'drop_off_time_slot_id' => $drop->id,
            'pick_up_time_slot_id' => $pick->id,
            'reservation_date' => $d,
            'user_name' => 'Expired',
            'country' => 'ME',
            'license_plate' => 'KOEXP1',
            'vehicle_type_id' => $vt->id,
            'invoice_amount_snapshot' => '10.00',
            'email' => 'e@example.com',
            'status' => TempData::STATUS_EXPIRED,
        ]);

        $this->from('/guest/reserve')
            ->post(route('checkout.store', [], false), [
                'reservation_date' => $d,
                'drop_off_time_slot_id' => $drop->id,
                'pick_up_time_slot_id' => $other->id,
                'vehicle_type_id' => $vt->id,
                'name' => 'NN',
                'country' => 'ME',
                'license_plate' => 'KOEXP1',
                'email' => 'n@example.com',
                'accept_terms' => 1,
                'accept_privacy' => 1,
            ])
            ->assertRedirect('https://bank.test/pay')
            ->assertSessionMissing('error');
    }

    public function test_free_reservation_blocks_duplicate(): void
    {
        $this->mockPaymentServiceNotCalled();
        ['drop' => $drop, 'pick' => $pick] = $this->seedSlots();
        $vt = VehicleType::query()->create(['price' => 10]);
        $d = now()->addDays(3)->toDateString();

        $this->makeTerminiReservation([
            'drop_off_time_slot_id' => $drop->id,
            'pick_up_time_slot_id' => $pick->id,
            'reservation_date' => $d,
            'license_plate' => 'KOFREE1',
            'status' => 'free',
            'invoice_amount' => '0.00',
            'created_by_admin' => true,
        ]);

        $this->from('/guest/reserve')
            ->post(route('checkout.store', [], false), [
                'reservation_date' => $d,
                'drop_off_time_slot_id' => $drop->id,
                'pick_up_time_slot_id' => $pick->id,
                'vehicle_type_id' => $vt->id,
                'name' => 'NN',
                'country' => 'ME',
                'license_plate' => 'KOFREE1',
                'email' => 'n@example.com',
                'accept_terms' => 1,
                'accept_privacy' => 1,
            ])
            ->assertRedirect('/guest/reserve')
            ->assertSessionHas('error', self::BLOCKED_EN);
    }

    public function test_daily_ticket_not_blocked_by_termini_reservation(): void
    {
        $mock = Mockery::mock(PaymentService::class);
        $mock->shouldReceive('createSession')
            ->once()
            ->andReturn(new PaymentSessionResult(true, 'https://bank.test/pay', null));
        $this->app->instance(PaymentService::class, $mock);

        ['drop' => $drop, 'pick' => $pick] = $this->seedSlots();
        $vt = VehicleType::query()->create(['price' => 40]);
        $d = now()->addDays(3)->toDateString();

        $this->makeTerminiReservation([
            'drop_off_time_slot_id' => $drop->id,
            'pick_up_time_slot_id' => $pick->id,
            'reservation_date' => $d,
            'license_plate' => 'KODAILY1',
        ]);

        $u = User::factory()->create(['country' => 'ME', 'lang' => 'en']);
        $vehicle = Vehicle::query()->create([
            'user_id' => $u->id,
            'license_plate' => 'KODAILY1',
            'vehicle_type_id' => $vt->id,
        ]);

        $this->actingAs($u)
            ->from('/panel/reservations')
            ->post(route('checkout.store', [], false), [
                'auth_panel_booking' => 1,
                'reservation_kind' => ReservationKind::DAILY_TICKET,
                'reservation_date' => $d,
                'vehicle_id' => $vehicle->id,
                'accept_terms' => 1,
            ])
            ->assertSessionMissing('error');

        $this->assertNotSame(self::BLOCKED_EN, session('error'));
    }

    public function test_payment_success_handler_blocks_duplicate_after_first_paid(): void
    {
        ['drop' => $drop, 'pick' => $pick] = $this->seedSlots();
        $vt = VehicleType::query()->create(['price' => 10]);
        $d = now()->addDays(3)->toDateString();
        $this->seedDailyCapacity($d, [$drop->id, $pick->id], 10);

        $this->makeTerminiReservation([
            'drop_off_time_slot_id' => $drop->id,
            'pick_up_time_slot_id' => $pick->id,
            'reservation_date' => $d,
            'license_plate' => 'KORACE1',
        ]);

        $temp = TempData::query()->create([
            'merchant_transaction_id' => 'race-temp-2',
            'retry_token' => 'retry-race',
            'reservation_kind' => ReservationKind::TIME_SLOTS,
            'drop_off_time_slot_id' => $drop->id,
            'pick_up_time_slot_id' => $pick->id,
            'reservation_date' => $d,
            'user_name' => 'Race',
            'country' => 'ME',
            'license_plate' => 'KORACE1',
            'vehicle_type_id' => $vt->id,
            'invoice_amount_snapshot' => '10.00',
            'email' => 'race@example.com',
            'status' => TempData::STATUS_PENDING,
        ]);

        $created = app(PaymentSuccessHandler::class)->handle($temp, ['status' => 'success'], true, true);

        $this->assertFalse($created);
        $temp->refresh();
        $this->assertSame(TempData::STATUS_LATE_MANUAL_REVIEW, $temp->status);
        $this->assertSame(1, Reservation::query()->where('license_plate', 'KORACE1')->count());
    }

    public function test_service_excludes_reservation_when_editing(): void
    {
        ['drop' => $drop, 'pick' => $pick] = $this->seedSlots();
        $d = now()->addDays(3)->toDateString();

        $r1 = $this->makeTerminiReservation([
            'drop_off_time_slot_id' => $drop->id,
            'pick_up_time_slot_id' => $pick->id,
            'reservation_date' => $d,
            'license_plate' => 'KOEDIT1',
        ]);

        $service = app(DuplicateReservationAttemptService::class);
        $this->assertFalse($service->existsConflict($d, 'KOEDIT1', $drop->id, $pick->id, exceptReservationId: $r1->id));
        $this->assertTrue($service->existsConflict($d, 'KOEDIT1', $drop->id, $pick->id));
    }
}
