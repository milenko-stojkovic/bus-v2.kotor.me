<?php

namespace Tests\Feature\Payment;

use App\Models\ListOfTimeSlot;
use App\Models\TempData;
use App\Models\User;
use App\Models\VehicleType;
use App\Services\Payment\RealPaymentProvider;
use App\Support\BankartBillingCountry;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

final class CardBillingCountryWordingTest extends TestCase
{
    use RefreshDatabase;

    private const LABEL_CG = 'Država naplatne adrese kartice';

    private const LABEL_EN = 'Card billing country';

    private const HELP_CG = 'Odaberite državu koja odgovara naplatnoj adresi kartice kojom će biti izvršeno plaćanje.';

    private const HELP_EN = 'Select the country corresponding to the billing address of the payment card.';

    private const ADMIN_MSG_EN = 'If your country is not listed, contact the administrator at bus@kotor.me.';

    public function test_guest_form_uses_card_billing_country_label_and_help(): void
    {
        $html = $this->get(route('guest.reserve', [], false))->assertOk()->getContent();

        $this->assertStringContainsString(self::LABEL_EN, $html);
        $this->assertStringContainsString(self::HELP_EN, $html);
        $this->assertStringNotContainsString('value="OTHER"', $html);
    }

    public function test_guest_form_uses_cg_label_when_locale_is_cg(): void
    {
        $this->get('/locale/cg')->assertRedirect();

        $html = $this->get(route('guest.reserve', [], false))->assertOk()->getContent();

        $this->assertStringContainsString(self::LABEL_CG, $html);
        $this->assertStringContainsString(self::HELP_CG, $html);
    }

    public function test_agency_registration_uses_card_billing_country_label_and_help(): void
    {
        $html = $this->get('/register')->assertOk()->getContent();

        $this->assertStringContainsString(self::LABEL_EN, $html);
        $this->assertStringContainsString(self::HELP_EN, $html);
        $this->assertStringNotContainsString('value="OTHER"', $html);
    }

    public function test_agency_profile_uses_card_billing_country_label_and_help(): void
    {
        $user = User::factory()->create(['country' => 'ME']);

        $html = $this->actingAs($user)
            ->get(route('panel.user', [], false))
            ->assertOk()
            ->getContent();

        $this->assertStringContainsString(self::LABEL_EN, $html);
        $this->assertStringContainsString(self::HELP_EN, $html);
        $this->assertStringNotContainsString('value="OTHER"', $html);
    }

    public function test_other_does_not_exist_in_config_or_selectable_list(): void
    {
        $this->assertArrayNotHasKey('OTHER', (array) config('countries', []));
        $this->assertArrayNotHasKey('OTHER', BankartBillingCountry::selectableCountries());
        $this->assertFalse(BankartBillingCountry::isSelectablePaymentCountry('OTHER'));
        $this->assertNull(BankartBillingCountry::resolveForPayload('OTHER'));
    }

    public function test_country_is_mandatory_on_registration(): void
    {
        $this->from('/register')
            ->post('/register', [
                'name' => 'No Country Agency',
                'email' => 'no-country@example.com',
                'password' => 'password',
                'password_confirmation' => 'password',
            ])
            ->assertRedirect('/register')
            ->assertSessionHasErrors('country');
    }

    public function test_major_iso_countries_are_available_in_config(): void
    {
        foreach (['CN', 'JP', 'US', 'GB', 'ME', 'HR', 'XK'] as $code) {
            $this->assertArrayHasKey($code, BankartBillingCountry::selectableCountries(), "Missing country: {$code}");
        }

        $this->assertGreaterThanOrEqual(249, count(BankartBillingCountry::selectableCountryCodes()));
    }

    public function test_validation_message_includes_administrator_contact(): void
    {
        $this->from('/register')
            ->post('/register', [
                'name' => 'Bad Country',
                'country' => 'OTHER',
                'email' => 'bad@example.com',
                'password' => 'password',
                'password_confirmation' => 'password',
            ])
            ->assertSessionHasErrors('country');

        $errors = session('errors')->get('country');
        $this->assertStringContainsString('bus@kotor.me', (string) ($errors[0] ?? ''));
        $this->assertStringContainsString('administrator', strtolower((string) ($errors[0] ?? '')));
    }

    public function test_valid_iso_code_produces_successful_bankart_payload(): void
    {
        config([
            'services.bankart' => [
                'api_url' => 'https://bankart.test',
                'api_key' => 'api-key-1',
                'username' => 'user',
                'password' => 'pass',
                'shared_secret' => '',
                'signature_enabled' => false,
                'send_customer' => true,
            ],
        ]);

        $capturedBody = null;
        Http::fake(function ($request) use (&$capturedBody) {
            $capturedBody = (string) $request->body();

            return Http::response(['redirectUrl' => 'https://bank.test/pay'], 200);
        });

        $drop = ListOfTimeSlot::query()->create(['time_slot' => '10:00 - 10:20']);
        $pick = ListOfTimeSlot::query()->create(['time_slot' => '11:00 - 11:20']);
        $vt = VehicleType::query()->create(['price' => 12.00]);
        $temp = TempData::query()->create([
            'merchant_transaction_id' => 'mt-cn-billing',
            'drop_off_time_slot_id' => $drop->id,
            'pick_up_time_slot_id' => $pick->id,
            'reservation_date' => now()->addDay()->toDateString(),
            'user_name' => 'Guest',
            'country' => 'CN',
            'license_plate' => 'CN123',
            'vehicle_type_id' => $vt->id,
            'email' => 'cn@example.com',
            'status' => TempData::STATUS_PENDING,
        ]);
        $temp->load('vehicleType');

        $result = app(RealPaymentProvider::class)->createSession($temp);

        $this->assertTrue($result->success);
        $this->assertIsString($capturedBody);
        $this->assertStringContainsString('"billingCountry":"CN"', $capturedBody);
        $this->assertNull(BankartBillingCountry::resolveForPayload(''));
    }
}
