<?php

namespace App\Services\AdminPanel\Blocking;

use App\Models\BlockZoneWorklist;
use App\Models\DailyParkingData;
use App\Models\Reservation;
use App\Models\TempData;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class BlockZoneWorklistService
{
    /**
     * Sync confirmed-reservation worklist membership to CURRENT occupancy of blocked slots.
     *
     * Membership: ready_to_adjust row exists iff at least one of the reservation's current
     * drop-off / pick-up slots on its current date has daily_parking_data.is_blocked = 1.
     *
     * Does not delete or overwrite an active pending_payment row that has no reservation yet.
     */
    public function reconcileForReservation(Reservation $reservation): void
    {
        if ($reservation->isDailyTicket()) {
            $this->deleteConfirmedWorklistIfPresent($reservation);

            return;
        }

        $date = $reservation->reservation_date->toDateString();
        $drop = (int) $reservation->drop_off_time_slot_id;
        $pick = (int) $reservation->pick_up_time_slot_id;

        $dropBlocked = $this->isSlotBlocked($date, $drop);
        $pickBlocked = $this->isSlotBlocked($date, $pick);

        if (! $dropBlocked && ! $pickBlocked) {
            $this->deleteConfirmedWorklistIfPresent($reservation);

            return;
        }

        $existing = BlockZoneWorklist::query()
            ->where('merchant_transaction_id', $reservation->merchant_transaction_id)
            ->first();

        if ($existing !== null
            && $existing->status === BlockZoneWorklist::STATUS_PENDING_PAYMENT
            && $existing->reservation_id === null) {
            return;
        }

        $targetSlots = [];
        if ($dropBlocked) {
            $targetSlots[] = $drop;
        }
        if ($pickBlocked) {
            $targetSlots[] = $pick;
        }
        $targetSlots = array_values(array_unique($targetSlots));

        $payload = [
            'user_name' => $reservation->user_name,
            'email' => $reservation->email,
            'reservation_id' => $reservation->id,
            'reservation_status' => $reservation->status,
            'target_block_slots' => $targetSlots,
        ];

        BlockZoneWorklist::query()->updateOrCreate(
            ['merchant_transaction_id' => $reservation->merchant_transaction_id],
            [
                'status' => BlockZoneWorklist::STATUS_READY_TO_ADJUST,
                'old_date' => $date,
                'old_drop_off' => $drop,
                'old_pick_up' => $pick,
                'affected_drop_off' => $dropBlocked,
                'affected_pick_up' => $pickBlocked,
                'snapshot_json' => $payload,
                'reservation_id' => $reservation->id,
                'temp_data_id' => null,
            ],
        );
    }

    private function isSlotBlocked(string $date, int $slotId): bool
    {
        if ($slotId < 1) {
            return false;
        }

        return DailyParkingData::query()
            ->whereDate('date', $date)
            ->where('time_slot_id', $slotId)
            ->where('is_blocked', true)
            ->exists();
    }

    private function deleteConfirmedWorklistIfPresent(Reservation $reservation): void
    {
        $row = BlockZoneWorklist::query()
            ->where('merchant_transaction_id', $reservation->merchant_transaction_id)
            ->first();
        if ($row === null) {
            return;
        }
        if ($row->status === BlockZoneWorklist::STATUS_PENDING_PAYMENT && $row->reservation_id === null) {
            return;
        }
        $row->delete();
    }

    /**
     * Kada pending pokušaj postane rezervacija: pending_payment → ready_to_adjust.
     * Za admin direktan free tok ($temp === null) samo se veže reservation_id ako postoji worklist red.
     */
    public function onReservationCreated(Reservation $reservation, ?TempData $temp = null): void
    {
        $row = BlockZoneWorklist::query()
            ->where('merchant_transaction_id', $reservation->merchant_transaction_id)
            ->first();
        if (! $row) {
            return;
        }

        if ($row->status === BlockZoneWorklist::STATUS_PENDING_PAYMENT) {
            $row->status = BlockZoneWorklist::STATUS_READY_TO_ADJUST;
        }
        $row->reservation_id = $reservation->id;
        if ($temp !== null) {
            $row->temp_data_id = $temp->id;
        }
        $row->snapshot_json = array_merge((array) ($row->snapshot_json ?? []), [
            'user_name' => $reservation->user_name,
            'email' => $reservation->email,
            'reservation_id' => $reservation->id,
            'status' => $reservation->status,
        ]);
        $row->save();
    }

    /**
     * Kada pending pokušaj propadne/istekne: izbaci iz worklist i blokiraj ciljne slotove (ako su sada slobodni).
     */
    public function onTempDataFailedOrExpired(TempData $temp, string $reason): void
    {
        $row = BlockZoneWorklist::query()
            ->where('merchant_transaction_id', $temp->merchant_transaction_id)
            ->first();
        if (! $row) {
            return;
        }

        $targetSlots = (array) (($row->snapshot_json['target_block_slots'] ?? null) ?? []);
        $row->delete();

        if ($targetSlots === []) {
            return;
        }

        DB::transaction(function () use ($targetSlots, $temp, $reason): void {
            foreach ($targetSlots as $slotId) {
                $slotId = (int) $slotId;
                if ($slotId < 1) {
                    continue;
                }
                /** @var DailyParkingData|null $daily */
                $daily = DailyParkingData::query()
                    ->where('date', $temp->reservation_date)
                    ->where('time_slot_id', $slotId)
                    ->lockForUpdate()
                    ->first();
                if (! $daily) {
                    continue;
                }
                if ($daily->reserved > 0 || $daily->pending > 0) {
                    continue;
                }
                $daily->is_blocked = true;
                $daily->save();
            }
        });

        Log::channel('payments')->info('block_zone_pending_removed', [
            'merchant_transaction_id' => $temp->merchant_transaction_id,
            'temp_data_id' => $temp->id,
            'reason' => $reason,
        ]);
    }
}

