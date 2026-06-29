<?php

namespace Tests\Feature\Auth;

use App\Models\User;
use App\Notifications\NoreplyVerifyEmail;
use App\Support\BankartBillingCountry;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

final class AgencyCountryRegistrationTest extends TestCase
{
    use RefreshDatabase;

    private const ADMIN_MSG_EN = 'If your country is not listed, contact the administrator at bus@kotor.me.';

    public function test_registration_page_does_not_offer_other(): void
    {
        $html = $this->get('/register')->assertOk()->getContent();

        $this->assertStringNotContainsString('value="OTHER"', $html);
        $this->assertStringContainsString('value="ME"', $html);
    }

    public function test_registration_rejects_country_other(): void
    {
        $this->from('/register')
            ->post('/register', [
                'name' => 'Bad Country Agency',
                'country' => 'OTHER',
                'email' => 'other-agency@example.com',
                'password' => 'password',
                'password_confirmation' => 'password',
            ])
            ->assertRedirect('/register')
            ->assertSessionHasErrors('country');

        $errors = session('errors')->get('country');
        $this->assertStringContainsString('administrator', strtolower((string) ($errors[0] ?? '')));
        $this->assertSame(0, User::query()->count());
    }

    public function test_registration_accepts_valid_iso_country(): void
    {
        Notification::fake();

        $this->post('/register', [
            'name' => 'Valid Agency',
            'country' => 'HR',
            'email' => 'valid-agency@example.com',
            'password' => 'password',
            'password_confirmation' => 'password',
        ])->assertRedirect(route('verification.notice', absolute: false));

        $user = User::query()->where('email', 'valid-agency@example.com')->firstOrFail();
        $this->assertSame('HR', $user->country);
        Notification::assertSentTo($user, NoreplyVerifyEmail::class);
    }

    public function test_selectable_countries_exclude_other(): void
    {
        $this->assertArrayNotHasKey('OTHER', BankartBillingCountry::selectableCountries());
        $this->assertContains('ME', BankartBillingCountry::selectableCountryCodes());
    }
}
