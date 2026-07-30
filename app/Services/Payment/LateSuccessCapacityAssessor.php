<?php

namespace App\Services\Payment;

use App\Models\DailyParkingData;
use App\Models\TempData;

/**
 * Read-only capacity hint for Staff Late Success Force UI.
 * Does not block Force — administrator decides.
 */
final class LateSuccessCapacityAssessor
{
    /**
     * @return array{
     *     available: bool,
     *     message: string,
     *     slots: list<array{time_slot_id:int,label:string,capacity:int,reserved:int,pending:int,available:int}>
     * }
     */
    public function assess(TempData $temp): array
    {
        if (! $temp->isTimeSlots()) {
            return [
                'available' => true,
                'message' => 'This payment is not a time-slot booking; capacity check does not apply.',
                'slots' => [],
            ];
        }

        $slotIds = array_values(array_unique(array_filter([
            $temp->drop_off_time_slot_id,
            $temp->pick_up_time_slot_id,
        ], fn ($id) => $id !== null)));

        if ($slotIds === [] || $temp->reservation_date === null) {
            return [
                'available' => true,
                'message' => 'Slot data is incomplete; capacity could not be evaluated.',
                'slots' => [],
            ];
        }

        $rows = DailyParkingData::query()
            ->with('timeSlot')
            ->whereDate('date', $temp->reservation_date)
            ->whereIn('time_slot_id', $slotIds)
            ->get()
            ->keyBy('time_slot_id');

        $slots = [];
        $allAvailable = true;

        foreach ($slotIds as $slotId) {
            $row = $rows->get($slotId);
            $label = $row?->timeSlot?->time_slot ?? ('slot #'.$slotId);
            if ($row === null) {
                $allAvailable = false;
                $slots[] = [
                    'time_slot_id' => (int) $slotId,
                    'label' => $label,
                    'capacity' => 0,
                    'reserved' => 0,
                    'pending' => 0,
                    'available' => 0,
                ];

                continue;
            }

            $available = $row->availableCapacity();
            if ($available < 1 || $row->is_blocked) {
                $allAvailable = false;
            }

            $slots[] = [
                'time_slot_id' => (int) $slotId,
                'label' => $label,
                'capacity' => (int) $row->capacity,
                'reserved' => (int) $row->reserved,
                'pending' => (int) $row->pending,
                'available' => $available,
            ];
        }

        return [
            'available' => $allAvailable,
            'message' => $allAvailable
                ? 'The requested arrival/departure slots are still available.'
                : 'These slots are no longer available. Creating this reservation will exceed configured capacity.',
            'slots' => $slots,
        ];
    }
}
