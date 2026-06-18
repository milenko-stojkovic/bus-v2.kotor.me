<?php

namespace App\Services\Operations;

use App\Models\DailyParkingData;
use App\Models\ListOfTimeSlot;
use App\Models\Reservation;
use App\Models\SystemConfig;
use App\Support\ReservationKind;
use Carbon\Carbon;

/**
 * Read-only operational dataset builder for daily capacity charts.
 *
 * Source-of-truth numbers are from daily_parking_data (reserved + pending).
 */
final class DailyCapacityChartService
{
    /**
     * @return array{
     *   date: string,
     *   capacity: int,
     *   slots: list<array{
     *     slot_number: int,
     *     slot_id: int,
     *     time_label: string,
     *     reserved: int,
     *     pending: int,
     *     total: int
     *   }>,
     *   meta: array{max_total:int, reservations_total:int}
     * }
     */
    public function forDate(Carbon $day): array
    {
        $date = $day->copy()->startOfDay()->toDateString();

        $capacity = $this->capacity();
        $reservationsTotal = $this->confirmedTimeSlotsReservationsCount($date);

        $slots = ListOfTimeSlot::query()->orderBy('id')->get();
        $dailyBySlotId = DailyParkingData::query()
            ->whereDate('date', $date)
            ->get()
            ->keyBy('time_slot_id');

        $out = [];
        $maxTotal = 0;
        foreach ($slots as $idx => $slot) {
            $daily = $dailyBySlotId->get($slot->id);
            $reserved = (int) ($daily?->reserved ?? 0);
            $pending = (int) ($daily?->pending ?? 0);
            $total = $reserved + $pending;
            $maxTotal = max($maxTotal, $total);

            $out[] = [
                'slot_number' => $idx + 1,
                'slot_id' => (int) $slot->id,
                'time_label' => (string) $slot->time_slot,
                'reserved' => $reserved,
                'pending' => $pending,
                'total' => $total,
            ];
        }

        return [
            'date' => $date,
            'capacity' => $capacity,
            'slots' => $out,
            'meta' => [
                'max_total' => $maxTotal,
                'reservations_total' => $reservationsTotal,
            ],
        ];
    }

    /** Confirmed Termini reservations (paid + free) for the given calendar day. */
    private function confirmedTimeSlotsReservationsCount(string $date): int
    {
        return (int) Reservation::query()
            ->whereDate('reservation_date', $date)
            ->whereIn('status', ['paid', 'free'])
            ->where(function ($query): void {
                $query->where('reservation_kind', ReservationKind::TIME_SLOTS)
                    ->orWhereNull('reservation_kind');
            })
            ->count();
    }

    private function capacity(): int
    {
        $cap = (int) SystemConfig::availableParkingSlots();
        if ($cap <= 0) {
            return 9;
        }

        return $cap;
    }

    /**
     * @return array{today: array<string,mixed>, tomorrow: array<string,mixed>, timezone: string}
     */
    public function todayAndTomorrow(): array
    {
        $tz = (string) config('reservations.operations_timezone', 'Europe/Podgorica');
        $today = Carbon::now($tz)->startOfDay();
        $tomorrow = $today->copy()->addDay();

        return [
            'timezone' => $tz,
            'today' => $this->forDate($today),
            'tomorrow' => $this->forDate($tomorrow),
        ];
    }
}

