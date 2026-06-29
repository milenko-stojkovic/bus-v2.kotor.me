<?php

namespace Tests\Feature\Profile;

use App\Contracts\PaymentService;
use App\Contracts\PaymentSessionResult;
use App\Models\DailyParkingData;
use App\Models\ListOfTimeSlot;
use App\Models\TempData;
use App\Models\User;
use App\Models\Vehicle;
use App\Models\VehicleType;
use App\Services\Payment\BankartBillingCountryAlertService;
use App\Support\BankartBillingCountry;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Mockery;
use Tests\TestCase;

final class AgencyCountryProfileTest extends TestCase
{
    use RefreshDatabase;

    private const MSG_EN = 'Please select your country. If your country is not listed, contact bus@kotor.me.';

    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }

    public function test_agency_profile_page_shows_country_selector_without_other(): void
    {
        $user = User::factory()->create(['country' => 'OTHER', 'lang' => 'en']);

        $html = $this->actingAs($user)
            ->get(route('panel.user', [], false))
            ->assertOk()
            ->getContent();

        $this->assertStringContainsString('name="country"', $html);
        $this->assertStringContainsString('id="user_country"', $html);
        $this->assertStringNotContainsString('value="OTHER"', $html);
        $this->assertStringContainsString('not valid for payment', $html);
    }

    public function test_agency_profile_rejects_other_country(): void
    {
        $user = User::factory()->create(['country' => 'ME', 'lang' => 'en']);

        $this->actingAs($user)
            ->from(route('panel.user', [], false))
            ->patch('/profile', [
                'name' => $user->name,
                'email' => $user->email,
                'lang' => 'en',
                'country' => 'OTHER',
            ])
            ->assertRedirect(route('panel.user', [], false))
            ->assertSessionHasErrors('country');

        $errors = session('errors')->get('country');
        $this->assertStringContainsString('bus@kotor.me', (string) ($errors[0] ?? ''));
        $this->assertSame('ME', $user->fresh()->country);
    }

    public function test_existing_agency_with_other_can_update_to_valid_country(): void
    {
        $user = User::factory()->create(['country' => 'OTHER', 'lang' => 'en']);

        $this->actingAs($user)
            ->patch('/profile', [
                'name' => $user->name,
                'email' => $user->email,
                'lang' => 'en',
                'country' => 'HR',
            ])
            ->assertRedirect(route('panel.user', [], false))
            ->assertSessionHasNoErrors();

        $this->assertSame('HR', $user->fresh()->country);
    }

    /**
     * @return array{drop: ListOfTimeSlot, pick: ListOfTimeSlot, date: string, vt: VehicleType}
     */
    private function seedTerminiFixtures(): array
    {
        $drop = ListOfTimeSlot::query()->create(['time_slot' => '10:00 - 10:20']);
        $pick = ListOfTimeSlot::query()->create(['time_slot' => '11:00 - 11:20']);
        $date = now()->addDays(3)->toDateString();
        $vt = VehicleType::query()->create(['price' => 50.00]);

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

        return compact('drop', 'pick', 'date', 'vt');
    }

    public function test_checkout_remains_blocked_for_other_until_profile_fixed(): void
    {
        $fixtures = $this->seedTerminiFixtures();
        $user = User::factory()->create([
            'email_verified_at' => now(),
            'country' => 'OTHER',
            'lang' => 'en',
        ]);
        $vehicle = Vehicle::query()->create([
            'user_id' => $user->id,
            'license_plate' => 'KOFIXME1',
            'vehicle_type_id' => $fixtures['vt']->id,
        ]);

        $payment = Mockery::mock(PaymentService::class);
        $payment->shouldNotReceive('createSession');
        $this->app->instance(PaymentService::class, $payment);

        $this->actingAs($user)->post(route('checkout.store', [], false), [
            'auth_panel_booking' => 1,
            'payment_method' => 'card',
            'reservation_date' => $fixtures['date'],
            'drop_off_time_slot_id' => $fixtures['drop']->id,
            'pick_up_time_slot_id' => $fixtures['pick']->id,
            'vehicle_id' => $vehicle->id,
            'accept_terms' => 1,
            'accept_privacy' => 1,
        ])->assertSessionHas('error');

        $this->assertSame(0, TempData::query()->count());
        $this->assertDatabaseHas('admin_alerts', ['type' => BankartBillingCountryAlertService::TYPE]);
    }

    public function test_checkout_succeeds_after_profile_country_fixed(): void
    {
        $fixtures = $this->seedTerminiFixtures();
        $user = User::factory()->create([
            'email_verified_at' => now(),
            'country' => 'OTHER',
            'lang' => 'en',
        ]);
        $vehicle = Vehicle::query()->create([
            'user_id' => $user->id,
            'license_plate' => 'KOFIXME2',
            'vehicle_type_id' => $fixtures['vt']->id,
        ]);

        $this->actingAs($user)->patch('/profile', [
            'name' => $user->name,
            'email' => $user->email,
            'lang' => 'en',
            'country' => 'ME',
        ])->assertSessionHasNoErrors();

        $payment = Mockery::mock(PaymentService::class);
        $payment->shouldReceive('createSession')
            ->once()
            ->andReturn(new PaymentSessionResult(true, 'https://bank.test/pay', null));
        $this->app->instance(PaymentService::class, $payment);

        $this->actingAs($user->fresh())->post(route('checkout.store', [], false), [
            'auth_panel_booking' => 1,
            'payment_method' => 'card',
            'reservation_date' => $fixtures['date'],
            'drop_off_time_slot_id' => $fixtures['drop']->id,
            'pick_up_time_slot_id' => $fixtures['pick']->id,
            'vehicle_id' => $vehicle->id,
            'accept_terms' => 1,
            'accept_privacy' => 1,
        ])->assertRedirect('https://bank.test/pay');

        $this->assertTrue(BankartBillingCountry::isSelectablePaymentCountry($user->fresh()->country));
    }
}
