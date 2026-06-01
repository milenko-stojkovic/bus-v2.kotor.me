<?php

namespace App\Services\AdminPanel\Reservation;

use App\Models\Reservation;

final class AdminReservationFieldChangeTracker
{
    /**
     * @param  array<string, mixed>  $data
     * @return list<string>
     */
    public static function diff(Reservation $before, array $data, bool $includeSlots = true): array
    {
        $changed = [];

        if ($before->reservation_date->toDateString() !== (string) $data['reservation_date']) {
            $changed[] = 'reservation_date';
        }

        if ($includeSlots) {
            if ((int) $before->drop_off_time_slot_id !== (int) $data['drop_off_time_slot_id']) {
                $changed[] = 'drop_off_time_slot_id';
            }
            if ((int) $before->pick_up_time_slot_id !== (int) $data['pick_up_time_slot_id']) {
                $changed[] = 'pick_up_time_slot_id';
            }
        }

        foreach (['user_name', 'country', 'license_plate', 'email'] as $field) {
            if ((string) $before->{$field} !== (string) $data[$field]) {
                $changed[] = $field;
            }
        }

        if ((int) $before->vehicle_type_id !== (int) $data['vehicle_type_id']) {
            $changed[] = 'vehicle_type_id';
        }

        return $changed;
    }
}
