<?php

namespace App\Console\Commands;

use App\Models\DailyParkingData;
use App\Models\TempData;
use App\Services\AdminPanel\Blocking\BlockZoneWorklistService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Cron: temp_data pending duže od X min → status expired (state machine). Log transition; release soft-lock.
 * V. docs/cron-commands.md.
 */
class ExpirePendingReservations extends Command
{
    protected $signature = 'reservations:expire-pending';

    protected $description = 'Expire temp_data pending older than threshold: mark expired, release soft-lock';

    public function handle(): int
    {
        $minutes = (int) config('reservations.pending_expire_minutes');
        $cutoff = now()->subMinutes($minutes);

        $rows = TempData::where('status', TempData::STATUS_PENDING)
            ->where('created_at', '<', $cutoff)
            ->get();

        foreach ($rows as $temp) {
            DB::transaction(function () use ($temp): void {
                $temp = TempData::where('id', $temp->id)->lockForUpdate()->first();
                if (! $temp || $temp->status !== TempData::STATUS_PENDING) {
                    return;
                }
                $from = $temp->status;
                $temp->update(['status' => TempData::STATUS_EXPIRED]);
                TempData::logStateTransition($temp->merchant_transaction_id, $from, TempData::STATUS_EXPIRED, 'cron: pending expired');
                if (! $temp->isDailyTicket()) {
                    $slotIds = array_values(array_unique(array_filter([
                        $temp->drop_off_time_slot_id,
                        $temp->pick_up_time_slot_id,
                    ], fn ($id) => $id !== null)));
                    if ($slotIds !== []) {
                        DailyParkingData::query()
                            ->where('date', $temp->reservation_date)
                            ->whereIn('time_slot_id', $slotIds)
                            ->decrement('pending');
                    }
                }
                app(BlockZoneWorklistService::class)->onTempDataFailedOrExpired($temp, 'expired');
            });
        }

        $this->info('Expired '.$rows->count().' pending rows.');
        return self::SUCCESS;
    }
}
