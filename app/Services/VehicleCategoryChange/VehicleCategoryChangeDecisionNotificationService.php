<?php

namespace App\Services\VehicleCategoryChange;

use App\Mail\VehicleCategoryChangeApprovedMail;
use App\Mail\VehicleCategoryChangeRejectedMail;
use App\Models\User;
use App\Models\VehicleCategoryChangeRequest;
use App\Models\VehicleType;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;

final class VehicleCategoryChangeDecisionNotificationService
{
    public function notifyApproved(int $requestId): void
    {
        $request = VehicleCategoryChangeRequest::query()
            ->with(['user', 'oldVehicleType.translations', 'requestedVehicleType.translations'])
            ->find($requestId);

        if ($request === null) {
            return;
        }

        if ($request->status !== VehicleCategoryChangeRequest::STATUS_APPROVED) {
            return;
        }

        if ($request->approved_notification_sent_at !== null) {
            return;
        }

        $user = $request->user;
        if (! $user instanceof User) {
            return;
        }

        $email = trim((string) $user->email);
        if ($email === '') {
            return;
        }

        $locale = $this->resolveLocale($user, $request);

        try {
            Mail::to($email)->send(new VehicleCategoryChangeApprovedMail(
                agencyLocale: $locale,
                agencyName: (string) $user->name,
                licensePlate: (string) $request->license_plate,
                newCategory: $this->categoryLabel($request->requestedVehicleType, (int) $request->requested_vehicle_type_id, $locale),
                oldCategory: $this->categoryLabel($request->oldVehicleType, (int) $request->old_vehicle_type_id, $locale),
            ));

            $request->update(['approved_notification_sent_at' => now()]);

            Log::channel('payments')->info('vehicle_category_change_approved_notification_sent', [
                'request_id' => (int) $request->id,
                'agency_user_id' => (int) $user->id,
                'email' => $email,
            ]);
        } catch (\Throwable $e) {
            Log::channel('payments')->warning('vehicle_category_change_approved_notification_failed', [
                'request_id' => (int) $request->id,
                'agency_user_id' => (int) $user->id,
                'error' => $e->getMessage(),
            ]);
        }
    }

    public function notifyRejected(int $requestId): void
    {
        $request = VehicleCategoryChangeRequest::query()
            ->with(['user', 'oldVehicleType.translations', 'requestedVehicleType.translations'])
            ->find($requestId);

        if ($request === null) {
            return;
        }

        if ($request->status !== VehicleCategoryChangeRequest::STATUS_REJECTED) {
            return;
        }

        if ($request->rejected_notification_sent_at !== null) {
            return;
        }

        $user = $request->user;
        if (! $user instanceof User) {
            return;
        }

        $email = trim((string) $user->email);
        if ($email === '') {
            return;
        }

        $locale = $this->resolveLocale($user, $request);
        $reason = trim((string) ($request->rejection_reason ?? ''));
        if ($reason === '') {
            $reason = $locale === 'en' ? 'Not specified.' : 'Nije naveden.';
        }

        try {
            Mail::to($email)->send(new VehicleCategoryChangeRejectedMail(
                agencyLocale: $locale,
                agencyName: (string) $user->name,
                licensePlate: (string) $request->license_plate,
                requestedCategory: $this->categoryLabel($request->requestedVehicleType, (int) $request->requested_vehicle_type_id, $locale),
                oldCategory: $this->categoryLabel($request->oldVehicleType, (int) $request->old_vehicle_type_id, $locale),
                rejectionReason: $reason,
            ));

            $request->update(['rejected_notification_sent_at' => now()]);

            Log::channel('payments')->info('vehicle_category_change_rejected_notification_sent', [
                'request_id' => (int) $request->id,
                'agency_user_id' => (int) $user->id,
                'email' => $email,
            ]);
        } catch (\Throwable $e) {
            Log::channel('payments')->warning('vehicle_category_change_rejected_notification_failed', [
                'request_id' => (int) $request->id,
                'agency_user_id' => (int) $user->id,
                'error' => $e->getMessage(),
            ]);
        }
    }

    private function resolveLocale(User $user, VehicleCategoryChangeRequest $request): string
    {
        $lang = (string) ($user->lang ?: $request->locale ?: 'cg');

        return $lang === 'en' ? 'en' : 'cg';
    }

    private function categoryLabel(?VehicleType $type, int $fallbackId, string $locale): string
    {
        return $type?->formatLabel($locale, 'EUR') ?? ('#'.$fallbackId);
    }
}
