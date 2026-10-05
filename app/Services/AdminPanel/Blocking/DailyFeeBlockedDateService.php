<?php

namespace App\Services\AdminPanel\Blocking;

use App\Models\DailyFeeBlockedDate;
use App\Models\DailyParkingData;
use App\Models\ListOfTimeSlot;
use Illuminate\Support\Facades\Log;

/**
 * Calendar dates on which NEW daily-ticket sales are prohibited.
 * May exist only while the date is fully slot-blocked for timed reservations.
 */
final class DailyFeeBlockedDateService
{
    public function isSaleProhibited(string $date): bool
    {
        return DailyFeeBlockedDate::query()
            ->whereDate('date', $date)
            ->exists();
    }

    /**
     * @return list<string> Y-m-d
     */
    public function prohibitedDatesFrom(string $fromDateInclusive): array
    {
        return DailyFeeBlockedDate::query()
            ->whereDate('date', '>=', $fromDateInclusive)
            ->orderBy('date')
            ->pluck('date')
            ->map(fn ($d) => \Carbon\Carbon::parse($d)->toDateString())
            ->values()
            ->all();
    }

    public function isDateFullySlotBlocked(string $date): bool
    {
        $slotIds = ListOfTimeSlot::query()->orderBy('id')->pluck('id')->map(fn ($v) => (int) $v)->all();
        if ($slotIds === []) {
            return false;
        }

        $blockedIds = DailyParkingData::query()
            ->whereDate('date', $date)
            ->where('is_blocked', true)
            ->whereIn('time_slot_id', $slotIds)
            ->pluck('time_slot_id')
            ->map(fn ($v) => (int) $v)
            ->unique()
            ->sort()
            ->values()
            ->all();

        sort($slotIds);

        return $blockedIds === $slotIds;
    }

    public function setProhibition(string $date, ?int $adminId): void
    {
        DailyFeeBlockedDate::query()->updateOrCreate(
            ['date' => $date],
            ['created_by_admin_id' => $adminId],
        );

        Log::channel('payments')->info('daily_fee_date_blocked', [
            'date' => $date,
            'admin_id' => $adminId,
        ]);
    }

    public function clearProhibition(string $date, string $reason): void
    {
        $deleted = DailyFeeBlockedDate::query()->whereDate('date', $date)->delete();
        if ($deleted > 0) {
            Log::channel('payments')->info('daily_fee_date_unblocked', [
                'date' => $date,
                'reason' => $reason,
            ]);
        }
    }

    /**
     * After slot block/unblock mutations: keep fee prohibition only if day is fully slot-blocked
     * and the caller requested it (or it already existed and day remains fully blocked).
     */
    public function syncAfterSlotMutation(
        string $date,
        bool $wantFeeBlock,
        ?int $adminId,
        string $reason,
    ): void {
        $fullyBlocked = $this->isDateFullySlotBlocked($date);

        if ($wantFeeBlock && $fullyBlocked) {
            $this->setProhibition($date, $adminId);

            return;
        }

        if (! $fullyBlocked) {
            $this->clearProhibition($date, $reason);
        } elseif (! $wantFeeBlock) {
            // Full-day block without fee checkbox clears any prior fee prohibition.
            $this->clearProhibition($date, $reason.'_without_fee_checkbox');
        }
    }
}
