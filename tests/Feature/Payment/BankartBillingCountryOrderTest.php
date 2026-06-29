<?php

namespace Tests\Feature\Payment;

use App\Models\User;
use App\Support\BankartBillingCountry;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

final class BankartBillingCountryOrderTest extends TestCase
{
    use RefreshDatabase;

    private const PREFERRED_PREFIX = [
        'ME', 'RS', 'HR', 'MK', 'BA', 'AL', 'HU', 'GR', 'TR', 'SI', 'UA', 'LT',
        'BG', 'PL', 'RO', 'MD', 'DE', 'FR', 'XK', 'SE', 'CZ', 'NL', 'SK',
    ];

    public function test_preferred_countries_appear_first_in_fixed_order(): void
    {
        $codes = array_keys(BankartBillingCountry::selectableCountries('en'));
        $config = (array) config('countries', []);

        $expectedPrefix = array_values(array_filter(
            self::PREFERRED_PREFIX,
            static fn (string $code): bool => isset($config[$code]),
        ));

        $this->assertSame($expectedPrefix, array_slice($codes, 0, count($expectedPrefix)));
    }

    public function test_remaining_countries_are_sorted_alphabetically_by_locale(): void
    {
        foreach (['en', 'cg'] as $locale) {
            $countries = BankartBillingCountry::selectableCountries($locale);
            $codes = array_keys($countries);
            $config = (array) config('countries', []);

            $preferredCount = count(array_filter(
                self::PREFERRED_PREFIX,
                static fn (string $code): bool => isset($config[$code]),
            ));
            $remainingCodes = array_slice($codes, $preferredCount);

            $labels = [];
            foreach ($remainingCodes as $code) {
                $labels[] = $countries[$code][$locale] ?? $countries[$code]['en'];
            }

            $sorted = $labels;
            usort($sorted, static fn (string $a, string $b): int => strcasecmp($a, $b));

            $this->assertSame($sorted, $labels, "Remaining countries not sorted for locale: {$locale}");
        }
    }

    public function test_no_duplicate_country_codes(): void
    {
        $codes = BankartBillingCountry::selectableCountryCodes('en');
        $configCount = count((array) config('countries', []));

        $this->assertSame($configCount, count($codes));
        $this->assertSame($configCount, count(array_unique($codes)));
    }

    public function test_preferred_prefix_is_identical_for_cg_and_en_locales(): void
    {
        $enPrefix = array_slice(array_keys(BankartBillingCountry::selectableCountries('en')), 0, count(self::PREFERRED_PREFIX));
        $cgPrefix = array_slice(array_keys(BankartBillingCountry::selectableCountries('cg')), 0, count(self::PREFERRED_PREFIX));

        $this->assertSame($enPrefix, $cgPrefix);
    }

    public function test_guest_registration_and_profile_render_preferred_order_first(): void
    {
        $expectedFirst = 'ME';

        $guestHtml = $this->get(route('guest.reserve', [], false))->assertOk()->getContent();
        $this->assertCountryOptionOrderStartsWith($guestHtml, $expectedFirst);

        $registerHtml = $this->get('/register')->assertOk()->getContent();
        $this->assertCountryOptionOrderStartsWith($registerHtml, $expectedFirst);

        $user = User::factory()->create(['country' => 'ME']);
        $profileHtml = $this->actingAs($user)
            ->get(route('panel.user', [], false))
            ->assertOk()
            ->getContent();
        $this->assertCountryOptionOrderStartsWith($profileHtml, $expectedFirst);
    }

    private function assertCountryOptionOrderStartsWith(string $html, string $expectedFirstCode): void
    {
        preg_match_all('/<option value="([A-Z]{2})"/', $html, $matches);
        $codes = $matches[1] ?? [];
        $codes = array_values(array_filter($codes, static fn (string $c): bool => $c !== ''));

        $this->assertNotEmpty($codes, 'No country options found in HTML');
        $this->assertSame($expectedFirstCode, $codes[0]);
        $this->assertSame('RS', $codes[1] ?? null);
        $this->assertSame('HR', $codes[2] ?? null);
    }
}
