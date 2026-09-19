<?php

namespace App\Services\AdminPanel\Blocking;

use App\Models\BlockZoneWorklist;
use App\Models\DailyParkingData;
use App\Models\ListOfTimeSlot;
use App\Models\Reservation;
use App\Models\TempData;
use Carbon\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class BlockingService
{
    /**
     * @return Collection<int, ListOfTimeSlot>
     */
    public function allSlots(): Collection
    {
        return ListOfTimeSlot::query()->orderBy('id')->get();
    }

    /**
     * Block selected slots immediately for NEW reservations (`is_blocked = 1`).
     * Existing reserved/pending occupants stay; they are listed on the block-zone worklist.
     * Already-pending soft-locks are grandfathered (PaymentSuccessHandler is unchanged).
     *
     * @param  list<int>  $slotIds
     * @return array{blocked_slots: int, worklist_touched: int}
     */
    public function applyBlock(string $date, array $slotIds): array
    {
        $slotIds = array_values(array_unique(array_map('intval', $slotIds)));
        $slotIds = array_values(array_filter($slotIds, fn (int $id) => $id > 0));
        if ($slotIds === []) {
            return ['blocked_slots' => 0, 'worklist_touched' => 0];
        }

        $blockedSlots = 0;
        /** @var array<string, true> $worklistMtids */
        $worklistMtids = [];

        DB::transaction(function () use ($date, $slotIds, &$blockedSlots, &$worklistMtids): void {
            $daily = DailyParkingData::query()
                ->whereDate('date', $date)
                ->whereIn('time_slot_id', $slotIds)
                ->lockForUpdate()
                ->get()
                ->keyBy('time_slot_id');

            foreach ($slotIds as $slotId) {
                /** @var DailyParkingData|null $row */
                $row = $daily->get($slotId);
                if (! $row) {
                    continue;
                }

                // Immediate hard gate for NEW reservations (may coexist with reserved/pending).
                if (! $row->is_blocked) {
                    $row->is_blocked = true;
                    $row->save();
                }
                $blockedSlots++;

                // Existing pending soft-locks → worklist (grandfathered; do not release pending).
                if ((int) $row->pending > 0) {
                    $temps = TempData::query()
                        ->where('status', TempData::STATUS_PENDING)
                        ->whereDate('reservation_date', $date)
                        ->where(function ($q) use ($slotId) {
                            $q->where('drop_off_time_slot_id', $slotId)->orWhere('pick_up_time_slot_id', $slotId);
                        })
                        ->get();
                    foreach ($temps as $temp) {
                        $this->upsertWorklistForTemp($temp, $slotId);
                        $worklistMtids[(string) $temp->merchant_transaction_id] = true;
                    }
                }

                // Existing confirmed reservations → worklist (remain valid until adjusted).
                if ((int) $row->reserved > 0) {
                    $reservations = Reservation::query()
                        ->whereDate('reservation_date', $date)
                        ->where(function ($q) use ($slotId) {
                            $q->where('drop_off_time_slot_id', $slotId)->orWhere('pick_up_time_slot_id', $slotId);
                        })
                        ->get();
                    foreach ($reservations as $r) {
                        $this->upsertWorklistForReservation($r, $slotId);
                        $worklistMtids[(string) $r->merchant_transaction_id] = true;
                    }
                }
            }
        });

        return [
            'blocked_slots' => $blockedSlots,
            'worklist_touched' => count($worklistMtids),
        ];
    }

    /**
     * @param  list<int>  $slotIdsToUnblock
     */
    public function applyUnblock(string $date, array $slotIdsToUnblock): void
    {
        $slotIdsToUnblock = array_values(array_unique(array_map('intval', $slotIdsToUnblock)));
        $slotIdsToUnblock = array_values(array_filter($slotIdsToUnblock, fn (int $id) => $id > 0));

        DB::transaction(function () use ($date, $slotIdsToUnblock): void {
            if ($slotIdsToUnblock !== []) {
                DailyParkingData::query()
                    ->whereDate('date', $date)
                    ->whereIn('time_slot_id', $slotIdsToUnblock)
                    ->lockForUpdate()
                    ->update(['is_blocked' => false]);
            }

            // Očisti worklist stavke koje više nisu u blok zoni.
            $work = BlockZoneWorklist::query()
                ->whereDate('old_date', $date)
                ->get();
            if ($work->isEmpty()) {
                return;
            }

            $blockedBySlot = DailyParkingData::query()
                ->whereDate('date', $date)
                ->where('is_blocked', true)
                ->get()
                ->keyBy('time_slot_id');

            foreach ($work as $row) {
                $affectedDrop = (bool) $row->affected_drop_off;
                $affectedPick = (bool) $row->affected_pick_up;
                if ($affectedDrop && ! $blockedBySlot->has((int) $row->old_drop_off)) {
                    $affectedDrop = false;
                }
                if ($affectedPick && ! $blockedBySlot->has((int) $row->old_pick_up)) {
                    $affectedPick = false;
                }

                if (! $affectedDrop && ! $affectedPick) {
                    $row->delete();
                    continue;
                }

                if ($affectedDrop !== (bool) $row->affected_drop_off || $affectedPick !== (bool) $row->affected_pick_up) {
                    $row->affected_drop_off = $affectedDrop;
                    $row->affected_pick_up = $affectedPick;
                    $row->save();
                }
            }
        });
    }

    public function upsertWorklistForReservation(Reservation $r, int $affectedSlotId): void
    {
        $drop = (int) $r->drop_off_time_slot_id;
        $pick = (int) $r->pick_up_time_slot_id;

        $existing = BlockZoneWorklist::query()
            ->where('merchant_transaction_id', $r->merchant_transaction_id)
            ->first();

        $payload = [
            'user_name' => $r->user_name,
            'email' => $r->email,
            'reservation_id' => $r->id,
            'reservation_status' => $r->status,
            'target_block_slots' => array_values(array_unique(array_merge(
                (array) (($existing?->snapshot_json['target_block_slots'] ?? []) ?: []),
                [$affectedSlotId]
            ))),
        ];

        BlockZoneWorklist::query()->updateOrCreate(
            ['merchant_transaction_id' => $r->merchant_transaction_id],
            [
                'status' => BlockZoneWorklist::STATUS_READY_TO_ADJUST,
                'old_date' => $r->reservation_date,
                'old_drop_off' => $drop,
                'old_pick_up' => $pick,
                'affected_drop_off' => $drop === $affectedSlotId ? true : ($existing?->affected_drop_off ?? false),
                'affected_pick_up' => $pick === $affectedSlotId ? true : ($existing?->affected_pick_up ?? false),
                'snapshot_json' => $payload,
                'reservation_id' => $r->id,
                'temp_data_id' => null,
            ],
        );
    }

    public function upsertWorklistForTemp(TempData $temp, int $affectedSlotId): void
    {
        $drop = (int) $temp->drop_off_time_slot_id;
        $pick = (int) $temp->pick_up_time_slot_id;

        $existing = BlockZoneWorklist::query()
            ->where('merchant_transaction_id', $temp->merchant_transaction_id)
            ->first();

        $payload = [
            'user_name' => $temp->user_name,
            'email' => $temp->email,
            'temp_data_id' => $temp->id,
            'target_block_slots' => array_values(array_unique(array_merge(
                (array) (($existing?->snapshot_json['target_block_slots'] ?? []) ?: []),
                [$affectedSlotId]
            ))),
        ];

        BlockZoneWorklist::query()->updateOrCreate(
            ['merchant_transaction_id' => $temp->merchant_transaction_id],
            [
                'status' => BlockZoneWorklist::STATUS_PENDING_PAYMENT,
                'old_date' => $temp->reservation_date,
                'old_drop_off' => $drop,
                'old_pick_up' => $pick,
                'affected_drop_off' => $drop === $affectedSlotId ? true : ($existing?->affected_drop_off ?? false),
                'affected_pick_up' => $pick === $affectedSlotId ? true : ($existing?->affected_pick_up ?? false),
                'snapshot_json' => $payload,
                'reservation_id' => null,
                'temp_data_id' => $temp->id,
            ],
        );
    }

    /**
     * Blokirani termini: samo redovi sa `is_blocked`. Datumi iz postojećih redova u tabeli (od danas nadalje).
     *
     * @return list<array{date:string, is_full_day:bool, ranges:list<string>, slot_ids:list<int>}>
     */
    public function blockedDaySummaries(): array
    {
        $orderedSlots = $this->allSlots();
        if ($orderedSlots->isEmpty()) {
            return [];
        }

        $builder = new DaySlotRangeSummaryBuilder;
        $today = now()->toDateString();

        $blocked = DailyParkingData::query()
            ->where('is_blocked', true)
            ->whereDate('date', '>=', $today)
            ->orderBy('date')
            ->orderBy('time_slot_id')
            ->get()
            ->groupBy(fn (DailyParkingData $d) => $d->date->toDateString());

        $out = [];
        foreach ($blocked as $date => $rows) {
            $slotIds = $rows->pluck('time_slot_id')->map(fn ($v) => (int) $v)->unique()->values()->all();
            $summary = $builder->summarize($orderedSlots, $slotIds);
            $out[] = [
                'date' => $date,
                'is_full_day' => $summary['is_full_day'],
                'ranges' => $summary['ranges'],
                'slot_ids' => $slotIds,
            ];
        }

        return $out;
    }

    /**
     * Termini koji se ne mogu kupiti: ista provera kao pri atomic claim-u u checkout-u
     * ({@see \App\Http\Controllers\CheckoutController::store}): nema reda, `is_blocked`, ili `availableCapacity() < 1`.
     * Uključuje i blokirane. Samo datumi koji već postoje u `daily_parking_data` (od danas nadalje).
     *
     * @return list<array{date:string, is_full_day:bool, ranges:list<string>}>
     */
    public function unavailableForPurchaseDaySummaries(): array
    {
        $orderedSlots = $this->allSlots();
        if ($orderedSlots->isEmpty()) {
            return [];
        }

        $builder = new DaySlotRangeSummaryBuilder;
        $today = now()->toDateString();

        $dates = DailyParkingData::query()
            ->whereDate('date', '>=', $today)
            ->distinct()
            ->orderBy('date')
            ->pluck('date')
            ->map(fn ($d) => Carbon::parse($d)->toDateString())
            ->values()
            ->all();

        $out = [];
        foreach ($dates as $dateStr) {
            $dailyBySlot = DailyParkingData::query()
                ->whereDate('date', $dateStr)
                ->get()
                ->keyBy('time_slot_id');

            $unavailableIds = [];
            foreach ($orderedSlots as $slot) {
                $daily = $dailyBySlot->get($slot->id);
                if ($daily === null || $daily->is_blocked || $daily->availableCapacity() < 1) {
                    $unavailableIds[] = $slot->id;
                }
            }

            if ($unavailableIds === []) {
                continue;
            }

            $summary = $builder->summarize($orderedSlots, $unavailableIds);
            $out[] = [
                'date' => $dateStr,
                'is_full_day' => $summary['is_full_day'],
                'ranges' => $summary['ranges'],
            ];
        }

        return $out;
    }

    /**
     * Prefilter datuma za UI prilagođavanja (nije garancija). Dan ulazi ako postoje najmanje 2 termina
     * sa `availableCapacity() >= 1`, neblokirani i bez pending-a (teorijski prostor za drop+pick).
     *
     * @return list<string> Y-m-d
     */
    public function datesEligibleForAdjustmentPrefilter(int $daysAhead = 90): array
    {
        $from = now()->startOfDay();
        $to = $from->copy()->addDays($daysAhead);

        if ($this->allSlots()->count() < 2) {
            return [];
        }

        $eligible = [];
        $cursor = $from->copy();
        while ($cursor->lte($to)) {
            $dateStr = $cursor->toDateString();
            $rows = DailyParkingData::query()
                ->whereDate('date', $dateStr)
                ->get();
            $withRoom = $rows->filter(function (DailyParkingData $d): bool {
                return ! $d->is_blocked
                    && (int) $d->pending === 0
                    && $d->availableCapacity() >= 1;
            });
            if ($withRoom->count() >= 2) {
                $eligible[] = $dateStr;
            }
            $cursor->addDay();
        }

        return $eligible;
    }
}

