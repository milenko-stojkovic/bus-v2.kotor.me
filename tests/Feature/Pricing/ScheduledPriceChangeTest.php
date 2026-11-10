<?php

namespace Tests\Feature\Pricing;

use App\Contracts\PaymentService;
use App\Contracts\PaymentSessionResult;
use App\Jobs\PaymentCallbackJob;
use App\Models\Admin;
use App\Models\AgencyAdvanceTransaction;
use App\Models\DailyParkingData;
use App\Models\ListOfTimeSlot;
use App\Models\PriceChangeSchedule;
use App\Models\Reservation;
use App\Models\Role;
use App\Models\TempData;
use App\Models\User;
use App\Models\Vehicle;
use App\Models\VehicleType;
use App\Services\AgencyAdvance\AgencyLateSuccessAdvanceConversionService;
use App\Services\Payment\LateSuccessManualResolutionService;
use App\Services\Payment\PaymentSuccessHandler;
use App\Services\Payment\RealPaymentProvider;
use App\Support\ReservationKind;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

final class ScheduledPriceChangeTest extends TestCase
{
    use RefreshDatabase;

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    public function test_admin_can_schedule_edit_and_remove_one_future_change(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-12-01 12:00:00', 'Europe/Podgorica'));
        $admin = $this->admin();
        $car = VehicleType::query()->create(['price' => 15]);
        $bus = VehicleType::query()->create(['price' => 40]);
        $this->actingAs($admin, 'panel_admin');

        $this->get(route('panel_admin.settings', [], false))
            ->assertOk()
            ->assertSee('Promjena cijena', false);

        $this->post(route('panel_admin.settings.price-change.store', [], false), [
            'effective_on' => '2026-12-01',
            'prices' => [$car->id => '18.00'],
        ])->assertSessionHasErrors('effective_on');

        $this->post(route('panel_admin.settings.price-change.store', [], false), [
            'effective_on' => '2026-12-03',
            'prices' => [$car->id => '15.00', $bus->id => ''],
        ])->assertSessionHasErrors('prices');

        $this->post(route('panel_admin.settings.price-change.store', [], false), [
            'effective_on' => '2026-12-03',
            'prices' => [$car->id => '18.00', $bus->id => ''],
        ])->assertRedirect(route('panel_admin.settings', [], false));

        $this->assertSame('15.00', (string) $car->fresh()->price);
        $schedule = PriceChangeSchedule::query()->firstOrFail();
        $this->assertSame(PriceChangeSchedule::STATUS_SCHEDULED, $schedule->status);
        $this->assertSame('18.00', (string) $schedule->lines()->first()->price_after);

        $this->post(route('panel_admin.settings.price-change.store', [], false), [
            'effective_on' => '2026-12-04',
            'prices' => [$bus->id => '50.00'],
        ])->assertSessionHasErrors('price_change');
        $this->assertSame(1, PriceChangeSchedule::query()->where('status', PriceChangeSchedule::STATUS_SCHEDULED)->count());

        $this->put(route('panel_admin.settings.price-change.update', [], false), [
            'effective_on' => '2026-12-05',
            'prices' => [$car->id => '19.50'],
        ])->assertRedirect(route('panel_admin.settings', [], false));
        $schedule->refresh();
        $this->assertSame('2026-12-05', $schedule->effectiveAt()->toDateString());
        $this->assertSame('19.50', (string) $schedule->lines()->first()->price_after);

        $this->delete(route('panel_admin.settings.price-change.destroy', [], false))
            ->assertRedirect(route('panel_admin.settings', [], false));
        $this->assertSame(PriceChangeSchedule::STATUS_CANCELLED, $schedule->fresh()->status);
        $this->assertSame('15.00', (string) $car->fresh()->price);

        Carbon::setTestNow(Carbon::parse('2026-12-06 00:10:00', 'Europe/Podgorica'));
        $this->put(route('panel_admin.settings.price-change.update', [], false), [
            'effective_on' => '2026-12-08',
            'prices' => [$car->id => '21.00'],
        ])->assertSessionHasErrors('price_change');
    }

    public function test_applied_change_is_kept_as_history_and_catalog_price_updates_without_waiting_for_the_command(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-12-01 10:00:00', 'Europe/Podgorica'));
        $admin = $this->admin();
        $car = VehicleType::query()->create(['price' => 15]);
        $this->actingAs($admin, 'panel_admin');
        $this->post(route('panel_admin.settings.price-change.store', [], false), [
            'effective_on' => '2026-12-02',
            'prices' => [$car->id => '22.00'],
        ])->assertRedirect();

        Carbon::setTestNow(Carbon::parse('2026-12-02 00:00:00', 'Europe/Podgorica'));
        $this->get(route('panel_admin.settings', [], false))
            ->assertOk()
            ->assertSee('Na snazi', false)
            ->assertDontSee('Izmijeni', false);

        $this->assertSame('22.00', (string) $car->fresh()->price);
        $this->delete(route('panel_admin.settings.price-change.destroy', [], false))
            ->assertSessionHasErrors('price_change');
        $this->assertSame('22.00', (string) $car->fresh()->price);
    }

    public function test_new_card_attempt_is_blocked_during_blackout_but_an_existing_session_continues(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-12-01 23:40:00', 'Europe/Podgorica'));
        $type = VehicleType::query()->create(['price' => 15]);
        $this->schedule($type, '2026-12-02', '20.00');
        $mock = \Mockery::mock(PaymentService::class);
        $mock->shouldNotReceive('createSession');
        $this->app->instance(PaymentService::class, $mock);

        $this->from('/guest/reserve')->post(route('checkout.store', [], false), $this->dailyPayload($type, [
            'confirmed_amount' => '15.00',
        ]))->assertSessionHasErrors('payment_method');
        $this->assertSame(0, TempData::query()->count());

        $drop = ListOfTimeSlot::query()->create(['time_slot' => '10:00 - 10:20']);
        $pick = ListOfTimeSlot::query()->create(['time_slot' => '12:00 - 12:20']);
        $date = '2026-12-10';
        TempData::query()->create([
            'merchant_transaction_id' => 'pending-session',
            'retry_token' => 'retry-session',
            'reservation_kind' => ReservationKind::TIME_SLOTS,
            'drop_off_time_slot_id' => $drop->id,
            'pick_up_time_slot_id' => $pick->id,
            'reservation_date' => $date,
            'user_name' => 'Guest',
            'country' => 'ME',
            'license_plate' => 'KO123AA',
            'vehicle_type_id' => $type->id,
            'invoice_amount_snapshot' => '15.00',
            'email' => 'guest@example.com',
            'status' => TempData::STATUS_PENDING,
            'payment_redirect_url' => 'https://bank.example/existing',
        ]);

        $this->post(route('checkout.store', [], false), [
            'reservation_kind' => ReservationKind::TIME_SLOTS,
            'reservation_date' => $date,
            'drop_off_time_slot_id' => $drop->id,
            'pick_up_time_slot_id' => $pick->id,
            'vehicle_type_id' => $type->id,
            'name' => 'Guest',
            'country' => 'ME',
            'license_plate' => 'KO123AA',
            'email' => 'guest@example.com',
            'accept_terms' => 1,
            'accept_privacy' => 1,
            'confirmed_amount' => '15.00',
        ])->assertRedirect('https://bank.example/existing');
        $this->assertSame(TempData::STATUS_PENDING, TempData::query()->first()->status);
    }

    public function test_free_reservation_is_not_blocked_by_the_card_blackout(): void
    {
        Mail::fake();
        Carbon::setTestNow(Carbon::parse('2026-12-01 23:40:00', 'Europe/Podgorica'));
        $type = VehicleType::query()->create(['price' => 15]);
        $this->schedule($type, '2026-12-02', '20.00');
        $slot = ListOfTimeSlot::query()->create(['time_slot' => '06:00 - 06:20']);
        $date = '2026-12-10';
        DailyParkingData::query()->create([
            'date' => $date,
            'time_slot_id' => $slot->id,
            'capacity' => 5,
            'reserved' => 0,
            'pending' => 0,
            'is_blocked' => false,
        ]);

        $this->post(route('checkout.store', [], false), [
            'reservation_kind' => ReservationKind::TIME_SLOTS,
            'reservation_date' => $date,
            'drop_off_time_slot_id' => $slot->id,
            'pick_up_time_slot_id' => $slot->id,
            'vehicle_type_id' => $type->id,
            'name' => 'Free Guest',
            'country' => 'ME',
            'license_plate' => 'FREE1',
            'email' => 'free@example.com',
            'accept_terms' => 1,
            'accept_privacy' => 1,
        ])->assertSessionMissing('errors');

        $reservation = Reservation::query()->where('license_plate', 'FREE1')->first();
        $this->assertNotNull($reservation);
        $this->assertSame('free', $reservation->status);
        $this->assertSame('0.00', (string) $reservation->invoice_amount);
    }

    public function test_advance_debits_the_tariff_in_force_and_requires_the_incoming_balance_before_midnight(): void
    {
        config(['features.advance_payments' => true]);
        $user = User::factory()->create(['country' => 'ME', 'lang' => 'cg', 'email_verified_at' => now()]);
        $type = VehicleType::query()->create(['price' => 15]);
        $vehicle = Vehicle::query()->create([
            'user_id' => $user->id,
            'license_plate' => 'ADV1',
            'vehicle_type_id' => $type->id,
        ]);
        $this->schedule($type, '2026-12-02', '20.00');
        $this->topup($user, '16.00');

        Carbon::setTestNow(Carbon::parse('2026-12-01 23:50:00', 'Europe/Podgorica'));
        $this->actingAs($user)->post(route('checkout.store', [], false), $this->advancePayload($vehicle, '15.00'))
            ->assertSessionHasErrors('payment_method');
        $this->assertSame(0, Reservation::query()->count());
        $this->assertSame(1, AgencyAdvanceTransaction::query()->count());

        $this->topup($user, '10.00');
        $this->actingAs($user)->post(route('checkout.store', [], false), $this->advancePayload($vehicle, '15.00', 'mtid-before'))
            ->assertRedirect(route('panel.reservations', [], false));
        $before = Reservation::query()->where('merchant_transaction_id', 'mtid-before')->firstOrFail();
        $this->assertSame('15.00', (string) $before->invoice_amount);

        Carbon::setTestNow(Carbon::parse('2026-12-02 00:01:00', 'Europe/Podgorica'));
        $this->actingAs($user)->post(route('checkout.store', [], false), $this->advancePayload($vehicle, '15.00', 'mtid-stale'))
            ->assertSessionHasErrors('payment_method');
        $this->assertNull(Reservation::query()->where('merchant_transaction_id', 'mtid-stale')->first());

        $this->topup($user, '20.00');
        $this->actingAs($user)->post(route('checkout.store', [], false), $this->advancePayload($vehicle, '20.00', 'mtid-after'))
            ->assertRedirect(route('panel.reservations', [], false));
        $after = Reservation::query()->where('merchant_transaction_id', 'mtid-after')->firstOrFail();
        $this->assertSame('20.00', (string) $after->invoice_amount);
        $this->assertSame('20.00', (string) $type->fresh()->price);
    }

    public function test_bankart_and_success_handler_keep_the_snapshot_after_the_catalog_price_changes(): void
    {
        config([
            'services.bankart' => [
                'api_url' => 'https://bankart.test',
                'api_key' => 'api-key-1',
                'username' => 'user',
                'password' => 'pass',
                'shared_secret' => '',
                'signature_enabled' => false,
                'send_customer' => false,
            ],
        ]);
        $captured = null;
        Http::fake(function ($request) use (&$captured) {
            $captured = (string) $request->body();

            return Http::response(['redirectUrl' => 'https://bank.test/pay'], 200);
        });

        $drop = ListOfTimeSlot::query()->create(['time_slot' => '10:00 - 10:20']);
        $pick = ListOfTimeSlot::query()->create(['time_slot' => '11:00 - 11:20']);
        $type = VehicleType::query()->create(['price' => 99]);
        $temp = TempData::query()->create([
            'merchant_transaction_id' => 'mt-snapshot',
            'drop_off_time_slot_id' => $drop->id,
            'pick_up_time_slot_id' => $pick->id,
            'reservation_date' => '2026-12-10',
            'user_name' => 'Guest',
            'country' => 'ME',
            'license_plate' => 'SNAP1',
            'vehicle_type_id' => $type->id,
            'invoice_amount_snapshot' => '15.00',
            'email' => 'snap@example.com',
            'status' => TempData::STATUS_PENDING,
        ]);

        $result = app(RealPaymentProvider::class)->createSession($temp);
        $this->assertTrue($result->success);
        $this->assertStringContainsString('"amount":"15.00"', (string) $captured);

        $created = app(PaymentSuccessHandler::class)->handle($temp, ['source' => 'test'], true, true);
        $this->assertTrue($created);
        $this->assertSame('15.00', (string) Reservation::query()->first()->invoice_amount);
        $this->assertSame(TempData::STATUS_PROCESSED, $temp->fresh()->status);
    }

    public function test_crossing_midnight_does_not_make_a_pending_payment_late_success(): void
    {
        $type = VehicleType::query()->create(['price' => 30]);
        $drop = ListOfTimeSlot::query()->create(['time_slot' => '10:00 - 10:20']);
        $pick = ListOfTimeSlot::query()->create(['time_slot' => '11:00 - 11:20']);
        $temp = TempData::query()->create([
            'merchant_transaction_id' => 'mt-midnight',
            'drop_off_time_slot_id' => $drop->id,
            'pick_up_time_slot_id' => $pick->id,
            'reservation_date' => '2026-12-10',
            'user_name' => 'Guest',
            'country' => 'ME',
            'license_plate' => 'MID1',
            'vehicle_type_id' => $type->id,
            'invoice_amount_snapshot' => '15.00',
            'email' => 'mid@example.com',
            'status' => TempData::STATUS_PENDING,
            'created_at' => Carbon::parse('2026-12-01 23:20:00', 'Europe/Podgorica'),
        ]);

        Carbon::setTestNow(Carbon::parse('2026-12-02 00:10:00', 'Europe/Podgorica'));
        (new PaymentCallbackJob(
            ['merchant_transaction_id' => 'mt-midnight', 'status' => 'success'],
            ['result' => 'OK'],
            true,
        ))->handle();

        $temp->refresh();
        $this->assertSame(TempData::STATUS_PROCESSED, $temp->status);
        $reservation = Reservation::query()->where('merchant_transaction_id', 'mt-midnight')->firstOrFail();
        $this->assertSame('15.00', (string) $reservation->invoice_amount);
    }

    public function test_guest_force_and_agency_late_success_keep_the_snapshot_amount(): void
    {
        Mail::fake();
        config(['features.advance_payments' => true]);
        $type = VehicleType::query()->create(['price' => 40]);
        $drop = ListOfTimeSlot::query()->create(['time_slot' => '16:20 - 16:40']);
        $pick = ListOfTimeSlot::query()->create(['time_slot' => '18:00 - 18:20']);
        $date = '2026-12-10';
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

        $guest = TempData::query()->create([
            'merchant_transaction_id' => 'mt-guest-force',
            'drop_off_time_slot_id' => $drop->id,
            'pick_up_time_slot_id' => $pick->id,
            'reservation_date' => $date,
            'user_name' => 'Guest',
            'country' => 'ME',
            'license_plate' => 'FORCE1',
            'vehicle_type_id' => $type->id,
            'invoice_amount_snapshot' => '15.00',
            'email' => 'force@example.com',
            'status' => TempData::STATUS_LATE_SUCCESS,
        ]);
        $staff = User::factory()->create();
        $role = Role::query()->create(['name' => 'admin', 'guard_name' => 'web']);
        $staff->roles()->attach($role->id);
        $this->actingAs($staff);
        $forced = app(LateSuccessManualResolutionService::class)->forceCreate($guest->id);
        $this->assertTrue($forced['ok']);
        $this->assertSame('15.00', (string) Reservation::query()->findOrFail($forced['reservation_id'])->invoice_amount);
        $this->assertSame(TempData::STATUS_PROCESSED, $guest->fresh()->status);

        $agency = User::factory()->create();
        $agencyTemp = TempData::query()->create([
            'merchant_transaction_id' => 'mt-agency-late',
            'user_id' => $agency->id,
            'drop_off_time_slot_id' => $drop->id,
            'pick_up_time_slot_id' => $pick->id,
            'reservation_date' => $date,
            'user_name' => 'Agency',
            'country' => 'ME',
            'license_plate' => 'LATE1',
            'vehicle_type_id' => $type->id,
            'invoice_amount_snapshot' => '15.00',
            'email' => $agency->email,
            'status' => TempData::STATUS_LATE_SUCCESS,
        ]);
        $this->assertSame('converted', app(AgencyLateSuccessAdvanceConversionService::class)->convertIfEligible($agencyTemp));
        $this->assertSame('15.00', (string) \App\Models\AgencyAdvanceTopup::query()->first()->amount);
        $this->assertSame(TempData::STATUS_LATE_SUCCESS, $agencyTemp->fresh()->status);
        $this->assertSame(0, Reservation::query()->where('merchant_transaction_id', 'mt-agency-late')->count());
    }

    public function test_paid_checkout_without_a_confirmed_amount_creates_no_charge(): void
    {
        $type = VehicleType::query()->create(['price' => 15]);
        $mock = \Mockery::mock(PaymentService::class);
        $mock->shouldNotReceive('createSession');
        $this->app->instance(PaymentService::class, $mock);

        $this->from('/guest/reserve')
            ->post(route('checkout.store', [], false), $this->dailyPayload($type))
            ->assertSessionHasErrors('payment_method');
        $this->assertSame(0, TempData::query()->count());

        config(['features.advance_payments' => true]);
        $user = User::factory()->create(['country' => 'ME', 'lang' => 'cg', 'email_verified_at' => now()]);
        $vehicle = Vehicle::query()->create([
            'user_id' => $user->id,
            'license_plate' => 'ADV0',
            'vehicle_type_id' => $type->id,
        ]);
        $this->topup($user, '100.00');
        $payload = $this->advancePayload($vehicle, '15.00', 'mtid-missing');
        unset($payload['confirmed_amount']);

        $this->actingAs($user)
            ->from('/panel/reservations')
            ->post(route('checkout.store', [], false), $payload)
            ->assertSessionHasErrors('payment_method');
        $this->assertSame(0, Reservation::query()->count());
        $this->assertSame(1, AgencyAdvanceTransaction::query()->count());
    }

    public function test_existing_pending_session_continues_without_a_new_confirmed_amount(): void
    {
        $type = VehicleType::query()->create(['price' => 15]);
        $drop = ListOfTimeSlot::query()->create(['time_slot' => '10:00 - 10:20']);
        $pick = ListOfTimeSlot::query()->create(['time_slot' => '12:00 - 12:20']);
        $date = '2026-12-10';
        TempData::query()->create([
            'merchant_transaction_id' => 'pending-session-no-confirm',
            'retry_token' => 'retry-session-no-confirm',
            'reservation_kind' => ReservationKind::TIME_SLOTS,
            'drop_off_time_slot_id' => $drop->id,
            'pick_up_time_slot_id' => $pick->id,
            'reservation_date' => $date,
            'user_name' => 'Guest',
            'country' => 'ME',
            'license_plate' => 'KO123AA',
            'vehicle_type_id' => $type->id,
            'invoice_amount_snapshot' => '15.00',
            'email' => 'guest@example.com',
            'status' => TempData::STATUS_PENDING,
            'payment_redirect_url' => 'https://bank.example/existing',
        ]);
        $mock = \Mockery::mock(PaymentService::class);
        $mock->shouldNotReceive('createSession');
        $this->app->instance(PaymentService::class, $mock);

        $this->post(route('checkout.store', [], false), [
            'reservation_kind' => ReservationKind::TIME_SLOTS,
            'reservation_date' => $date,
            'drop_off_time_slot_id' => $drop->id,
            'pick_up_time_slot_id' => $pick->id,
            'vehicle_type_id' => $type->id,
            'name' => 'Guest',
            'country' => 'ME',
            'license_plate' => 'KO123AA',
            'email' => 'guest@example.com',
            'accept_terms' => 1,
            'accept_privacy' => 1,
        ])->assertRedirect('https://bank.example/existing');
        $this->assertSame(1, TempData::query()->count());
    }

    public function test_card_checkout_locks_the_tariff_before_parking_rows(): void
    {
        if (DB::getDriverName() !== 'mysql') {
            $this->markTestSkipped('InnoDB lock order is verified on MySQL.');
        }

        $type = VehicleType::query()->create(['price' => 15]);
        $drop = ListOfTimeSlot::query()->create(['time_slot' => '10:00 - 10:20']);
        $pick = ListOfTimeSlot::query()->create(['time_slot' => '12:00 - 12:20']);
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
        $mock = \Mockery::mock(PaymentService::class);
        $mock->shouldReceive('createSession')->once()->andReturn(new PaymentSessionResult(
            success: true,
            paymentUrl: 'https://bank.example/pay',
            errorMessage: null,
        ));
        $this->app->instance(PaymentService::class, $mock);

        $sql = [];
        DB::listen(function ($query) use (&$sql): void {
            $sql[] = strtolower($query->sql);
        });

        $this->post(route('checkout.store', [], false), [
            'reservation_kind' => ReservationKind::TIME_SLOTS,
            'reservation_date' => $date,
            'drop_off_time_slot_id' => $drop->id,
            'pick_up_time_slot_id' => $pick->id,
            'vehicle_type_id' => $type->id,
            'name' => 'Guest',
            'country' => 'ME',
            'license_plate' => 'LOCK1',
            'email' => 'lock@example.com',
            'accept_terms' => 1,
            'accept_privacy' => 1,
            'confirmed_amount' => '15.00',
        ])->assertRedirect('https://bank.example/pay');

        $parkingLockAt = null;
        $tariffLockBeforeParking = false;
        $tariffLockAfterParking = false;
        foreach ($sql as $index => $statement) {
            $locksTariff = str_contains($statement, 'price_change_schedules') && str_contains($statement, 'for update');
            $locksParking = str_contains($statement, 'daily_parking_data') && str_contains($statement, 'for update');
            if ($locksTariff && $parkingLockAt === null) {
                $tariffLockBeforeParking = true;
            }
            if ($locksParking && $parkingLockAt === null) {
                $parkingLockAt = $index;
            }
            if ($locksTariff && $parkingLockAt !== null) {
                $tariffLockAfterParking = true;
            }
        }

        $this->assertTrue($tariffLockBeforeParking);
        $this->assertNotNull($parkingLockAt);
        $this->assertFalse($tariffLockAfterParking);
    }

    private function admin(): Admin
    {
        return Admin::query()->create([
            'username' => 'price-admin',
            'email' => 'price-admin@example.com',
            'password' => bcrypt('secret-password-st'),
            'control_access' => false,
            'admin_access' => true,
        ]);
    }

    private function schedule(VehicleType $type, string $date, string $after): void
    {
        $schedule = PriceChangeSchedule::query()->create([
            'effective_on' => $date,
            'status' => PriceChangeSchedule::STATUS_SCHEDULED,
            'pending_slot' => PriceChangeSchedule::PENDING_SLOT,
        ]);
        $schedule->lines()->create([
            'vehicle_type_id' => $type->id,
            'price_before' => $type->price,
            'price_after' => $after,
        ]);
    }

    /**
     * @param  array<string, mixed>  $extra
     * @return array<string, mixed>
     */
    private function dailyPayload(VehicleType $type, array $extra = []): array
    {
        return array_merge([
            'reservation_kind' => ReservationKind::DAILY_TICKET,
            'reservation_date' => '2026-12-10',
            'vehicle_type_id' => $type->id,
            'name' => 'Guest',
            'country' => 'ME',
            'license_plate' => 'KO999AA',
            'email' => 'guest-new@example.com',
            'accept_terms' => 1,
        ], $extra);
    }

    /**
     * @return array<string, mixed>
     */
    private function advancePayload(Vehicle $vehicle, string $confirmed, string $mtid = 'mtid-advance'): array
    {
        return [
            'auth_panel_booking' => 1,
            'reservation_kind' => ReservationKind::DAILY_TICKET,
            'payment_method' => 'advance',
            'reservation_date' => '2026-12-10',
            'vehicle_id' => $vehicle->id,
            'accept_terms' => 1,
            'merchant_transaction_id' => $mtid,
            'confirmed_amount' => $confirmed,
        ];
    }

    private function topup(User $user, string $amount): void
    {
        AgencyAdvanceTransaction::query()->create([
            'agency_user_id' => $user->id,
            'amount' => $amount,
            'type' => AgencyAdvanceTransaction::TYPE_TOPUP,
            'reference_type' => 'seed',
            'reference_id' => null,
            'merchant_transaction_id' => 'topup-'.uniqid(),
            'note' => 'seed',
        ]);
    }
}
