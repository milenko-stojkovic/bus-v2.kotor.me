<?php

namespace Tests\Feature\Guest;

use App\Contracts\PaymentService;
use App\Models\AdminAlert;
use App\Models\DailyParkingData;
use App\Models\ListOfTimeSlot;
use App\Models\Reservation;
use App\Models\TempData;
use App\Models\User;
use App\Models\Vehicle;
use App\Models\VehicleType;
use App\Models\VehicleTypeTranslation;
use App\Services\AdminFiscalizationAlertService;
use App\Services\Reservation\DuplicateReservationAttemptService;
use App\Support\ReservationKind;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Mockery;
use Tests\TestCase;

final class GuestPaidLowerCategoryCheckoutBlockTest extends TestCase
{
    use RefreshDatabase;

    private string $docsDir;

    /** @var array<string, bool> */
    private array $createdGuidePdfs = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->docsDir = public_path('docs');
        if (! is_dir($this->docsDir)) {
            mkdir($this->docsDir, 0777, true);
        }
        foreach (['cgbuskotor.pdf', 'engbuskotor.pdf'] as $name) {
            $path = $this->docsDir.DIRECTORY_SEPARATOR.$name;
            if (! is_file($path)) {
                file_put_contents($path, '%PDF-1.4');
                $this->createdGuidePdfs[$name] = true;
            }
        }
    }

    protected function tearDown(): void
    {
        foreach ($this->createdGuidePdfs as $name => $_) {
            @unlink($this->docsDir.DIRECTORY_SEPARATOR.$name);
        }
        Mockery::close();
        parent::tearDown();
    }

    /** @return array{low: VehicleType, high: VehicleType, drop: ListOfTimeSlot, pick: ListOfTimeSlot, date: string} */
    private function seedFixtures(): array
    {
        $low = VehicleType::query()->create(['price' => 15.00]);
        $high = VehicleType::query()->create(['price' => 40.00]);

        foreach ([[$low, 'Niža kategorija', 'Lower category'], [$high, 'Viša kategorija', 'Higher category']] as [$type, $cgName, $enName]) {
            foreach (['cg' => $cgName, 'en' => $enName] as $locale => $name) {
                VehicleTypeTranslation::query()->create([
                    'vehicle_type_id' => $type->id,
                    'locale' => $locale,
                    'name' => $name,
                    'description' => null,
                ]);
            }
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

    private function seedHistoricalPaidGuest(array $fixtures, string $plate, int $vehicleTypeId, string $merchantId = 'mt-hist'): Reservation
    {
        return Reservation::query()->create([
            'user_id' => null,
            'merchant_transaction_id' => $merchantId,
            'reservation_kind' => ReservationKind::TIME_SLOTS,
            'drop_off_time_slot_id' => $fixtures['drop']->id,
            'pick_up_time_slot_id' => $fixtures['pick']->id,
            'reservation_date' => '2026-06-01',
            'user_name' => 'Historical Guest',
            'country' => 'ME',
            'license_plate' => DuplicateReservationAttemptService::normalizeLicensePlate($plate),
            'vehicle_type_id' => $vehicleTypeId,
            'email' => 'hist@example.com',
            'preferred_locale' => 'cg',
            'status' => 'paid',
            'invoice_amount' => 40.00,
        ]);
    }

    /** @return array<string, mixed> */
    private function guestTerminiPayload(array $fixtures, string $plate, int $vehicleTypeId): array
    {
        return [
            'reservation_kind' => ReservationKind::TIME_SLOTS,
            'reservation_date' => $fixtures['date'],
            'drop_off_time_slot_id' => $fixtures['drop']->id,
            'pick_up_time_slot_id' => $fixtures['pick']->id,
            'vehicle_type_id' => $vehicleTypeId,
            'name' => 'Guest User',
            'country' => 'ME',
            'license_plate' => $plate,
            'email' => 'guest@example.com',
            'accept_terms' => 1,
            'accept_privacy' => 1,
            'confirmed_amount' => number_format((float) (VehicleType::query()->find($vehicleTypeId)?->price ?? 0), 2, '.', ''),
        ];
    }

    public function test_guest_time_slots_lower_than_previous_paid_guest_category_is_blocked_before_payment_init(): void
    {
        Mail::fake();
        $fixtures = $this->seedFixtures();
        $plate = 'PG123AB';
        $this->seedHistoricalPaidGuest($fixtures, $plate, (int) $fixtures['high']->id);

        $payment = Mockery::mock(PaymentService::class);
        $payment->shouldNotReceive('createSession');
        $this->app->instance(PaymentService::class, $payment);

        $this->get('/locale/cg')->assertRedirect();

        $this->from('/guest/reserve')
            ->post(route('checkout.store', [], false), $this->guestTerminiPayload($fixtures, $plate, (int) $fixtures['low']->id))
            ->assertRedirect()
            ->assertSessionHasErrors('vehicle_type_id')
            ->assertSessionHas('guest_lower_category_block');

        $this->assertSame(0, TempData::query()->count());
    }

    public function test_guest_daily_ticket_lower_than_previous_paid_guest_category_is_blocked_before_payment_init(): void
    {
        Mail::fake();
        $fixtures = $this->seedFixtures();
        $plate = 'PGDAILY1';
        $this->seedHistoricalPaidGuest($fixtures, $plate, (int) $fixtures['high']->id, 'mt-hist-daily');

        $payment = Mockery::mock(PaymentService::class);
        $payment->shouldNotReceive('createSession');
        $this->app->instance(PaymentService::class, $payment);

        $this->get('/locale/cg')->assertRedirect();

        $this->from('/guest/reserve')
            ->post(route('checkout.store', [], false), [
                'reservation_kind' => ReservationKind::DAILY_TICKET,
                'reservation_date' => $fixtures['date'],
                'vehicle_type_id' => $fixtures['low']->id,
                'name' => 'Guest Daily',
                'country' => 'ME',
                'license_plate' => $plate,
                'email' => 'guest-daily@example.com',
                'accept_terms' => 1,
            ])
            ->assertRedirect()
            ->assertSessionHasErrors('vehicle_type_id');

        $this->assertSame(0, TempData::query()->count());
    }

    public function test_same_category_is_allowed(): void
    {
        $fixtures = $this->seedFixtures();
        $plate = 'PGSAME1';
        $this->seedHistoricalPaidGuest($fixtures, $plate, (int) $fixtures['high']->id, 'mt-same');

        $payment = Mockery::mock(PaymentService::class);
        $payment->shouldReceive('createSession')->once()->andReturn(new \App\Contracts\PaymentSessionResult(true, 'https://bank.example/pay', null));
        $this->app->instance(PaymentService::class, $payment);

        $this->post(route('checkout.store', [], false), $this->guestTerminiPayload($fixtures, $plate, (int) $fixtures['high']->id))
            ->assertRedirect('https://bank.example/pay');

        $this->assertSame(1, TempData::query()->count());
    }

    public function test_higher_category_is_allowed(): void
    {
        $fixtures = $this->seedFixtures();
        $plate = 'PGHIGH1';
        $this->seedHistoricalPaidGuest($fixtures, $plate, (int) $fixtures['low']->id, 'mt-low-hist');

        $payment = Mockery::mock(PaymentService::class);
        $payment->shouldReceive('createSession')->once()->andReturn(new \App\Contracts\PaymentSessionResult(true, 'https://bank.example/pay', null));
        $this->app->instance(PaymentService::class, $payment);

        $this->post(route('checkout.store', [], false), $this->guestTerminiPayload($fixtures, $plate, (int) $fixtures['high']->id))
            ->assertRedirect('https://bank.example/pay');
    }

    public function test_previous_paid_agency_reservation_does_not_block_guest(): void
    {
        $fixtures = $this->seedFixtures();
        $user = User::factory()->create();
        $plate = 'KOAGENCY1';

        Reservation::query()->create([
            'user_id' => $user->id,
            'merchant_transaction_id' => 'mt-agency-hist',
            'reservation_kind' => ReservationKind::TIME_SLOTS,
            'drop_off_time_slot_id' => $fixtures['drop']->id,
            'pick_up_time_slot_id' => $fixtures['pick']->id,
            'reservation_date' => '2026-06-01',
            'user_name' => $user->name,
            'country' => 'ME',
            'license_plate' => DuplicateReservationAttemptService::normalizeLicensePlate($plate),
            'vehicle_type_id' => $fixtures['high']->id,
            'email' => $user->email,
            'status' => 'paid',
            'invoice_amount' => 40.00,
        ]);

        $payment = Mockery::mock(PaymentService::class);
        $payment->shouldReceive('createSession')->once()->andReturn(new \App\Contracts\PaymentSessionResult(true, 'https://bank.example/pay', null));
        $this->app->instance(PaymentService::class, $payment);

        $this->post(route('checkout.store', [], false), $this->guestTerminiPayload($fixtures, $plate, (int) $fixtures['low']->id))
            ->assertRedirect('https://bank.example/pay');
    }

    public function test_previous_free_reservation_does_not_block_guest(): void
    {
        $fixtures = $this->seedFixtures();
        $plate = 'PGFREE1';

        Reservation::query()->create([
            'user_id' => null,
            'merchant_transaction_id' => 'mt-free-hist',
            'drop_off_time_slot_id' => $fixtures['drop']->id,
            'pick_up_time_slot_id' => $fixtures['pick']->id,
            'reservation_date' => '2026-06-01',
            'user_name' => 'Free Guest',
            'country' => 'ME',
            'license_plate' => DuplicateReservationAttemptService::normalizeLicensePlate($plate),
            'vehicle_type_id' => $fixtures['high']->id,
            'email' => 'free@example.com',
            'status' => 'free',
            'invoice_amount' => 0,
        ]);

        $payment = Mockery::mock(PaymentService::class);
        $payment->shouldReceive('createSession')->once()->andReturn(new \App\Contracts\PaymentSessionResult(true, 'https://bank.example/pay', null));
        $this->app->instance(PaymentService::class, $payment);

        $this->post(route('checkout.store', [], false), $this->guestTerminiPayload($fixtures, $plate, (int) $fixtures['low']->id))
            ->assertRedirect('https://bank.example/pay');
    }

    public function test_previous_temp_data_pending_does_not_block_guest(): void
    {
        $fixtures = $this->seedFixtures();
        $plate = 'PGTEMP1';

        TempData::query()->create([
            'merchant_transaction_id' => 'mt-pending-high',
            'retry_token' => 'retry-pending',
            'user_id' => null,
            'reservation_kind' => ReservationKind::TIME_SLOTS,
            'drop_off_time_slot_id' => $fixtures['drop']->id,
            'pick_up_time_slot_id' => $fixtures['pick']->id,
            'reservation_date' => '2026-06-01',
            'user_name' => 'Pending Guest',
            'country' => 'ME',
            'license_plate' => DuplicateReservationAttemptService::normalizeLicensePlate($plate),
            'vehicle_type_id' => $fixtures['high']->id,
            'email' => 'pending@example.com',
            'status' => TempData::STATUS_PENDING,
            'invoice_amount_snapshot' => 40.00,
        ]);

        $payment = Mockery::mock(PaymentService::class);
        $payment->shouldReceive('createSession')->once()->andReturn(new \App\Contracts\PaymentSessionResult(true, 'https://bank.example/pay', null));
        $this->app->instance(PaymentService::class, $payment);

        $this->post(route('checkout.store', [], false), $this->guestTerminiPayload($fixtures, $plate, (int) $fixtures['low']->id))
            ->assertRedirect('https://bank.example/pay');
    }

    public function test_historical_category_uses_reservations_vehicle_type_id_snapshot(): void
    {
        Mail::fake();
        $fixtures = $this->seedFixtures();
        $plate = 'PGSNAP1';
        $historical = $this->seedHistoricalPaidGuest($fixtures, $plate, (int) $fixtures['high']->id, 'mt-snap');

        VehicleType::query()->whereKey($fixtures['high']->id)->update(['price' => 55.00]);

        $payment = Mockery::mock(PaymentService::class);
        $payment->shouldNotReceive('createSession');
        $this->app->instance(PaymentService::class, $payment);

        $response = $this->post(route('checkout.store', [], false), $this->guestTerminiPayload($fixtures, $plate, (int) $fixtures['low']->id));
        $response->assertSessionHasErrors('vehicle_type_id');

        $block = $response->getSession()->get('guest_lower_category_block');
        $this->assertIsArray($block);
        $this->assertStringContainsString('55.00 EUR', (string) ($block['required_category'] ?? ''));
        $this->assertSame((int) $historical->vehicle_type_id, (int) $fixtures['high']->id);
    }

    public function test_block_response_includes_required_category_name_support_email_and_agency_guide_link(): void
    {
        Mail::fake();
        $fixtures = $this->seedFixtures();
        $plate = 'PGMSG01';
        $this->seedHistoricalPaidGuest($fixtures, $plate, (int) $fixtures['high']->id, 'mt-msg');

        $this->get('/locale/cg')->assertRedirect();

        $response = $this->followingRedirects()
            ->from('/guest/reserve')
            ->post(route('checkout.store', [], false), $this->guestTerminiPayload($fixtures, $plate, (int) $fixtures['low']->id));

        $html = $response->getContent();

        $this->assertStringContainsString('Viša kategorija', $html);
        $this->assertStringContainsString('bus@kotor.me', $html);
        $this->assertStringContainsString('Uputstvo za agencije', $html);
        $this->assertStringContainsString('docs/cgbuskotor.pdf', $html);
    }

    public function test_blocked_attempt_creates_admin_alerts_and_email_trace(): void
    {
        $fixtures = $this->seedFixtures();
        $plate = 'PGALERT1';
        $this->seedHistoricalPaidGuest($fixtures, $plate, (int) $fixtures['high']->id, 'mt-alert');

        $fiscalAlerts = Mockery::mock(AdminFiscalizationAlertService::class)->makePartial();
        $fiscalAlerts->shouldReceive('notify')
            ->once()
            ->with(
                '[Kotor Bus] Guest checkout blocked: lower vehicle category than historical paid',
                Mockery::type('string'),
                Mockery::on(fn (array $ctx): bool => ($ctx['alert_type'] ?? '') === 'guest_lower_category_checkout_blocked'),
            );
        $this->app->instance(AdminFiscalizationAlertService::class, $fiscalAlerts);

        $this->post(route('checkout.store', [], false), $this->guestTerminiPayload($fixtures, $plate, (int) $fixtures['low']->id));

        $this->assertDatabaseHas('admin_alerts', [
            'type' => 'guest_lower_category_checkout_blocked',
            'status' => AdminAlert::STATUS_UNREAD,
        ]);
    }

    public function test_agency_checkout_is_not_blocked_by_this_rule(): void
    {
        $fixtures = $this->seedFixtures();
        $user = User::factory()->create();
        $plate = 'KOAGLOW1';

        Reservation::query()->create([
            'user_id' => null,
            'merchant_transaction_id' => 'mt-guest-hist-high',
            'drop_off_time_slot_id' => $fixtures['drop']->id,
            'pick_up_time_slot_id' => $fixtures['pick']->id,
            'reservation_date' => '2026-06-01',
            'user_name' => 'Guest Hist',
            'country' => 'ME',
            'license_plate' => DuplicateReservationAttemptService::normalizeLicensePlate($plate),
            'vehicle_type_id' => $fixtures['high']->id,
            'email' => 'guest-hist@example.com',
            'status' => 'paid',
            'invoice_amount' => 40.00,
        ]);

        $vehicle = Vehicle::query()->create([
            'user_id' => $user->id,
            'license_plate' => $plate,
            'vehicle_type_id' => $fixtures['low']->id,
        ]);

        config(['features.advance_payments' => true]);
        \App\Models\AgencyAdvanceTransaction::query()->create([
            'agency_user_id' => $user->id,
            'amount' => '500.00',
            'type' => \App\Models\AgencyAdvanceTransaction::TYPE_TOPUP,
            'reference_type' => 'manual',
            'reference_id' => null,
            'merchant_transaction_id' => 'topup-1',
            'note' => 'seed',
        ]);

        $this->actingAs($user)->post(route('checkout.store', [], false), [
            'auth_panel_booking' => 1,
            'payment_method' => 'advance',
            'reservation_kind' => ReservationKind::TIME_SLOTS,
            'reservation_date' => $fixtures['date'],
            'drop_off_time_slot_id' => $fixtures['drop']->id,
            'pick_up_time_slot_id' => $fixtures['pick']->id,
            'vehicle_id' => $vehicle->id,
            'accept_terms' => 1,
            'accept_privacy' => 1,
            'confirmed_amount' => '15.00',
        ])->assertRedirect(route('panel.reservations', [], false));

        $this->assertSame(1, Reservation::query()->where('user_id', $user->id)->where('status', 'paid')->count());
        $this->assertSame(0, AdminAlert::query()->where('type', 'guest_lower_category_checkout_blocked')->count());
    }
}
