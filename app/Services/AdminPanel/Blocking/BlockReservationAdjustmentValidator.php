<?php

namespace App\Services\AdminPanel\Blocking;

use App\Models\DailyParkingData;

/**
 * Finalna validacija prilagođavanja rezervacije — mora se pozvati posle lockForUpdate na relevantnim redovima.
 */
final class BlockReservationAdjustmentValidator
{
    /**
     * @param  array<string, DailyParkingData>  $dailyByKey ključ: "{$date}|{$timeSlotId}"
     *
     * @throws \RuntimeException ako finalna provera ne prođe
     */
    public function assertValidAfterLock(
        string $oldDate,
        string $newDate,
        int $oldDrop,
        int $oldPick,
        int $newDrop,
        int $newPick,
        array $dailyByKey,
    ): void {
        $uOld = array_values(array_unique([$oldDrop, $oldPick]));
        $uNew = array_values(array_unique([$newDrop, $newPick]));

        foreach ($uNew as $slotId) {
            $row = $this->row($dailyByKey, $newDate, $slotId);
            // Retaining the reservation's current slot is allowed even if that slot was blocked later.
            // Entering a *different* blocked slot is forbidden.
            $isNewlyEntered = $oldDate !== $newDate || ! in_array($slotId, $uOld, true);
            if ($isNewlyEntered && $row->is_blocked) {
                throw new \RuntimeException('Novi termin je blokiran.');
            }
            if ($isNewlyEntered && (int) $row->pending !== 0) {
                throw new \RuntimeException('Novi termin ima pending — pokušajte ponovo posle osvežavanja.');
            }
        }

        if ($oldDate !== $newDate) {
            foreach ($uOld as $slotId) {
                $row = $this->row($dailyByKey, $oldDate, $slotId);
                if ((int) $row->reserved < 1) {
                    throw new \RuntimeException('Konflikt stanja: stari termin nema očekivanu rezervisanost.');
                }
            }
            foreach ($uNew as $slotId) {
                $row = $this->row($dailyByKey, $newDate, $slotId);
                if ($row->availableCapacity() < 1) {
                    throw new \RuntimeException('Novi termin nema dovoljno slobodnih mesta (provera pod lock-om).');
                }
            }

            return;
        }

        $ids = array_values(array_unique(array_merge($uOld, $uNew)));
        foreach ($ids as $slotId) {
            $row = $this->row($dailyByKey, $oldDate, $slotId);
            $isNewlyEntered = in_array($slotId, $uNew, true) && ! in_array($slotId, $uOld, true);
            if ($isNewlyEntered && $row->is_blocked) {
                throw new \RuntimeException('Novi termin je blokiran.');
            }
            if ($isNewlyEntered && (int) $row->pending !== 0) {
                throw new \RuntimeException('Novi termin ima pending — pokušajte ponovo posle osvežavanja.');
            }

            $finalReserved = (int) $row->reserved;
            if (in_array($slotId, $uOld, true)) {
                $finalReserved -= 1;
            }
            if (in_array($slotId, $uNew, true)) {
                $finalReserved += 1;
            }
            if ($finalReserved < 0) {
                throw new \RuntimeException('Konflikt stanja: negativna rezervisanost.');
            }
            if ((int) $row->capacity - (int) $row->pending < $finalReserved) {
                throw new \RuntimeException('Novi izbor nema dovoljno kapaciteta na istom danu (provera pod lock-om).');
            }
        }
    }

    /**
     * @param  array<string, DailyParkingData>  $dailyByKey
     */
    private function row(array $dailyByKey, string $date, int $slotId): DailyParkingData
    {
        $key = $date.'|'.$slotId;
        if (! isset($dailyByKey[$key])) {
            throw new \RuntimeException('Nedostaje daily_parking_data za datum/termin.');
        }

        return $dailyByKey[$key];
    }
}
