<?php

namespace App\Services\AdminPanel\Reservation;

use App\Models\Reservation;
use App\Services\Reservation\PanelReservationListService;
use Carbon\Carbon;

/**
 * Pravila kada admin sme da uređuje rezervaciju i ograničenja posle prošlog drop-off termina.
 */
final class AdminReservationEditPolicy
{
    public static function canEdit(Reservation $reservation): bool
    {
        return ! PanelReservationListService::isRealized($reservation);
    }

    public static function assertEditable(Reservation $reservation): void
    {
        if (PanelReservationListService::isRealized($reservation)) {
            throw new \RuntimeException('Realizovana rezervacija se ne može mijenjati.');
        }
    }

    /**
     * Drop-off termin je već počeo, ali pick-up još nije realizovan — dozvoljena je samo izmena pick-up termina.
     */
    public static function isPickUpOnlyMode(Reservation $reservation): bool
    {
        if ($reservation->isDailyTicket()) {
            return false;
        }
        if (! PanelReservationListService::isUpcoming($reservation)) {
            return false;
        }

        $day = $reservation->reservation_date->copy()->startOfDay();
        if (! $day->isSameDay(now()->startOfDay())) {
            return false;
        }

        $drop = $reservation->dropOffTimeSlot;
        if ($drop === null) {
            return false;
        }

        $dropStart = $drop->getStartTimeForDate($day);
        if ($dropStart === null) {
            return false;
        }

        return now()->gte($dropStart);
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    public static function enforcePickUpOnlyFields(Reservation $reservation, array $data): array
    {
        if (! self::isPickUpOnlyMode($reservation)) {
            return $data;
        }

        $data['reservation_date'] = $reservation->reservation_date->toDateString();
        $data['drop_off_time_slot_id'] = (int) $reservation->drop_off_time_slot_id;

        return $data;
    }
}
