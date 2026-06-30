<?php

namespace App\Http\Controllers\Api;

use App\Contracts\CallbackSignatureValidator;
use App\Http\Controllers\Controller;
use App\Jobs\PaymentCallbackJob;
use App\Models\TempData;
use App\Services\Payment\PaymentCallbackDuplicateTerminalAckService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Validator;
use Symfony\Component\HttpFoundation\Response;

/**
 * Bank callback endpoint – API only. Fully stateless.
 *
 * - No session, no cookies, no redirects.
 * - Returns Bankart ACK: HTTP 200 + body `OK` (text/plain), or 400 Bad Request on errors.
 * - User redirect is handled later via frontend polling GET /payment/result.
 */
class PaymentCallbackController extends Controller
{
    /**
     * Validate bank signature (first), then payload; dispatch job; return Bankart ACK or 400.
     */
    public function handle(
        Request $request,
        CallbackSignatureValidator $signatureValidator,
        PaymentCallbackDuplicateTerminalAckService $duplicateTerminalAck,
    ): Response {
        Log::channel('payments')->info('Payment callback received', [
            'ip' => $request->ip(),
            'content_type' => $request->header('Content-Type'),
            'has_signature' => $request->header('X-Signature') !== null,
        ]);

        if (! $signatureValidator->validate($request)) {
            $txId = $this->extractMerchantTransactionIdFromRequest($request);
            $this->auditTempDataCallback400($txId, 'invalid_signature', 'Invalid callback signature');

            Log::channel('payments')->warning('Payment callback signature invalid', [
                'ip' => $request->ip(),
                'merchant_transaction_id' => $txId,
            ]);

            return response()->json(['message' => 'Invalid callback signature.'], 400);
        }

        $rawBody = $request->getContent();
        $decoded = json_decode($rawBody, true);
        if (! is_array($decoded)) {
            $txId = $this->extractMerchantTransactionIdFromRequest($request);
            $this->auditTempDataCallback400($txId, 'malformed_callback', 'Malformed callback payload');

            Log::channel('payments')->warning('Payment callback malformed JSON', [
                'ip' => $request->ip(),
                'merchant_transaction_id' => $txId,
            ]);

            return response()->json(['message' => 'Malformed callback JSON.'], 400);
        }

        $normalized = $this->normalizePayload($decoded);

        $validator = Validator::make($normalized, [
            'merchant_transaction_id' => ['required', 'string', 'max:64'],
            'status' => ['required', 'string', 'in:success,failed,timeout,CANCEL,ERROR'],
            'error_code' => ['nullable', 'string', 'max:64'],
            'error_reason' => ['nullable', 'string', 'max:500'],
        ]);
        if ($validator->fails()) {
            $txId = is_string($normalized['merchant_transaction_id'] ?? null) ? $normalized['merchant_transaction_id'] : null;
            $this->auditTempDataCallback400($txId, 'malformed_callback', 'Malformed callback payload');

            Log::channel('payments')->warning('Payment callback payload invalid', [
                'merchant_transaction_id' => $txId,
                'status' => $normalized['status'] ?? null,
                'errors' => $validator->errors()->toArray(),
            ]);

            return response()->json(['message' => 'Invalid callback payload.'], 400);
        }
        $validated = $validator->validated();

        $duplicateAck = $duplicateTerminalAck->contextForImmediateAck(
            $validated['merchant_transaction_id'],
            $validated['status'],
        );
        if ($duplicateAck !== null) {
            Log::channel('payments')->info('payment_callback_duplicate_terminal_acknowledged', [
                'merchant_transaction_id' => $validated['merchant_transaction_id'],
                'temp_data_id' => $duplicateAck['temp_data_id'],
                'reservation_id' => $duplicateAck['reservation_id'],
                'temp_status' => $duplicateAck['temp_status'],
                'callback_status' => $validated['status'],
                'received_at' => now()->toIso8601String(),
                'ip' => $request->ip(),
            ]);

            return $this->bankartAck();
        }

        Log::channel('payments')->info('Payment callback signature valid', [
            'ip' => $request->ip(),
        ]);
        Log::channel('payments')->info('Payment callback normalized payload', [
            'merchant_transaction_id' => $normalized['merchant_transaction_id'] ?? null,
            'status' => $normalized['status'] ?? null,
            'result' => $decoded['result'] ?? null,
            'raw_status' => $decoded['status'] ?? null,
        ]);

        $rawPayload = $decoded;

        Log::channel('payments')->info('Payment callback accepted', [
            'merchant_transaction_id' => $validated['merchant_transaction_id'],
            'status' => $validated['status'],
            'ip' => $request->ip(),
        ]);
        if (config('app.debug')) {
            Log::channel('payments')->debug('Payment callback payload', ['payload' => $rawPayload]);
        }

        PaymentCallbackJob::dispatch($validated, $rawPayload);
        Log::channel('payments')->info('Payment callback job dispatched', [
            'merchant_transaction_id' => $validated['merchant_transaction_id'],
            'status' => $validated['status'],
        ]);

        return $this->bankartAck();
    }

    /** Bankart/NLB postback ACK: HTTP 200 + plain text body `OK`. */
    private function bankartAck(): Response
    {
        return response('OK', 200)->header('Content-Type', 'text/plain');
    }

    /**
     * Real bank payload compatibility: support camelCase tx key and result-only callbacks.
     *
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    private function normalizePayload(array $payload): array
    {
        $txId = $payload['merchant_transaction_id']
            ?? $payload['merchantTransactionId']
            ?? null;

        $status = $payload['status'] ?? null;
        if ($status === null && isset($payload['result'])) {
            $result = strtoupper((string) $payload['result']);
            $status = match ($result) {
                'OK' => 'success',
                'CANCEL', 'ERROR', 'FAILED' => 'failed',
                default => null,
            };
        }

        if (is_string($status) && strtoupper($status) === 'OK') {
            $status = 'success';
        }

        return [
            ...$payload,
            'merchant_transaction_id' => $txId,
            'status' => $status,
            'error_code' => $payload['error_code'] ?? $payload['errorCode'] ?? null,
            'error_reason' => $payload['error_reason'] ?? $payload['errorReason'] ?? null,
        ];
    }

    private function extractMerchantTransactionIdFromRequest(Request $request): ?string
    {
        $txId = $request->input('merchant_transaction_id') ?? $request->input('merchantTransactionId');
        if (is_string($txId)) {
            $txId = trim($txId);
            if ($txId !== '' && mb_strlen($txId) <= 64) {
                return $txId;
            }
        }

        $rawBody = $request->getContent();
        if (! is_string($rawBody) || trim($rawBody) === '') {
            return null;
        }
        $decoded = json_decode($rawBody, true);
        if (! is_array($decoded)) {
            return null;
        }
        $txId = $decoded['merchant_transaction_id'] ?? $decoded['merchantTransactionId'] ?? null;
        if (! is_string($txId)) {
            return null;
        }
        $txId = trim($txId);

        return ($txId !== '' && mb_strlen($txId) <= 64) ? $txId : null;
    }

    private function auditTempDataCallback400(?string $merchantTransactionId, string $code, string $reason): void
    {
        if (! is_string($merchantTransactionId) || $merchantTransactionId === '') {
            return;
        }

        $temp = TempData::query()
            ->where('merchant_transaction_id', $merchantTransactionId)
            ->first();
        if (! $temp) {
            return;
        }

        $temp->update([
            'callback_error_code' => $code,
            'callback_error_reason' => $reason,
            'resolution_reason' => $code,
        ]);

        Log::channel('payments')->info('Payment callback 400 audited to temp_data', [
            'merchant_transaction_id' => $merchantTransactionId,
            'callback_error_code' => $code,
        ]);
    }
}
