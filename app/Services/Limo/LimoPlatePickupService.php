<?php

namespace App\Services\Limo;

use App\Models\AgencyAdvanceTransaction;
use App\Models\LimoPickupEvent;
use App\Models\LimoPickupPhoto;
use App\Models\LimoPlateUpload;
use App\Models\User;
use App\Models\Vehicle;
use App\Services\AgencyAdvance\AgencyAdvanceService;
use App\Services\Reservation\DuplicateReservationAttemptService;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Throwable;

final class LimoPlatePickupService
{
    public function __construct(
        private readonly AgencyAdvanceService $agencyAdvanceService,
        private readonly LimoPlateOcrService $ocrService,
    ) {}

    /**
     * @param  array{left:int,top:int,width:int,height:int}|null  $plateCropBasisPoints  0–10000 basis points relative to stored image size
     * @return array{status: 'ok', upload_token: string, suggested_plate: ?string, debug?: array<string, mixed>}
     */
    public function processUpload(
        UploadedFile $file,
        ?string $gpsLat,
        ?string $gpsLng,
        ?string $deviceInfo,
        int $uploadedByLimoAdminId,
        bool $includeDebugInResponse = false,
        ?array $plateCropBasisPoints = null,
    ): array {
        $uploadToken = Str::random(48);
        $ext = strtolower($file->guessExtension() ?: 'jpg');
        if (! in_array($ext, ['jpg', 'jpeg', 'png', 'webp'], true)) {
            $ext = 'jpg';
        }
        $storedName = Str::uuid()->toString().'.'.$ext;
        $path = $file->storeAs('limo_plate_uploads', $storedName, 'local');

        $absolute = Storage::disk('local')->path($path);
        $suffix = substr($uploadToken, -8);
        $pathSuffix = basename($absolute);

        if ($this->ocrService->isRunnable()) {
            Log::channel('payments')->info('limo_plate_ocr_attempted', [
                'upload_token_suffix' => $suffix,
                'path_suffix' => $pathSuffix,
                'plate_crop' => $plateCropBasisPoints !== null,
            ]);
        }

        $cropTempPath = null;
        if ($plateCropBasisPoints !== null && LimoPlateCropExtractor::validateBasisPoints($plateCropBasisPoints)) {
            $cropTempPath = LimoPlateCropExtractor::extractToTempPng($absolute, $plateCropBasisPoints);
        }

        try {
            $analysis = $this->ocrService->analyze($absolute, $suffix, $cropTempPath !== null && is_file($cropTempPath) ? $cropTempPath : null);
        } finally {
            if ($cropTempPath !== null && is_file($cropTempPath)) {
                @unlink($cropTempPath);
            }
        }
        $suggestedPlate = $analysis['suggested_plate'];
        $ocrRawForDb = $analysis['raw_text'] !== null ? Str::limit($analysis['raw_text'], 2000, '') : null;

        $normalizedLen = strlen($analysis['normalized_compact']);
        $logCtx = [
            'upload_token_suffix' => $suffix,
            'path_suffix' => $pathSuffix,
            'suggested_plate' => $suggestedPlate,
            'normalized_compact_length' => $normalizedLen,
            'ocr_reason' => $analysis['reason'],
        ];

        match ($analysis['reason']) {
            'disabled', 'unavailable' => Log::channel('payments')->info('limo_plate_ocr_unavailable', $logCtx + [
                'unavailable_detail' => $analysis['reason'] === 'disabled' ? 'ocr_disabled' : ($analysis['failure_message'] ?? 'runner_unavailable'),
            ]),
            'failed' => Log::channel('payments')->warning('limo_plate_ocr_failed', $logCtx + [
                'failure_reason' => $analysis['failure_message'],
            ]),
            'no_candidate' => Log::channel('payments')->info('limo_plate_ocr_no_candidate', $logCtx + [
                'raw_preview' => $analysis['raw_text'] !== null ? Str::limit($analysis['raw_text'], 120, '…') : null,
                'variant_passes' => count($analysis['debug_variant_attempts'] ?? []),
            ]),
            'ok' => Log::channel('payments')->info('limo_plate_ocr_succeeded', $logCtx + [
                'raw_preview' => $analysis['raw_text'] !== null ? Str::limit($analysis['raw_text'], 120, '…') : null,
            ]),
        };

        LimoPlateUpload::query()->create([
            'upload_token' => $uploadToken,
            'path' => $path,
            'ocr_text' => $ocrRawForDb,
            'gps_lat' => $gpsLat,
            'gps_lng' => $gpsLng,
            'device_info' => $deviceInfo,
            'uploaded_by_limo_admin_id' => $uploadedByLimoAdminId,
            'expires_at' => now()->addHour(),
            'plate_crop_left_bp' => $plateCropBasisPoints !== null ? $plateCropBasisPoints['left'] : null,
            'plate_crop_top_bp' => $plateCropBasisPoints !== null ? $plateCropBasisPoints['top'] : null,
            'plate_crop_width_bp' => $plateCropBasisPoints !== null ? $plateCropBasisPoints['width'] : null,
            'plate_crop_height_bp' => $plateCropBasisPoints !== null ? $plateCropBasisPoints['height'] : null,
        ]);

        Log::channel('payments')->info('limo_plate_photo_uploaded', [
            'upload_token_suffix' => $suffix,
            'path_dir' => 'limo_plate_uploads',
            'uploaded_by_limo_admin_id' => $uploadedByLimoAdminId,
        ]);

        $payload = [
            'status' => 'ok',
            'upload_token' => $uploadToken,
            'suggested_plate' => $suggestedPlate,
        ];

        if ($includeDebugInResponse) {
            $attempts = $analysis['debug_variant_attempts'] ?? [];
            $variantsTried = [];
            foreach ($attempts as $row) {
                if (! isset($row['variant'])) {
                    continue;
                }
                $psm = array_key_exists('psm', $row) ? $row['psm'] : null;
                $wl = array_key_exists('whitelist_enabled', $row) ? $row['whitelist_enabled'] : null;
                $wlStr = $wl === null ? 'null' : ($wl ? '1' : '0');
                $variantsTried[] = (string) $row['variant'].'@psm='.($psm === null ? 'null' : (string) $psm).'@wl='.$wlStr;
            }
            $payload['debug'] = [
                'ocr_enabled' => $analysis['ocr_enabled'],
                'ocr_available' => $analysis['ocr_runner_available'],
                'raw_preview' => $analysis['raw_text'] !== null ? Str::limit($analysis['raw_text'], 200, '…') : '',
                'normalized_preview' => Str::limit($analysis['normalized_compact'], 200, '…'),
                'reason' => $analysis['reason'] === 'unavailable' ? 'failed' : $analysis['reason'],
                'variants_tried' => $variantsTried,
                'variant_attempts' => $attempts,
                'variants_count' => (int) ($analysis['variants_count'] ?? 0),
                'attempts_count' => count($attempts),
                'preprocessing_gd_available' => (bool) ($analysis['preprocessing_gd_loaded'] ?? extension_loaded('gd')),
                'preprocessing_available' => ($analysis['preprocessing_ran'] ?? false) && ! ($analysis['preprocessing_used_fallback'] ?? false),
                'preprocessing_failed' => (bool) ($analysis['preprocessing_used_fallback'] ?? false),
                'selected_variant' => $analysis['selected_variant'],
                'selected_psm' => $analysis['selected_psm'],
                'selected_candidate' => $analysis['suggested_plate'],
                'candidate_score' => $analysis['candidate_score'] ?? null,
                'cg_prefix_detected' => (bool) ($analysis['cg_prefix_detected'] ?? false),
                'normalized_prefix' => $analysis['normalized_prefix'] ?? null,
                'candidate_reason' => $analysis['candidate_reason'] ?? null,
                'early_exit' => (bool) ($analysis['early_exit'] ?? false),
                'ocr_used_user_crop' => (bool) ($analysis['ocr_used_user_crop'] ?? false),
                'ocr_crop_width_px' => $analysis['ocr_crop_width_px'] ?? null,
                'ocr_crop_height_px' => $analysis['ocr_crop_height_px'] ?? null,
            ];
            if ($analysis['reason'] === 'unavailable') {
                $payload['debug']['failure_detail'] = $analysis['failure_message'] ?? 'unavailable';
            } elseif ($analysis['reason'] === 'failed' && $analysis['failure_message'] !== null) {
                $payload['debug']['failure_detail'] = Str::limit($analysis['failure_message'], 200, '…');
            } elseif ($attempts !== [] && ($attempts[0]['variant'] ?? '') === 'none' && ($attempts[0]['error'] ?? '') !== '') {
                $payload['debug']['failure_detail'] = (string) $attempts[0]['error'];
            }
        }

        return $payload;
    }

    /**
     * @param  array{device_info?: string|null, gps_lat?: string|null, gps_lng?: string|null}  $meta
     * @return array{success: true, merchant_transaction_id: string, remaining_balance: string, event_id: int}|array{success: false, code: 'invalid_upload'|'plate_not_registered'|'insufficient_advance'|'validation_error'}
     */
    public function confirmPlate(string $uploadToken, string $rawPlate, array $meta, int $recordedByLimoAdminId): array
    {
        $plate = DuplicateReservationAttemptService::normalizeLicensePlate($rawPlate);
        if ($plate === '') {
            return ['success' => false, 'code' => 'validation_error'];
        }

        try {
            return DB::transaction(function () use ($uploadToken, $plate, $meta, $recordedByLimoAdminId) {
                /** @var LimoPlateUpload|null $upload */
                $upload = LimoPlateUpload::query()
                    ->where('upload_token', $uploadToken)
                    ->lockForUpdate()
                    ->first();

                if ($upload === null || (int) $upload->uploaded_by_limo_admin_id !== $recordedByLimoAdminId) {
                    return ['success' => false, 'code' => 'invalid_upload'];
                }

                if ($upload->consumed_at !== null) {
                    return ['success' => false, 'code' => 'invalid_upload'];
                }

                if ($upload->expires_at->isPast()) {
                    return ['success' => false, 'code' => 'invalid_upload'];
                }

                if (! Storage::disk('local')->exists($upload->path)) {
                    return ['success' => false, 'code' => 'invalid_upload'];
                }

                /** @var Vehicle|null $vehicle */
                $vehicle = Vehicle::query()
                    ->where('license_plate', $plate)
                    ->where('status', Vehicle::STATUS_ACTIVE)
                    ->whereNotNull('user_id')
                    ->orderBy('id')
                    ->first();

                if ($vehicle === null) {
                    Log::channel('payments')->warning('limo_plate_failed_not_registered', [
                        'license_plate' => $plate,
                        'upload_token_suffix' => substr($uploadToken, -8),
                    ]);

                    return ['success' => false, 'code' => 'plate_not_registered'];
                }

                $agencyUserId = (int) $vehicle->user_id;

                User::query()->whereKey($agencyUserId)->lockForUpdate()->first();

                if (! $this->agencyAdvanceService->canSpend($agencyUserId, LimoPickupService::AMOUNT_EUR)) {
                    Log::channel('payments')->warning('limo_plate_failed_insufficient_advance', [
                        'agency_user_id' => $agencyUserId,
                        'license_plate' => $plate,
                    ]);

                    return ['success' => false, 'code' => 'insufficient_advance'];
                }

                /** @var User|null $agency */
                $agency = User::query()->find($agencyUserId);
                if ($agency === null) {
                    return ['success' => false, 'code' => 'plate_not_registered'];
                }

                $merchantTransactionId = (string) Str::uuid();

                $event = LimoPickupEvent::query()->create([
                    'merchant_transaction_id' => $merchantTransactionId,
                    'agency_user_id' => $agencyUserId,
                    'agency_name_snapshot' => $agency->name,
                    'agency_email_snapshot' => $agency->email,
                    'agency_country_snapshot' => $agency->country,
                    'license_plate_snapshot' => $plate,
                    'service_name_snapshot' => LimoPickupService::SERVICE_NAME,
                    'amount_snapshot' => LimoPickupService::AMOUNT_EUR,
                    'source' => 'plate',
                    'status' => 'pending_fiscal',
                    'occurred_at' => now(),
                    'recorded_by_limo_admin_id' => $recordedByLimoAdminId,
                    'device_info' => $meta['device_info'] ?? null,
                    'gps_lat' => isset($meta['gps_lat']) ? $meta['gps_lat'] : null,
                    'gps_lng' => isset($meta['gps_lng']) ? $meta['gps_lng'] : null,
                    'vehicle_id' => $vehicle->id,
                    'qr_token_hash' => null,
                    'qr_valid_on' => null,
                ]);

                AgencyAdvanceTransaction::query()->create([
                    'agency_user_id' => $agencyUserId,
                    'amount' => number_format(-1 * (float) LimoPickupService::AMOUNT_EUR, 2, '.', ''),
                    'type' => AgencyAdvanceTransaction::TYPE_USAGE,
                    'reference_type' => LimoPickupService::REFERENCE_TYPE_LIMO_PICKUP_EVENT,
                    'reference_id' => (int) $event->id,
                    'merchant_transaction_id' => $merchantTransactionId,
                    'note' => \App\Support\AdvanceLedgerNote::KEY_LIMO_PICKUP_PLATE,
                    'created_by_admin_id' => $recordedByLimoAdminId,
                ]);

                $evidenceDir = 'limo_pickup_evidence/'.$event->id;
                $ext = pathinfo($upload->path, PATHINFO_EXTENSION);
                $ext = $ext !== '' ? strtolower($ext) : 'jpg';
                $newName = 'plate_'.Str::uuid()->toString().'.'.$ext;
                $newPath = $evidenceDir.'/'.$newName;
                Storage::disk('local')->makeDirectory($evidenceDir);
                Storage::disk('local')->move($upload->path, $newPath);

                LimoPickupPhoto::query()->create([
                    'limo_pickup_event_id' => $event->id,
                    'path' => $newPath,
                    'type' => 'plate',
                ]);

                $upload->update(['consumed_at' => now()]);

                $remaining = $this->agencyAdvanceService->balance($agencyUserId);

                Log::channel('payments')->info('limo_plate_confirmed', [
                    'limo_pickup_event_id' => $event->id,
                    'merchant_transaction_id' => $merchantTransactionId,
                    'agency_user_id' => $agencyUserId,
                    'license_plate' => $plate,
                ]);

                return [
                    'success' => true,
                    'merchant_transaction_id' => $merchantTransactionId,
                    'remaining_balance' => $remaining,
                    'event_id' => (int) $event->id,
                ];
            });
        } catch (Throwable $e) {
            Log::channel('payments')->error('limo_plate_confirm_failed', [
                'message' => $e->getMessage(),
            ]);
            throw $e;
        }
    }
}
