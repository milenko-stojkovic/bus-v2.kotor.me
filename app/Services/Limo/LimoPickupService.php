<?php

namespace App\Services\Limo;

use App\Models\AgencyAdvanceTransaction;
use App\Models\LimoPickupEvent;
use App\Models\LimoQrToken;
use App\Models\User;
use App\Services\AgencyAdvance\AgencyAdvanceService;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Throwable;

final class LimoPickupService
{
    public const REFERENCE_TYPE_LIMO_PICKUP_EVENT = 'limo_pickup_event';

    public const AMOUNT_EUR = '15.00';

    public const SERVICE_NAME = 'Limo pick-up taksa';

    public function __construct(
        private readonly AgencyAdvanceService $agencyAdvanceService,
    ) {}

    /**
     * @param  array{license_plate?: string|null, device_info?: string|null, gps_lat?: string|null, gps_lng?: string|null}  $meta
     * @return array{success: true, merchant_transaction_id: string, remaining_balance: string, event_id: int}|array{success: false, code: 'invalid_token'|'insufficient_advance'|'token_already_used'}
     */
    public function processQrPickup(string $rawToken, array $meta, int $recordedByLimoAdminId): array
    {
        $tokenHash = self::hashToken($rawToken);
        $today = Carbon::today('Europe/Podgorica');

        try {
            return DB::transaction(function () use ($tokenHash, $today, $meta, $recordedByLimoAdminId) {
                /** @var LimoQrToken|null $qrRow */
                $qrRow = LimoQrToken::query()
                    ->where('token_hash', $tokenHash)
                    ->whereDate('valid_on', $today->toDateString())
                    ->lockForUpdate()
                    ->first();

                if ($qrRow === null) {
                    $reuse = LimoPickupEvent::query()
                        ->where('qr_token_hash', $tokenHash)
                        ->whereDate('qr_valid_on', $today->toDateString())
                        ->exists();

                    if ($reuse) {
                        $this->logFailure('limo_pickup_failed_token_reused', $tokenHash, null, null);

                        return ['success' => false, 'code' => 'token_already_used'];
                    }

                    $this->logFailure('limo_pickup_failed_invalid_token', $tokenHash, null, null);

                    return ['success' => false, 'code' => 'invalid_token'];
                }

                if (LimoPickupEvent::query()
                    ->where('qr_token_hash', $tokenHash)
                    ->whereDate('qr_valid_on', $qrRow->valid_on->toDateString())
                    ->exists()) {
                    $this->logFailure('limo_pickup_failed_token_reused', $tokenHash, (int) $qrRow->agency_user_id, null);

                    return ['success' => false, 'code' => 'token_already_used'];
                }

                $agencyUserId = (int) $qrRow->agency_user_id;

                User::query()->whereKey($agencyUserId)->lockForUpdate()->first();

                if (! $this->agencyAdvanceService->canSpend($agencyUserId, self::AMOUNT_EUR)) {
                    $this->logFailure('limo_pickup_failed_insufficient_advance', $tokenHash, $agencyUserId, null);

                    return ['success' => false, 'code' => 'insufficient_advance'];
                }

                /** @var User|null $agency */
                $agency = User::query()->find($agencyUserId);
                if ($agency === null) {
                    $this->logFailure('limo_pickup_failed_invalid_token', $tokenHash, null, null);

                    return ['success' => false, 'code' => 'invalid_token'];
                }

                $merchantTransactionId = (string) Str::uuid();

                $event = LimoPickupEvent::query()->create([
                    'merchant_transaction_id' => $merchantTransactionId,
                    'agency_user_id' => $agencyUserId,
                    'agency_name_snapshot' => $agency->name,
                    'agency_email_snapshot' => $agency->email,
                    'agency_country_snapshot' => $agency->country,
                    'license_plate_snapshot' => $meta['license_plate'] ?? null,
                    'service_name_snapshot' => self::SERVICE_NAME,
                    'amount_snapshot' => self::AMOUNT_EUR,
                    'source' => 'qr',
                    'status' => 'pending_fiscal',
                    'occurred_at' => now(),
                    'recorded_by_limo_admin_id' => $recordedByLimoAdminId,
                    'device_info' => $meta['device_info'] ?? null,
                    'gps_lat' => isset($meta['gps_lat']) ? $meta['gps_lat'] : null,
                    'gps_lng' => isset($meta['gps_lng']) ? $meta['gps_lng'] : null,
                    'qr_token_hash' => $tokenHash,
                    'qr_valid_on' => $qrRow->valid_on,
                ]);

                AgencyAdvanceTransaction::query()->create([
                    'agency_user_id' => $agencyUserId,
                    'amount' => number_format(-1 * (float) self::AMOUNT_EUR, 2, '.', ''),
                    'type' => AgencyAdvanceTransaction::TYPE_USAGE,
                    'reference_type' => self::REFERENCE_TYPE_LIMO_PICKUP_EVENT,
                    'reference_id' => (int) $event->id,
                    'merchant_transaction_id' => $merchantTransactionId,
                    'note' => \App\Support\AdvanceLedgerNote::KEY_LIMO_PICKUP_QR,
                    'created_by_admin_id' => $recordedByLimoAdminId,
                ]);

                $qrRow->delete();

                $remaining = $this->agencyAdvanceService->balance($agencyUserId);

                Log::channel('payments')->info('limo_pickup_created', [
                    'token_hash' => $tokenHash,
                    'agency_user_id' => $agencyUserId,
                    'merchant_transaction_id' => $merchantTransactionId,
                    'amount' => self::AMOUNT_EUR,
                    'limo_pickup_event_id' => $event->id,
                ]);

                return [
                    'success' => true,
                    'merchant_transaction_id' => $merchantTransactionId,
                    'remaining_balance' => $remaining,
                    'event_id' => (int) $event->id,
                ];
            });
        } catch (Throwable $e) {
            Log::channel('payments')->error('limo_pickup_transaction_failed', [
                'token_hash' => $tokenHash,
                'message' => $e->getMessage(),
            ]);
            throw $e;
        }
    }

    public static function hashToken(string $rawToken): string
    {
        return hash('sha256', $rawToken);
    }

    private function logFailure(string $event, string $tokenHash, ?int $agencyUserId, ?string $merchantTransactionId): void
    {
        Log::channel('payments')->warning($event, [
            'token_hash' => $tokenHash,
            'agency_user_id' => $agencyUserId,
            'merchant_transaction_id' => $merchantTransactionId,
            'amount' => self::AMOUNT_EUR,
        ]);
    }
}
