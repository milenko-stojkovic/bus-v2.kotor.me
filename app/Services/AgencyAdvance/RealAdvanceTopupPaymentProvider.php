<?php

namespace App\Services\AgencyAdvance;

use App\Contracts\PaymentSessionResult;
use App\Services\Payment\BankartBillingCountryAlertService;
use App\Support\BankartBillingCountry;
use App\Support\BankartSignature;
use App\Support\HttpOutboundConfig;
use App\Support\UiText;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Bankart session starter for agency advance topups.
 *
 * IMPORTANT: This does NOT use temp_data. It is a separate payment lifecycle.
 */
final class RealAdvanceTopupPaymentProvider
{
    public function createSession(string $merchantTransactionId, string $amount, string $successUrl, string $errorUrl, string $cancelUrl, ?string $email = null, ?string $country = null): PaymentSessionResult
    {
        $cfg = config('services.bankart');
        $apiBase = rtrim((string) ($cfg['api_url'] ?? ''), '/');
        $apiKey = (string) ($cfg['api_key'] ?? '');
        $username = (string) ($cfg['username'] ?? '');
        $password = (string) ($cfg['password'] ?? '');
        $sharedSecret = (string) ($cfg['shared_secret'] ?? '');
        $signatureEnabled = (bool) ($cfg['signature_enabled'] ?? true);
        $sendCustomer = (bool) ($cfg['send_customer'] ?? true);

        if ($apiBase === '' || $apiKey === '' || $username === '' || $password === '' || ($signatureEnabled && $sharedSecret === '')) {
            Log::channel('payments')->warning('advance_topup_create_session_failed', [
                'stage' => 'create_session',
                'reason' => 'missing_configuration',
                'merchant_transaction_id' => $merchantTransactionId,
                'has_api_url' => $apiBase !== '',
                'has_api_key' => $apiKey !== '',
                'has_username' => $username !== '',
                'has_password' => $password !== '',
                'has_shared_secret' => $sharedSecret !== '',
                'signature_enabled' => $signatureEnabled,
            ]);

            return PaymentSessionResult::unavailable(
                UiText::t('payment', 'payment_processing_issue', 'Payment temporarily unavailable.'),
            );
        }

        $currency = 'EUR';

        $billingCountry = null;
        if ($sendCustomer && is_string($email) && $email !== '') {
            $billingCountry = BankartBillingCountry::resolveForPayload($country);
            if ($billingCountry === null) {
                Log::channel('payments')->warning('checkout_billing_country_invalid', [
                    'stage' => 'advance_topup_create_session',
                    'merchant_transaction_id' => $merchantTransactionId,
                    'email' => $email,
                    'selected_country' => (string) ($country ?? ''),
                    'normalized_country' => BankartBillingCountry::normalize($country),
                ]);

                return PaymentSessionResult::unavailable(
                    app(BankartBillingCountryAlertService::class)->userMessage(),
                    null,
                    'invalid_billing_country',
                );
            }
        }

        Log::channel('payments')->info('advance_topup_create_session_request', [
            'stage' => 'create_session',
            'merchant_transaction_id' => $merchantTransactionId,
            'amount' => $amount,
            'currency' => $currency,
        ]);

        $path = '/transaction/'.$apiKey.'/debit';
        $url = $apiBase.$path;
        $signaturePath = BankartSignature::resolveSignaturePath($apiBase, $path);
        $contentType = 'application/json; charset=utf-8';
        $date = gmdate('D, d M Y H:i:s').' GMT';

        $payload = [
            'merchantTransactionId' => $merchantTransactionId,
            'amount' => $amount,
            'currency' => $currency,
            'successUrl' => $successUrl,
            'errorUrl' => $errorUrl,
            'cancelUrl' => $cancelUrl,
            'callbackUrl' => route('api.payment.callback'),
        ];

        if ($sendCustomer && is_string($email) && $email !== '') {
            $payload['customer'] = [
                'billingAddress1' => (string) (config('services.bankart.billing_address1') ?: ($country ? 'Address '.$country : 'Address')),
                'billingCity' => (string) (config('services.bankart.billing_city') ?: 'Kotor'),
                'billingCountry' => $billingCountry,
                'billingPostcode' => (string) (config('services.bankart.billing_postcode') ?: '85330'),
                'email' => $email,
            ];
        }

        $rawBody = json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        if ($rawBody === false) {
            Log::channel('payments')->error('advance_topup_create_session_failed', [
                'stage' => 'create_session',
                'reason' => 'payload_encode_failed',
                'merchant_transaction_id' => $merchantTransactionId,
            ]);

            return PaymentSessionResult::unavailable(
                UiText::t('payment', 'payment_processing_issue', 'Payment temporarily unavailable.'),
            );
        }

        $signature = null;
        if ($signatureEnabled) {
            $signature = BankartSignature::sign('POST', $rawBody, $contentType, $date, $signaturePath, $sharedSecret);
            if ($signature === null) {
                Log::channel('payments')->error('advance_topup_create_session_failed', [
                    'stage' => 'create_session',
                    'reason' => 'signing_failed',
                    'merchant_transaction_id' => $merchantTransactionId,
                ]);

                return PaymentSessionResult::unavailable(
                    UiText::t('payment', 'payment_processing_issue', 'Payment temporarily unavailable.'),
                );
            }
        }

        $headers = [
            'Accept' => 'application/json',
            'Content-Type' => $contentType,
        ];
        if ($signatureEnabled) {
            $headers['Date'] = $date;
            $headers['X-Signature'] = (string) $signature;
        }

        $httpCfg = HttpOutboundConfig::bankart('create_session');

        try {
            $response = Http::withBasicAuth($username, $password)
                ->withHeaders($headers)
                ->withBody($rawBody, $contentType)
                ->connectTimeout($httpCfg['connect_timeout'])
                ->timeout($httpCfg['timeout'])
                ->post($url);
        } catch (Throwable $e) {
            Log::channel('payments')->error('advance_topup_create_session_failed', [
                'stage' => 'create_session',
                'reason' => 'http_exception',
                'merchant_transaction_id' => $merchantTransactionId,
                'exception_class' => $e::class,
                'exception_message' => $e->getMessage(),
            ]);

            return PaymentSessionResult::unavailable(
                UiText::t('payment', 'payment_processing_issue', 'Payment temporarily unavailable.'),
            );
        }

        $data = $response->json();
        $redirectUrl = is_array($data) ? ($data['redirectUrl'] ?? null) : null;

        if ($response->successful() && is_string($redirectUrl) && $redirectUrl !== '') {
            Log::channel('payments')->info('advance_topup_create_session_response', [
                'stage' => 'create_session',
                'outcome' => 'success',
                'merchant_transaction_id' => $merchantTransactionId,
                'amount' => $amount,
                'currency' => $currency,
                'http_status' => $response->status(),
            ]);

            return PaymentSessionResult::ok($redirectUrl);
        }

        Log::channel('payments')->warning('advance_topup_create_session_failed', [
            'stage' => 'create_session',
            'reason' => 'gateway_rejected',
            'merchant_transaction_id' => $merchantTransactionId,
            'http_status' => $response->status(),
        ]);

        return PaymentSessionResult::unavailable(
            UiText::t('payment', 'payment_processing_issue', 'Payment temporarily unavailable.'),
        );
    }
}

