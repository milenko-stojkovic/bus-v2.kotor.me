<?php

namespace Tests\Feature\Panel;

use App\Jobs\PaymentCallbackJob;
use App\Models\AgencyAdvanceTopup;
use App\Models\AgencyAdvanceTransaction;
use App\Models\User;
use Database\Seeders\UiTranslationsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use Tests\TestCase;

final class AdvanceTopupResultFeedbackTest extends TestCase
{
    use RefreshDatabase;

    private const SECRET = 'RAW-BANK-SECRET-DO-NOT-SHOW';

    protected function setUp(): void
    {
        parent::setUp();
        config(['features.advance_payments' => true]);
        $this->seed(UiTranslationsSeeder::class);
        Mail::fake();
    }

    public function test_browser_success_flag_does_not_mark_paid_and_callback_then_shows_success(): void
    {
        $user = $this->agency('en');
        $topup = $this->pendingTopup($user);

        $this->actingAs($user)
            ->get($this->returnUrl($topup->merchant_transaction_id, 'success'))
            ->assertOk()
            ->assertSee('Advance top-up is being processed', false)
            ->assertSee('/panel/avans/result', false)
            ->assertDontSee(self::SECRET, false);

        $topup->refresh();
        $this->assertSame(AgencyAdvanceTopup::STATUS_PENDING, (string) $topup->status);
        $this->assertSame(0, AgencyAdvanceTransaction::query()->count());

        $this->runCallback($topup->merchant_transaction_id, 'success', ['result' => 'OK', 'error_reason' => self::SECRET]);

        $this->actingAs($user)
            ->get($this->returnUrl($topup->merchant_transaction_id, 'success'))
            ->assertRedirect(route('panel.advance.index', [], false));

        $this->actingAs($user)
            ->get(route('panel.advance.index', [], false))
            ->assertOk()
            ->assertSee('Advance top-up was successfully recorded.', false)
            ->assertSee('The amount has been added to your available advance.', false)
            ->assertDontSee(self::SECRET, false);

        $this->assertSame(1, AgencyAdvanceTransaction::query()->count());
    }

    public function test_failed_topup_shows_classified_explanation_without_raw_payload(): void
    {
        $user = $this->agency('en');
        $topup = $this->pendingTopup($user);

        $this->runCallback($topup->merchant_transaction_id, 'ERROR', [
            'status' => 'ERROR',
            'error_code' => '2003',
            'error_reason' => self::SECRET,
            'cardNumber' => '4111111111111111',
        ]);

        $this->actingAs($user)
            ->get($this->returnUrl($topup->merchant_transaction_id, 'error'))
            ->assertRedirect(route('panel.advance.index', [], false));

        $this->actingAs($user)
            ->get(route('panel.advance.index', [], false))
            ->assertOk()
            ->assertSee('The advance top-up was not approved.', false)
            ->assertSee('The bank declined the card authorization. No funds were added to your advance.', false)
            ->assertDontSee(self::SECRET, false)
            ->assertDontSee('4111111111111111', false);

        $this->assertSame(0, AgencyAdvanceTransaction::query()->count());
    }

    public function test_cancelled_payment_is_distinct_when_the_stored_response_says_cancel(): void
    {
        $user = $this->agency('en');
        $topup = $this->pendingTopup($user);

        $this->runCallback($topup->merchant_transaction_id, 'CANCEL', [
            'status' => 'CANCEL',
            'error_reason' => self::SECRET,
        ]);

        $topup->refresh();
        $this->assertSame(AgencyAdvanceTopup::STATUS_FAILED, (string) $topup->status);

        $this->actingAs($user)
            ->followingRedirects()
            ->get($this->returnUrl($topup->merchant_transaction_id, 'cancel'))
            ->assertOk()
            ->assertSee('The advance top-up was cancelled.', false)
            ->assertSee('No funds were added to your advance. You can try again.', false)
            ->assertDontSee(self::SECRET, false);
    }

    public function test_pending_callback_then_success_is_visible_through_the_result_endpoint(): void
    {
        $user = $this->agency('en');
        $topup = $this->pendingTopup($user);

        $this->actingAs($user)
            ->get(route('panel.advance.result', ['merchant_transaction_id' => $topup->merchant_transaction_id], false))
            ->assertOk()
            ->assertJson(['status' => 'pending']);

        $this->runCallback($topup->merchant_transaction_id, 'success', ['result' => 'OK']);
        $this->runCallback($topup->merchant_transaction_id, 'success', ['result' => 'OK']);

        $this->actingAs($user)
            ->get(route('panel.advance.result', ['merchant_transaction_id' => $topup->merchant_transaction_id], false))
            ->assertOk()
            ->assertJson(['status' => 'success'])
            ->assertJsonMissingPath('bank_payload')
            ->assertJsonMissingPath('error_reason');

        $this->assertSame(1, AgencyAdvanceTransaction::query()->count());
    }

    public function test_pending_callback_then_failure_does_not_credit_the_ledger(): void
    {
        $user = $this->agency('en');
        $topup = $this->pendingTopup($user);

        $this->actingAs($user)
            ->get(route('panel.advance.result', ['merchant_transaction_id' => $topup->merchant_transaction_id], false))
            ->assertJson(['status' => 'pending']);

        $this->runCallback($topup->merchant_transaction_id, 'failed', [
            'status' => 'failed',
            'error_code' => '2006',
            'error_reason' => self::SECRET,
        ]);

        $this->actingAs($user)
            ->get(route('panel.advance.result', ['merchant_transaction_id' => $topup->merchant_transaction_id], false))
            ->assertOk()
            ->assertJson(['status' => 'failed'])
            ->assertDontSee(self::SECRET, false);

        $this->actingAs($user)
            ->followingRedirects()
            ->get($this->returnUrl($topup->merchant_transaction_id, 'error'))
            ->assertSee('There are insufficient funds on the card. No funds were added to your advance.', false)
            ->assertDontSee(self::SECRET, false);

        $this->assertSame(0, AgencyAdvanceTransaction::query()->count());
    }

    public function test_missing_or_invalid_reference_shows_unconfirmed_without_a_ledger_change(): void
    {
        $user = $this->agency('en');

        $this->actingAs($user)
            ->followingRedirects()
            ->get(route('panel.advance.return', [], false))
            ->assertOk()
            ->assertSee('The advance top-up result could not be confirmed.', false);

        $this->actingAs($user)
            ->followingRedirects()
            ->get(route('panel.advance.return', ['merchant_transaction_id' => (string) Str::uuid()], false))
            ->assertOk()
            ->assertSee('The advance top-up result could not be confirmed.', false);

        $this->actingAs($user)
            ->get(route('panel.advance.result', ['merchant_transaction_id' => 'not-a-real-topup'], false))
            ->assertNotFound()
            ->assertJson(['status' => 'failed']);

        $this->assertSame(0, AgencyAdvanceTopup::query()->count());
    }

    public function test_another_agencys_topup_is_not_readable(): void
    {
        $owner = $this->agency('en');
        $other = $this->agency('en');
        $topup = $this->pendingTopup($owner, [
            'status' => AgencyAdvanceTopup::STATUS_FAILED,
            'bank_payload' => [
                'status' => 'ERROR',
                'error_reason' => self::SECRET,
                'cardNumber' => '5555444433331111',
            ],
            'failed_at' => now(),
        ]);

        $this->actingAs($other)
            ->get(route('panel.advance.result', ['merchant_transaction_id' => $topup->merchant_transaction_id], false))
            ->assertNotFound()
            ->assertJson(['status' => 'failed'])
            ->assertDontSee(self::SECRET, false)
            ->assertDontSee('5555444433331111', false);

        $this->actingAs($other)
            ->followingRedirects()
            ->get($this->returnUrl($topup->merchant_transaction_id, 'error'))
            ->assertOk()
            ->assertSee('The advance top-up result could not be confirmed.', false)
            ->assertDontSee(self::SECRET, false)
            ->assertDontSee('5555444433331111', false)
            ->assertDontSee((string) $topup->amount, false);
    }

    public function test_create_session_failure_is_visible_on_the_advance_page(): void
    {
        config(['services.bank.driver' => 'bankart']);
        config([
            'services.bankart.api_url' => null,
            'services.bankart.api_key' => null,
            'services.bankart.username' => null,
            'services.bankart.password' => null,
        ]);

        $user = $this->agency('en');

        $this->actingAs($user)
            ->followingRedirects()
            ->post(route('panel.advance.topup.store', [], false), ['amount' => '100'])
            ->assertOk()
            ->assertSee('Advance top-up could not be started.', false);

        $topup = AgencyAdvanceTopup::query()->firstOrFail();
        $this->assertSame(AgencyAdvanceTopup::STATUS_FAILED, (string) $topup->status);
        $this->assertSame(0, AgencyAdvanceTransaction::query()->count());
    }

    public function test_messages_follow_the_agency_language(): void
    {
        $en = $this->agency('en');
        $cg = $this->agency('cg');
        $enTopup = $this->pendingTopup($en);
        $cgTopup = $this->pendingTopup($cg);

        $this->runCallback($enTopup->merchant_transaction_id, 'CANCEL', ['status' => 'CANCEL']);
        $this->runCallback($cgTopup->merchant_transaction_id, 'CANCEL', ['status' => 'CANCEL']);

        $this->actingAs($en)
            ->followingRedirects()
            ->get($this->returnUrl($enTopup->merchant_transaction_id, 'cancel'))
            ->assertSee('The advance top-up was cancelled.', false)
            ->assertDontSee('Avansna uplata je otkazana.', false);

        $this->actingAs($cg)
            ->followingRedirects()
            ->get($this->returnUrl($cgTopup->merchant_transaction_id, 'cancel'))
            ->assertSee('Avansna uplata je otkazana.', false)
            ->assertSee('Sredstva nisu dodata na avans. Možete pokušati ponovo.', false);
    }

    private function agency(string $lang): User
    {
        return User::factory()->create([
            'email_verified_at' => now(),
            'lang' => $lang,
        ]);
    }

    /**
     * @param  array<string, mixed>  $overrides
     */
    private function pendingTopup(User $user, array $overrides = []): AgencyAdvanceTopup
    {
        return AgencyAdvanceTopup::query()->create(array_merge([
            'agency_user_id' => $user->id,
            'merchant_transaction_id' => (string) Str::uuid(),
            'amount' => '100.00',
            'status' => AgencyAdvanceTopup::STATUS_PENDING,
        ], $overrides));
    }

    private function returnUrl(string $merchantTransactionId, string $bankResult): string
    {
        return route('panel.advance.return', [
            'merchant_transaction_id' => $merchantTransactionId,
            'bank_result' => $bankResult,
        ], false);
    }

    /**
     * @param  array<string, mixed>  $raw
     */
    private function runCallback(string $merchantTransactionId, string $status, array $raw): void
    {
        (new PaymentCallbackJob([
            'merchant_transaction_id' => $merchantTransactionId,
            'status' => $status,
        ], $raw))->handle();
    }
}
