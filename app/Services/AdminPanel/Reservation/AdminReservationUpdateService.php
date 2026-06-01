<?php

namespace App\Services\AdminPanel\Reservation;

use App\Models\DailyParkingData;
use App\Models\ListOfTimeSlot;
use App\Models\Reservation;
use App\Models\VehicleType;
use App\Services\AdminPanel\Blocking\BlockReservationAdjustmentValidator;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

final class AdminReservationUpdateService
{
    public function __construct(
        private BlockReservationAdjustmentValidator $finalValidator,
        private AdminReservationSlotRules $slotRules,
    ) {}

    /**
     * @param  array{
     *   reservation_date:string,
     *   drop_off_time_slot_id:int,
     *   pick_up_time_slot_id:int,
     *   user_name:string,
     *   country:string,
     *   license_plate:string,
     *   vehicle_type_id:int,
     *   email:string,
     * }  $data
     * @return list<string>
     */
    public function apply(Reservation $reservation, array $data): array
    {
        $changedFields = [];
        $oldDate = $reservation->reservation_date->toDateString();
        $oldDrop = (int) $reservation->drop_off_time_slot_id;
        $oldPick = (int) $reservation->pick_up_time_slot_id;

        $newDate = (string) $data['reservation_date'];
        $newDrop = (int) $data['drop_off_time_slot_id'];
        $newPick = (int) $data['pick_up_time_slot_id'];

        $dropSlot = ListOfTimeSlot::query()->findOrFail($newDrop);
        $pickSlot = ListOfTimeSlot::query()->findOrFail($newPick);
        $day = Carbon::parse($newDate)->startOfDay();

        $this->slotRules->assertPairAllowedForReservation($reservation, $dropSlot, $pickSlot, $day);

        $newVtId = (int) $data['vehicle_type_id'];
        $vt = VehicleType::query()->findOrFail($newVtId);
        $currentVt = $reservation->vehicleType;
        $currentPrice = $currentVt !== null ? (float) $currentVt->price : 0.0;
        if ((float) $vt->price > $currentPrice) {
            throw new \RuntimeException('Nije dozvoljena veća kategorija vozila od trenutne.');
        }

        DB::transaction(function () use (
            $reservation,
            $data,
            $oldDate,
            $oldDrop,
            $oldPick,
            $newDate,
            $newDrop,
            $newPick,
            $newVtId,
            &$changedFields,
        ): void {
            /** @var Reservation|null $r */
            $r = Reservation::query()->whereKey($reservation->id)->lockForUpdate()->first();
            if (! $r) {
                throw new \RuntimeException('Rezervacija nije pronađena.');
            }

            $tuples = [
                [$oldDate, $oldDrop],
                [$oldDate, $oldPick],
                [$newDate, $newDrop],
                [$newDate, $newPick],
            ];
            $uniqueKeys = [];
            foreach ($tuples as [$d, $sid]) {
                $uniqueKeys[$d.'|'.$sid] = [$d, $sid];
            }
            $sorted = array_values($uniqueKeys);
            usort($sorted, function (array $a, array $b): int {
                if ($a[0] !== $b[0]) {
                    return $a[0] <=> $b[0];
                }

                return $a[1] <=> $b[1];
            });

            /** @var array<string, DailyParkingData> $dailyByKey */
            $dailyByKey = [];
            foreach ($sorted as [$d, $sid]) {
                $key = $d.'|'.$sid;
                $m = DailyParkingData::query()
                    ->whereDate('date', $d)
                    ->where('time_slot_id', $sid)
                    ->lockForUpdate()
                    ->first();
                if ($m === null) {
                    throw new \RuntimeException('Nedostaje daily_parking_data za datum/termin.');
                }
                $dailyByKey[$key] = $m;
            }

            $this->finalValidator->assertValidAfterLock(
                $oldDate,
                $newDate,
                $oldDrop,
                $oldPick,
                $newDrop,
                $newPick,
                $dailyByKey,
            );

            $get = fn (string $date, int $slotId): DailyParkingData => $dailyByKey[$date.'|'.$slotId];

            $uOld = array_values(array_unique([$oldDrop, $oldPick]));
            $uNew = array_values(array_unique([$newDrop, $newPick]));

            if ($oldDate === $newDate) {
                $ids = array_values(array_unique(array_merge($uOld, $uNew)));
                foreach ($ids as $sid) {
                    $delta = (in_array($sid, $uNew, true) ? 1 : 0) - (in_array($sid, $uOld, true) ? 1 : 0);
                    if ($delta === 1) {
                        $get($oldDate, $sid)->increment('reserved');
                    } elseif ($delta === -1) {
                        $get($oldDate, $sid)->decrement('reserved');
                    }
                }
            } else {
                foreach ($uOld as $sid) {
                    $get($oldDate, $sid)->decrement('reserved');
                }
                foreach ($uNew as $sid) {
                    $get($newDate, $sid)->increment('reserved');
                }
            }

            $changedFields = AdminReservationFieldChangeTracker::diff($r, $data, includeSlots: true);

            $r->update([
                'reservation_date' => $newDate,
                'drop_off_time_slot_id' => $newDrop,
                'pick_up_time_slot_id' => $newPick,
                'user_name' => $data['user_name'],
                'country' => $data['country'],
                'license_plate' => $data['license_plate'],
                'vehicle_type_id' => $newVtId,
                'email' => $data['email'],
                'invoice_sent_at' => null,
                'email_sent' => Reservation::EMAIL_NOT_SENT,
            ]);

            Log::channel('payments')->info('admin_panel_reservation_updated', [
                'reservation_id' => $r->id,
                'merchant_transaction_id' => $r->merchant_transaction_id,
                'old_date' => $oldDate,
                'new_date' => $newDate,
                'changed_fields' => $changedFields,
            ]);
        });

        return $changedFields;
    }
}
