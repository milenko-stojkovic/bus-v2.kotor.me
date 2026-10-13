<?php

namespace App\Services\AgencyAdvance;

use App\Models\AgencyAdvanceTopup;
use App\Services\Payment\ErrorClassifier;

/**
 * Agency-facing status for an advance top-up.
 * Reads agency_advance_topups only. Does not consult reservation temp_data.
 * The browser return query is not an input: paid is only the persisted status.
 */
final class AdvanceTopupResultResolver
{
    /**
     * @return array{status: 'success'|'failed'|'pending', resolution_reason: ?string}|null
     *                                                                                      null when the reference is missing or belongs to another agency
     */
    public function resolveForAgency(string $merchantTransactionId, int $agencyUserId): ?array
    {
        $merchantTransactionId = trim($merchantTransactionId);
        if ($merchantTransactionId === '' || $agencyUserId < 1) {
            return null;
        }

        $topup = AgencyAdvanceTopup::query()
            ->where('merchant_transaction_id', $merchantTransactionId)
            ->where('agency_user_id', $agencyUserId)
            ->first();

        if (! $topup) {
            return null;
        }

        if ($topup->status === AgencyAdvanceTopup::STATUS_PAID) {
            return [
                'status' => 'success',
                'resolution_reason' => null,
            ];
        }

        if ($topup->status === AgencyAdvanceTopup::STATUS_PENDING) {
            return [
                'status' => 'pending',
                'resolution_reason' => null,
            ];
        }

        $reason = $topup->status === AgencyAdvanceTopup::STATUS_EXPIRED
            ? 'transaction_expired'
            : $this->resolutionReason(is_array($topup->bank_payload) ? $topup->bank_payload : null);

        return [
            'status' => 'failed',
            'resolution_reason' => $reason,
        ];
    }

    /**
     * @param  array<string, mixed>|null  $payload
     */
    private function resolutionReason(?array $payload): string
    {
        if ($payload === null || $payload === []) {
            return 'unknown_gateway_error';
        }

        $status = strtoupper(trim((string) ($payload['status'] ?? '')));
        $result = strtoupper(trim((string) ($payload['result'] ?? '')));
        if ($status === 'CANCEL' || $result === 'CANCEL') {
            return 'user_cancelled';
        }

        $code = $payload['error_code'] ?? $payload['errorCode'] ?? $payload['code'] ?? null;
        if (! is_string($code) && ! is_int($code)) {
            $code = null;
        }

        $message = $payload['error_reason'] ?? $payload['errorReason'] ?? $payload['message'] ?? $payload['errorMessage'] ?? null;
        $message = is_string($message) ? $message : null;

        $classified = app(ErrorClassifier::class)->classify(
            'bankart',
            $code,
            $message,
            $payload,
            ['stage' => 'advance_topup_result'],
        );

        return $classified['resolution_reason'];
    }
}
