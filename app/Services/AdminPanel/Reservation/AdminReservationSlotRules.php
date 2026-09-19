<?php

namespace App\Services\AdminPanel\Reservation;

use App\Models\DailyParkingData;
use App\Models\ListOfTimeSlot;
use App\Models\Reservation;
use App\Services\Reservation\FreeReservationRules;
use Carbon\Carbon;

/**
 * Pravila izbora termina za admin izmenu rezervacije (prefilter + poslovna pravila free/paid).
 */
final class AdminReservationSlotRules
{
    public function slotSelectableOnDaily(
        Reservation $reservation,
        string $dateStr,
        int $slotId,
        DailyParkingData $daily,
    ): bool {
        $isCurrentDate = $reservation->reservation_date->toDateString() === $dateStr;
        $isCurrentSlot = $isCurrentDate
            && ($slotId === (int) $reservation->drop_off_time_slot_id || $slotId === (int) $reservation->pick_up_time_slot_id);

        // Current occupancy may sit on a slot that was blocked later — still representable in edit form.
        if ($isCurrentSlot) {
            return true;
        }

        if ($daily->is_blocked) {
            return false;
        }
        if ((int) $daily->pending !== 0) {
            return false;
        }

        return $daily->availableCapacity() >= 1;
    }

    /**
     * Da li par termina sme da ostane / bude izabran posle izmene.
     */
    public function assertPairAllowedForReservation(
        Reservation $reservation,
        ListOfTimeSlot $drop,
        ListOfTimeSlot $pick,
        Carbon $day,
    ): void {
        $dropStart = $drop->getStartTimeForDate($day);
        $pickStart = $pick->getStartTimeForDate($day);
        if ($dropStart === null || $pickStart === null) {
            throw new \RuntimeException('Nije moguće parsirati vreme termina.');
        }
        if ($dropStart->gt($pickStart)) {
            throw new \RuntimeException('Vrijeme dolaska mora biti prije ili jednako vremenu odlaska.');
        }

        if ($reservation->status !== 'free') {
            return;
        }

        if ($reservation->created_by_admin) {
            return;
        }

        if (! FreeReservationRules::isFreeReservation($drop, $pick)) {
            throw new \RuntimeException('Za ovu besplatnu rezervaciju dozvoljeni su samo termini u besplatnom prozoru (free→free).');
        }
    }
}
