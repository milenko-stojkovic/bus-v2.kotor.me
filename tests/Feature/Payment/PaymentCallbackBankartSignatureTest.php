<?php

namespace Tests\Feature\Payment;

use App\Jobs\PaymentCallbackJob;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class PaymentCallbackBankartSignatureTest extends TestCase
{
    use RefreshDatabase;

    private const SECRET = 'test-bankart-shared-secret-for-hmac';

    private const DATE = 'Thu, 01 Jan 2026 12:00:00 GMT';

    private const CONTENT_TYPE = 'application/json; charset=utf-8';

    private function useBankartDriver(): void
    {
        Config::set('services.bank.driver', 'bankart');
        Config::set('services.bankart.shared_secret', self::SECRET);
    }

    /**
     * Same construction as {@see \App\Services\Payment\RealCallbackSignatureValidator}.
     */
    private function bankartXSignature(string $rawBody): string
    {
        $path = '/api/payment/callback';
        $bodyHash = hash('sha512', $rawBody);
        $message = implode("\n", [
            'POST',
            $bodyHash,
            self::CONTENT_TYPE,
            self::DATE,
            $path,
        ]);

        return base64_encode(hash_hmac('sha512', $message, self::SECRET, true));
    }

    private function postCallback(string $rawBody, array $headers = []): \Illuminate\Testing\TestResponse
    {
        $default = [
            'CONTENT_TYPE' => self::CONTENT_TYPE,
            'HTTP_ACCEPT' => 'application/json',
            'HTTP_DATE' => self::DATE,
        ];

        return $this->call('POST', '/api/payment/callback', [], [], [], array_merge($default, $headers), $rawBody);
    }

    public function test_valid_bankart_hmac_callback_is_accepted_and_dispatches_job(): void
    {
        Queue::fake();
        $this->useBankartDriver();

        $rawBody = json_encode([
            'merchant_transaction_id' => 'mt-hmac-ok-1',
            'status' => 'success',
        ], JSON_THROW_ON_ERROR);

        $sig = $this->bankartXSignature($rawBody);

        $response = $this->postCallback($rawBody, [
            'HTTP_X_SIGNATURE' => $sig,
        ]);

        $response->assertOk();
        $this->assertSame('OK', $response->getContent());
        Queue::assertPushed(PaymentCallbackJob::class, function (PaymentCallbackJob $job): bool {
            return ($job->payload['merchant_transaction_id'] ?? null) === 'mt-hmac-ok-1';
        });
    }

    public function test_invalid_hmac_callback_is_rejected(): void
    {
        Queue::fake();
        $this->useBankartDriver();

        // No merchant_transaction_id: signature fails before payload rules; audit skips DB lookup.
        $rawBody = json_encode(['status' => 'success'], JSON_THROW_ON_ERROR);

        $response = $this->postCallback($rawBody, [
            'HTTP_X_SIGNATURE' => base64_encode(hash_hmac('sha512', 'wrong-message', self::SECRET, true)),
        ]);

        $response->assertStatus(400)
            ->assertJsonPath('message', 'Invalid callback signature.');
        Queue::assertNothingPushed();
    }

    public function test_missing_x_signature_callback_is_rejected(): void
    {
        Queue::fake();
        $this->useBankartDriver();

        $rawBody = json_encode(['status' => 'success'], JSON_THROW_ON_ERROR);

        $response = $this->postCallback($rawBody);

        $response->assertStatus(400)
            ->assertJsonPath('message', 'Invalid callback signature.');
        Queue::assertNothingPushed();
    }

    public function test_missing_shared_secret_rejects_callback(): void
    {
        Queue::fake();
        Config::set('services.bank.driver', 'bankart');
        Config::set('services.bankart.shared_secret', null);

        $rawBody = json_encode(['status' => 'success'], JSON_THROW_ON_ERROR);

        $response = $this->postCallback($rawBody, [
            'HTTP_X_SIGNATURE' => 'any-signature',
        ]);

        $response->assertStatus(400);
        Queue::assertNothingPushed();
    }

    public function test_fake_bank_driver_does_not_require_hmac(): void
    {
        Queue::fake();
        Config::set('services.bank.driver', 'fake');
        Config::set('services.bankart.shared_secret', self::SECRET);

        $rawBody = json_encode([
            'merchant_transaction_id' => 'mt-fake-no-hmac',
            'status' => 'success',
        ], JSON_THROW_ON_ERROR);

        $response = $this->postCallback($rawBody);

        $response->assertOk();
        $this->assertSame('OK', $response->getContent());
        Queue::assertPushed(PaymentCallbackJob::class, function (PaymentCallbackJob $job): bool {
            return ($job->payload['merchant_transaction_id'] ?? null) === 'mt-fake-no-hmac';
        });
    }
}
