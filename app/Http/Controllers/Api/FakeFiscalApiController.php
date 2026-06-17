<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

/**
 * Fake eksterni fiskal servis – samo za test.
 *
 * Odgovor (success/error shape) mora ostati kompatibilan sa pravim provajderom — vidi {@see \App\Services\FiscalizationService} (fake driver MUST mirror real API contract).
 *
 * Rute: POST /api/efiscal/deposit, POST /api/efiscal/fiscalReceipt.
 * - forceFail=true (query/JSON) ili header X-Fake-Fail: 1 → Error payload.
 * - Header X-Fake-Timeout: 1 → sleep 10s, zatim 504.
 * Inače success. Ne koristi bazu.
 */
class FakeFiscalApiController extends Controller
{
    /**
     * POST /api/efiscal/deposit – simulacija inicijacije depozita.
     */
    public function deposit(Request $request): JsonResponse
    {
        return $this->respond($request);
    }

    /**
     * POST /api/efiscal/fiscalReceipt – simulacija zahteva za fiskalni račun.
     */
    public function fiscalReceipt(Request $request): JsonResponse
    {
        return $this->respond($request);
    }

    private function respond(Request $request): JsonResponse
    {
        // Keep fake simulation close to real: Operator should come from request identity (ENUIdentifier),
        // not from server-side config.
        // Operator code on receipt = User.UserCode (Primatech); fallback to ENU for legacy payloads.
        $operator = data_get($request->all(), 'User.UserCode')
            ?? $request->input('ENUIdentifier')
            ?? $request->json('ENUIdentifier')
            ?? null;

        $documentNumber = $request->input('DocumentNumber') ?? $request->json('DocumentNumber') ?? null;
        $documentNumber = is_numeric($documentNumber) ? (int) $documentNumber : null;
        $createdAt = $request->input('DateSend')
            ?? $request->json('DateSend')
            ?? $request->input('CreatedAt')
            ?? $request->json('CreatedAt')
            ?? null;
        $createdAt = is_string($createdAt) && $createdAt !== '' ? $createdAt : now()->toIso8601String();
        $price = $request->input('Price') ?? $request->json('Price') ?? null;
        if (! is_numeric($price)) {
            $paymentRows = data_get($request->all(), 'Payments.PaymentRow');
            if (is_array($paymentRows) && isset($paymentRows[0]['PaymentAmount']) && is_numeric($paymentRows[0]['PaymentAmount'])) {
                $price = $paymentRows[0]['PaymentAmount'];
            }
        }
        $price = is_numeric($price) ? number_format((float) $price, 2, '.', '') : '50.00';

        $buildVerifyUrl = function (string $iic, string $operator, ?int $documentNumber, string $createdAt, string $price): string {
            $params = [
                'iic' => $iic,
                'tin' => '02012936',
                'crtd' => $createdAt,
                'ord' => $documentNumber ?? random_int(10000, 99999),
                'bu' => 'ml068la705',
                'cr' => $operator,
                'sw' => 'to030bx579',
                'prc' => $price,
            ];

            return 'https://mapr.tax.gov.me/ic/#/verify?'.http_build_query($params);
        };

        $scenario = $request->string('scenario')->toString();
        if ($scenario === '') {
            $scenario = (string) ($request->header('X-Fake-Scenario') ?? '');
        }
        if ($scenario === '') {
            $scenario = (string) ($request->json('scenario') ?? '');
        }
        $scenario = trim($scenario);

        if ($scenario === 'timeout') {
            sleep(10);
            return response()->json([
                'IsSucccess' => false,
                'Error' => [
                    'ErrorCode' => '504',
                    'ErrorMessage' => 'Fake timeout (gateway timeout)',
                ],
            ], 504);
        }

        if ($scenario === 'malformed_response') {
            return response('NOT_JSON_RESPONSE', 200)->header('Content-Type', 'text/plain; charset=utf-8');
        }

        if ($request->header('X-Fake-Timeout') === '1') {
            sleep(10);
            return response()->json([
                'IsSucccess' => false,
                'Error' => [
                    'ErrorCode' => '504',
                    'ErrorMessage' => 'Fake timeout (gateway timeout)',
                ],
            ], 504);
        }

        if ($request->header('X-Fake-Fail') === '1') {
            return response()->json([
                'IsSucccess' => false,
                'Error' => [
                    'ErrorCode' => '500',
                    'ErrorMessage' => 'Fake fiskal service error',
                ],
            ], 422);
        }

        $forceFail = $request->boolean('forceFail')
            || ($request->json('forceFail') === true);

        if ($forceFail) {
            return response()->json([
                'IsSucccess' => false,
                'Error' => [
                    'ErrorCode' => '500',
                    'ErrorMessage' => 'Fake fiskal service error',
                ],
            ], 422);
        }

        // Scenario-driven fiscal errors (shape aligned with real provider).
        if ($scenario !== '' && $scenario !== 'success') {
            $path = $request->path();
            $isReceipt = str_contains($path, 'fiscalReceipt');

            // deposit_missing (58) is meaningful only on receipt; deposit stays OK.
            if ($scenario === 'deposit_missing' && ! $isReceipt) {
                $scenario = 'success';
            }

            $uuid = Str::uuid()->toString();
            $error = match ($scenario) {
                'deposit_missing' => ['code' => '58', 'msg' => 'Deposit missing'],
                'already_fiscalized' => ['code' => '78', 'msg' => 'Already fiscalized'],
                'validation_error' => ['code' => '11', 'msg' => 'Validation error'],
                'provider_down' => ['code' => '500', 'msg' => 'Provider down'],
                'temporary_service_down' => ['code' => '999', 'msg' => 'Temporary service down'],
                'tax_server_error' => ['code' => '905', 'msg' => 'Tax server error'],
                default => ['code' => '500', 'msg' => 'Fake fiscal service error'],
            };

            $body = [
                'IsSucccess' => false,
                'Error' => [
                    'ErrorCode' => $error['code'],
                    'ErrorMessage' => $error['msg'],
                ],
                'RawMessage' => 'scenario='.$scenario,
            ];

            // Optional: for 78 provider sometimes includes identifiers.
            if ($scenario === 'already_fiscalized') {
                $body['ResponseCode'] = 'FAKE-JIR-'.$uuid;
                $body['UIDRequest'] = 'FAKE-IKOF-'.$uuid;
                if (is_string($operator) && $operator !== '') {
                    $body['Url'] = ['Value' => $buildVerifyUrl($body['UIDRequest'], $operator, $documentNumber, $createdAt, $price)];
                }
            }

            if (is_string($operator) && $operator !== '') {
                $body['Operator'] = $operator;
            }

            return response()->json($body, 422);
        }

        $uuid = Str::uuid()->toString();

        $body = [
            'IsSucccess' => true,
            'ResponseCode' => 'FAKE-JIR-'.$uuid,
            'UIDRequest' => 'FAKE-IKOF-'.$uuid,
            'RawMessage' => 'OK',
        ];

        if (is_string($operator) && $operator !== '') {
            $body['Operator'] = $operator;
            $body['Url'] = ['Value' => $buildVerifyUrl($body['UIDRequest'], $operator, $documentNumber, $createdAt, $price)];
        }

        return response()->json($body);
    }
}
