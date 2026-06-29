<?php

namespace App\Services\Payment;

use App\Contracts\PaymentResult;
use App\Contracts\PaymentService;
use App\Contracts\PaymentSessionResult;
use App\Models\TempData;
use App\Support\BankartBillingCountry;
use App\Support\BankartSignature;
use App\Support\HttpOutboundConfig;
use App\Support\UiText;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use RuntimeException;
use Throwable;

class RealPaymentProvider implements PaymentService
{
    private function unavailableMessage(): string
    {
        return UiText::t(
            'payment',
            'payment_window_unavailable',
            'The payment window cannot be opened at the moment. Please try again in a few minutes. If the problem continues, contact bus@kotor.me.',
        );
    }

    private function billingCountryInvalidMessage(): string
    {
        return app(BankartBillingCountryAlertService::class)->userMessage();
    }

    public function createSession(TempData $tempData): PaymentSessionResult
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
            Log::channel('payments')->warning('bankart_create_session_failed', [
                'stage' => 'create_session',
                'reason' => 'missing_configuration',
                'merchant_transaction_id' => $tempData->merchant_transaction_id,
                'temp_data_id' => $tempData->id,
                'has_api_url' => $apiBase !== '',
                'has_api_key' => $apiKey !== '',
                'has_username' => $username !== '',
                'has_password' => $password !== '',
                'has_shared_secret' => $sharedSecret !== '',
                'signature_enabled' => $signatureEnabled,
            ]);

            return PaymentSessionResult::unavailable(
                $this->unavailableMessage(),
                null,
                'missing_configuration',
            );
        }

        $amount = $this->resolveAmount($tempData);
        if ($amount === null) {
            Log::channel('payments')->warning('bankart_create_session_failed', [
                'stage' => 'create_session',
                'reason' => 'missing_amount',
                'merchant_transaction_id' => $tempData->merchant_transaction_id,
                'temp_data_id' => $tempData->id,
                'vehicle_type_id' => $tempData->vehicle_type_id,
            ]);

            return PaymentSessionResult::unavailable(
                $this->unavailableMessage(),
                null,
                'missing_amount',
            );
        }

        $currency = 'EUR';

        $billingCountry = null;
        if ($sendCustomer) {
            $billingCountry = BankartBillingCountry::resolveForPayload($tempData->country);
            if ($billingCountry === null || ! BankartBillingCountry::isSelectablePaymentCountry($tempData->country)) {
                app(BankartBillingCountryAlertService::class)->notifyForTempData($tempData, 'create_session');

                return PaymentSessionResult::unavailable(
                    $this->billingCountryInvalidMessage(),
                    null,
                    'invalid_billing_country',
                );
            }
        }

        Log::channel('payments')->info('bankart_create_session_request', [
            'stage' => 'create_session',
            'merchant_transaction_id' => $tempData->merchant_transaction_id,
            'temp_data_id' => $tempData->id,
            'amount' => $amount,
            'currency' => $currency,
        ]);

        $path = '/transaction/'.$apiKey.'/debit';
        $url = $apiBase.$path;
        $signaturePath = BankartSignature::resolveSignaturePath($apiBase, $path);
        $contentType = 'application/json; charset=utf-8';
        $date = gmdate('D, d M Y H:i:s').' GMT';

        $payload = [
            'merchantTransactionId' => $tempData->merchant_transaction_id,
            'amount' => $amount,
            'currency' => $currency,
            'successUrl' => route('payment.return', ['merchant_transaction_id' => $tempData->merchant_transaction_id, 'bank_result' => 'success']),
            'errorUrl' => route('payment.return', ['merchant_transaction_id' => $tempData->merchant_transaction_id, 'bank_result' => 'error']),
            'cancelUrl' => route('payment.return', ['merchant_transaction_id' => $tempData->merchant_transaction_id, 'bank_result' => 'cancel']),
            'callbackUrl' => route('api.payment.callback'),
        ];
        if ($sendCustomer) {
            $payload['customer'] = [
                'billingAddress1' => (string) (config('services.bankart.billing_address1') ?: ($tempData->country ? 'Address '.$tempData->country : 'Address')),
                'billingCity' => (string) (config('services.bankart.billing_city') ?: 'Kotor'),
                'billingCountry' => $billingCountry,
                'billingPostcode' => (string) (config('services.bankart.billing_postcode') ?: '85330'),
                'email' => $tempData->email,
            ];
        }

        $rawBody = json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        if ($rawBody === false) {
            Log::channel('payments')->error('bankart_create_session_failed', [
                'stage' => 'create_session',
                'reason' => 'payload_encode_failed',
                'merchant_transaction_id' => $tempData->merchant_transaction_id,
                'temp_data_id' => $tempData->id,
                'amount' => $amount,
                'currency' => $currency,
                'http_status' => null,
                'response_body_preview' => null,
            ]);

            return PaymentSessionResult::unavailable(
                $this->unavailableMessage(),
                null,
                'payload_encode_failed',
            );
        }

        $signature = null;
        if ($signatureEnabled) {
            $signature = BankartSignature::sign('POST', $rawBody, $contentType, $date, $signaturePath, $sharedSecret);
            if ($signature === null) {
                Log::channel('payments')->error('bankart_create_session_failed', [
                    'stage' => 'create_session',
                    'reason' => 'signing_failed',
                    'merchant_transaction_id' => $tempData->merchant_transaction_id,
                    'temp_data_id' => $tempData->id,
                    'amount' => $amount,
                    'currency' => $currency,
                    'http_status' => null,
                    'response_body_preview' => null,
                ]);

                return PaymentSessionResult::unavailable(
                    $this->unavailableMessage(),
                    null,
                    'signing_failed',
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
            Log::channel('payments')->error('bankart_create_session_failed', [
                'stage' => 'create_session',
                'reason' => 'http_exception',
                'merchant_transaction_id' => $tempData->merchant_transaction_id,
                'temp_data_id' => $tempData->id,
                'amount' => $amount,
                'currency' => $currency,
                'http_status' => null,
                'exception_class' => $e::class,
                'exception_message' => $e->getMessage(),
                'response_body_preview' => null,
            ]);

            return PaymentSessionResult::unavailable(
                $this->unavailableMessage(),
                null,
                'http_exception',
            );
        }

        $httpStatus = $response->status();
        $bodyPreview = $this->truncateForLog($response->body());
        $data = $response->json();
        if (! is_array($data)) {
            Log::channel('payments')->error('bankart_create_session_failed', [
                'stage' => 'create_session',
                'reason' => 'invalid_json',
                'merchant_transaction_id' => $tempData->merchant_transaction_id,
                'temp_data_id' => $tempData->id,
                'amount' => $amount,
                'currency' => $currency,
                'http_status' => $httpStatus,
                'response_body_preview' => $bodyPreview,
            ]);

            return PaymentSessionResult::unavailable(
                $this->unavailableMessage(),
                $httpStatus,
                'invalid_json',
            );
        }

        $redirectUrl = $data['redirectUrl'] ?? null;
        if ($response->successful() && is_string($redirectUrl) && $redirectUrl !== '') {
            Log::channel('payments')->info('bankart_create_session_response', [
                'stage' => 'create_session',
                'outcome' => 'success',
                'merchant_transaction_id' => $tempData->merchant_transaction_id,
                'temp_data_id' => $tempData->id,
                'amount' => $amount,
                'currency' => $currency,
                'http_status' => $httpStatus,
                'uuid' => $data['uuid'] ?? null,
                'purchase_id' => $data['purchaseId'] ?? null,
                'payment_method' => $data['paymentMethod'] ?? null,
                'return_type' => $data['returnType'] ?? null,
            ]);

            return PaymentSessionResult::ok($redirectUrl);
        }

        $rawCode = $data['code'] ?? $data['errorCode'] ?? null;
        $rawMessage = $data['message'] ?? $data['error'] ?? $response->reason();
        $payloadForClassifier = array_merge($data, [
            'merchantTransactionId' => $tempData->merchant_transaction_id,
            'merchant_transaction_id' => $tempData->merchant_transaction_id,
        ]);

        $classified = app(ErrorClassifier::class)->classify(
            'bankart',
            is_scalar($rawCode) ? $rawCode : null,
            is_string($rawMessage) ? $rawMessage : null,
            $payloadForClassifier,
            [
                'stage' => 'create_session',
                'http_status' => $httpStatus,
                'amount' => $amount,
                'currency' => $currency,
                'temp_data_id' => $tempData->id,
            ],
        );

        Log::channel('payments')->warning('bankart_create_session_failed', [
            'stage' => 'create_session',
            'reason' => 'gateway_rejected',
            'merchant_transaction_id' => $tempData->merchant_transaction_id,
            'temp_data_id' => $tempData->id,
            'amount' => $amount,
            'currency' => $currency,
            'http_status' => $httpStatus,
            'response_body_preview' => $this->truncateForLog((string) json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)),
            'bank_code' => $rawCode,
            'bank_message_truncated' => $this->truncateForLog(is_string($rawMessage) ? $rawMessage : null, 500),
            'resolution_reason' => $classified['resolution_reason'],
        ]);

        $userLine = UiText::t('payment', $classified['user_message_key'], 'Payment temporarily unavailable.');

        return PaymentSessionResult::unavailable($userLine, $httpStatus, 'gateway_rejected');
    }

    public function pay(TempData $tempData): PaymentResult
    {
        throw new RuntimeException('Real payment provider not implemented. Set BANK_DRIVER=fake or implement gateway call here.');
    }

    private function resolveAmount(TempData $tempData): ?string
    {
        $price = $tempData->vehicleType?->price;
        if ($price === null) {
            return null;
        }
        if (! is_numeric((string) $price)) {
            return null;
        }

        return number_format((float) $price, 2, '.', '');
    }

    private function truncateForLog(?string $raw, int $max = 4000): ?string
    {
        if ($raw === null) {
            return null;
        }
        if (strlen($raw) <= $max) {
            return $raw;
        }

        return substr($raw, 0, $max).'…[truncated]';
    }
}
