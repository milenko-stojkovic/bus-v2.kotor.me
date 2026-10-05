<?php

namespace App\Services\AdminPanel\Blocking;

use App\Models\BlockZoneWorklist;
use App\Models\DailyParkingData;
use App\Models\Reservation;
use App\Models\TempData;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;

class BlockZoneWorklistService
{
    /**
     * Sync confirmed-reservation worklist membership to CURRENT occupancy of blocked slots.
     *
     * Membership: ready_to_adjust row exists iff at least one of the reservation's current
     * drop-off / pick-up slots on its current date has daily_parking_data.is_blocked = 1.
     *
     * Does not delete or overwrite an active pending_payment row that has no reservation yet.
     * Does not reopen acknowledged_no_adjustment while the reservation still matches the
     * acknowledged date/slot fingerprint.
     */
    public function reconcileForReservation(Reservation $reservation): void
    {
        $existing = BlockZoneWorklist::query()
            ->where('merchant_transaction_id', $reservation->merchant_transaction_id)
            ->first();

        if ($existing !== null && $existing->isTerminalResolved()) {
            // Preserve audit rows (acknowledged / converted). Daily-ticket conversion
            // leaves a terminal row that must not be deleted by kind-based cleanup.
            if ($existing->isConvertedToDailyFee()) {
                return;
            }
            if ($existing->isAcknowledged() && $existing->matchesAcknowledgedFingerprint($reservation)) {
                return;
            }
            // Acknowledged but fingerprint drifted (moved) — fall through only for timed occupancy.
        }

        if ($reservation->isDailyTicket()) {
            $this->deleteConfirmedWorklistIfPresent($reservation);

            return;
        }

        if ($existing !== null
            && $existing->isAcknowledged()
            && $existing->matchesAcknowledgedFingerprint($reservation)) {
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

        // Reservation moved off the acknowledged fingerprint onto newly blocked slots → new intervention.
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
                'reviewed_by_admin_id' => null,
                'reviewed_at' => null,
                'resolution_note' => null,
            ],
        );
    }

    /**
     * Admin confirms the reservation was honored despite the blockade; no slot move required.
     *
     * @throws ValidationException
     */
    public function acknowledgeRealized(
        BlockZoneWorklist $row,
        int $adminId,
        ?string $note = null,
    ): void {
        DB::transaction(function () use ($row, $adminId, $note): void {
            /** @var BlockZoneWorklist|null $locked */
            $locked = BlockZoneWorklist::query()
                ->whereKey($row->id)
                ->lockForUpdate()
                ->first();

            if ($locked === null) {
                throw ValidationException::withMessages([
                    'worklist' => ['Stavka više nije dostupna.'],
                ]);
            }

            if ($locked->status === BlockZoneWorklist::STATUS_ACKNOWLEDGED_NO_ADJUSTMENT) {
                // Idempotent: already acknowledged.
                return;
            }

            if ($locked->status !== BlockZoneWorklist::STATUS_READY_TO_ADJUST) {
                throw ValidationException::withMessages([
                    'worklist' => ['Samo stavke spremne za prilagođavanje (ready_to_adjust) mogu se potvrditi kao realizovane.'],
                ]);
            }

            if ($locked->reservation_id === null) {
                throw ValidationException::withMessages([
                    'worklist' => ['Stavka nema povezanu rezervaciju.'],
                ]);
            }

            /** @var Reservation|null $reservation */
            $reservation = Reservation::query()
                ->whereKey((int) $locked->reservation_id)
                ->lockForUpdate()
                ->first();

            if ($reservation === null) {
                throw ValidationException::withMessages([
                    'worklist' => ['Povezana rezervacija nije pronađena.'],
                ]);
            }

            if ((string) $reservation->merchant_transaction_id !== (string) $locked->merchant_transaction_id) {
                throw ValidationException::withMessages([
                    'worklist' => ['Rezervacija ne odgovara stavci workliste.'],
                ]);
            }

            $resDate = $reservation->reservation_date->toDateString();
            if ($resDate !== $locked->old_date->toDateString()
                || (int) $reservation->drop_off_time_slot_id !== (int) $locked->old_drop_off
                || (int) $reservation->pick_up_time_slot_id !== (int) $locked->old_pick_up) {
                throw ValidationException::withMessages([
                    'worklist' => ['Rezervacija je u međuvremenu izmijenjena (datum/termini). Osvježite worklistu ili prilagodite rezervaciju.'],
                ]);
            }

            $noteTrimmed = $note !== null ? trim($note) : null;
            if ($noteTrimmed === '') {
                $noteTrimmed = null;
            }
            if ($noteTrimmed !== null && mb_strlen($noteTrimmed) > 500) {
                throw ValidationException::withMessages([
                    'resolution_note' => ['Napomena može imati najviše 500 karaktera.'],
                ]);
            }

            $locked->update([
                'status' => BlockZoneWorklist::STATUS_ACKNOWLEDGED_NO_ADJUSTMENT,
                'reviewed_by_admin_id' => $adminId,
                'reviewed_at' => now(),
                'resolution_note' => $noteTrimmed,
                // Fingerprint stays as old_* matching current reservation occupancy.
                'old_date' => $resDate,
                'old_drop_off' => (int) $reservation->drop_off_time_slot_id,
                'old_pick_up' => (int) $reservation->pick_up_time_slot_id,
            ]);

            Log::channel('payments')->info('block_zone_worklist_acknowledged_realized', [
                'worklist_id' => (int) $locked->id,
                'reservation_id' => (int) $reservation->id,
                'merchant_transaction_id' => (string) $locked->merchant_transaction_id,
                'admin_id' => $adminId,
                'old_date' => $resDate,
                'old_drop_off' => (int) $reservation->drop_off_time_slot_id,
                'old_pick_up' => (int) $reservation->pick_up_time_slot_id,
                'has_note' => $noteTrimmed !== null,
            ]);
        });
    }

    /**
     * Convert a blocked timed reservation into a daily-ticket reservation for an eligible date.
     *
     * Releases timed slot capacity; does not change money/fiscal fields; does not unblock slots.
     *
     * @return array{reservation_id:int, changed_fields:list<string>, already_converted:bool}
     *
     * @throws ValidationException
     */
    public function convertToDailyFee(
        BlockZoneWorklist $row,
        string $destinationDate,
        int $adminId,
        ?string $note = null,
    ): array {
        $result = [
            'reservation_id' => 0,
            'changed_fields' => [],
            'already_converted' => false,
        ];

        DB::transaction(function () use ($row, $destinationDate, $adminId, $note, &$result): void {
            /** @var BlockZoneWorklist|null $locked */
            $locked = BlockZoneWorklist::query()
                ->whereKey($row->id)
                ->lockForUpdate()
                ->first();

            if ($locked === null) {
                throw ValidationException::withMessages([
                    'worklist' => ['Stavka više nije dostupna.'],
                ]);
            }

            if ($locked->status === BlockZoneWorklist::STATUS_CONVERTED_TO_DAILY_FEE) {
                $result['already_converted'] = true;
                $result['reservation_id'] = (int) ($locked->reservation_id ?? 0);

                return;
            }

            if ($locked->status !== BlockZoneWorklist::STATUS_READY_TO_ADJUST) {
                throw ValidationException::withMessages([
                    'worklist' => ['Samo stavke spremne za prilagođavanje (ready_to_adjust) mogu se pretvoriti u dnevnu naknadu.'],
                ]);
            }

            if ($locked->reservation_id === null) {
                throw ValidationException::withMessages([
                    'worklist' => ['Stavka nema povezanu rezervaciju.'],
                ]);
            }

            /** @var Reservation|null $reservation */
            $reservation = Reservation::query()
                ->whereKey((int) $locked->reservation_id)
                ->lockForUpdate()
                ->first();

            if ($reservation === null) {
                throw ValidationException::withMessages([
                    'worklist' => ['Povezana rezervacija nije pronađena.'],
                ]);
            }

            if ((string) $reservation->merchant_transaction_id !== (string) $locked->merchant_transaction_id) {
                throw ValidationException::withMessages([
                    'worklist' => ['Rezervacija ne odgovara stavci workliste.'],
                ]);
            }

            if (! $reservation->isTimeSlots()) {
                throw ValidationException::withMessages([
                    'worklist' => ['Rezervacija nije vremenska (Termini) i ne može se pretvoriti.'],
                ]);
            }

            $oldDate = $reservation->reservation_date->toDateString();
            $oldDrop = (int) $reservation->drop_off_time_slot_id;
            $oldPick = (int) $reservation->pick_up_time_slot_id;

            if ($oldDate !== $locked->old_date->toDateString()
                || $oldDrop !== (int) $locked->old_drop_off
                || $oldPick !== (int) $locked->old_pick_up) {
                throw ValidationException::withMessages([
                    'worklist' => ['Rezervacija je u međuvremenu izmijenjena (datum/termini). Osvježite worklistu.'],
                ]);
            }

            $today = now()->startOfDay()->toDateString();
            $max = now()->copy()->addDays(90)->toDateString();
            if ($destinationDate < $today) {
                throw ValidationException::withMessages([
                    'daily_fee_date' => ['Datum dnevne naknade ne smije biti u prošlosti.'],
                ]);
            }
            if ($destinationDate > $max) {
                throw ValidationException::withMessages([
                    'daily_fee_date' => ['Datum dnevne naknade je van dozvoljenog opsega (danas … +90 dana).'],
                ]);
            }

            if (app(DailyFeeBlockedDateService::class)->isSaleProhibited($destinationDate)) {
                throw ValidationException::withMessages([
                    'daily_fee_date' => ['Prodaja dnevne naknade za izabrani datum je zabranjena.'],
                ]);
            }

            $noteTrimmed = $note !== null ? trim($note) : null;
            if ($noteTrimmed === '') {
                $noteTrimmed = null;
            }
            if ($noteTrimmed !== null && mb_strlen($noteTrimmed) > 500) {
                throw ValidationException::withMessages([
                    'resolution_note' => ['Napomena može imati najviše 500 karaktera.'],
                ]);
            }

            $uOld = array_values(array_unique([$oldDrop, $oldPick]));
            sort($uOld);
            foreach ($uOld as $sid) {
                $daily = DailyParkingData::query()
                    ->whereDate('date', $oldDate)
                    ->where('time_slot_id', $sid)
                    ->lockForUpdate()
                    ->first();
                if ($daily === null) {
                    throw ValidationException::withMessages([
                        'worklist' => ['Nedostaje daily_parking_data za stari termin.'],
                    ]);
                }
                if ((int) $daily->reserved < 1) {
                    throw ValidationException::withMessages([
                        'worklist' => ['Kapacitet starog termina je već 0; konverzija nije sigurna.'],
                    ]);
                }
                $daily->decrement('reserved');
            }

            $reservation->update([
                'reservation_kind' => Reservation::KIND_DAILY_TICKET,
                'reservation_date' => $destinationDate,
                'drop_off_time_slot_id' => null,
                'pick_up_time_slot_id' => null,
                'invoice_sent_at' => null,
                'email_sent' => Reservation::EMAIL_NOT_SENT,
            ]);

            $locked->update([
                'status' => BlockZoneWorklist::STATUS_CONVERTED_TO_DAILY_FEE,
                'reviewed_by_admin_id' => $adminId,
                'reviewed_at' => now(),
                'resolution_note' => $noteTrimmed,
                // Keep original timed fingerprint for audit.
                'old_date' => $oldDate,
                'old_drop_off' => $oldDrop,
                'old_pick_up' => $oldPick,
                'affected_drop_off' => false,
                'affected_pick_up' => false,
                'reservation_id' => $reservation->id,
            ]);

            Log::channel('payments')->info('block_zone_worklist_converted_to_daily_fee', [
                'worklist_id' => (int) $locked->id,
                'reservation_id' => (int) $reservation->id,
                'merchant_transaction_id' => (string) $locked->merchant_transaction_id,
                'admin_id' => $adminId,
                'old_kind' => Reservation::KIND_TIME_SLOTS,
                'new_kind' => Reservation::KIND_DAILY_TICKET,
                'old_date' => $oldDate,
                'old_drop_off' => $oldDrop,
                'old_pick_up' => $oldPick,
                'new_date' => $destinationDate,
                'has_note' => $noteTrimmed !== null,
            ]);

            $result['reservation_id'] = (int) $reservation->id;
            $result['changed_fields'] = [
                'reservation_kind',
                'reservation_date',
                'drop_off_time_slot_id',
                'pick_up_time_slot_id',
            ];
        });

        return $result;
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
        // Keep terminal audit rows even if slots are later unblocked or kind becomes daily ticket.
        if ($row->isTerminalResolved()) {
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

        if ($row->isTerminalResolved()) {
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

        if ($row->isTerminalResolved()) {
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
