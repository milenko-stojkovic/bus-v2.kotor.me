<?php

namespace App\Services\AdminPanel\Agency;

use App\Models\Reservation;
use App\Models\User;
use App\Models\Vehicle;
use App\Support\ReservationKind;
use App\Support\ReservationOrigin;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;

final class AgencyV2ReservationStatisticsService
{
    /**
     * Official V2 statistics for the current calendar year.
     * Agency-linked native V2 rows only. Imported V1 rows stay out after user_id is set.
     *
     * @return array<string, mixed>
     */
    public function compute(User $agency): array
    {
        $tz = (string) config('app.timezone', 'Europe/Podgorica');
        $year = (int) now($tz)->year;
        $from = Carbon::create($year, 1, 1, 0, 0, 0, $tz)->toDateString();
        $to = Carbon::create($year, 12, 31, 0, 0, 0, $tz)->toDateString();

        $base = Reservation::query()
            ->where('user_id', $agency->id)
            ->whereDate('reservation_date', '>=', $from)
            ->whereDate('reservation_date', '<=', $to);
        ReservationOrigin::scopeNativeV2($base);

        $aggregate = (clone $base)->select([
            DB::raw('COUNT(*) as total'),
            DB::raw("SUM(CASE WHEN status = 'paid' THEN 1 ELSE 0 END) as paid_count"),
            DB::raw("SUM(CASE WHEN status = 'free' THEN 1 ELSE 0 END) as free_count"),
            DB::raw("SUM(CASE WHEN reservation_kind = '".ReservationKind::TIME_SLOTS."' THEN 1 ELSE 0 END) as time_slots_count"),
            DB::raw("SUM(CASE WHEN reservation_kind = '".ReservationKind::DAILY_TICKET."' THEN 1 ELSE 0 END) as daily_ticket_count"),
            DB::raw("SUM(CASE WHEN status = 'paid' THEN COALESCE(invoice_amount, 0) ELSE 0 END) as total_paid_amount"),
            DB::raw("SUM(CASE WHEN status = 'paid' AND reservation_kind = '".ReservationKind::TIME_SLOTS."' THEN COALESCE(invoice_amount, 0) ELSE 0 END) as time_slots_paid_amount"),
            DB::raw("SUM(CASE WHEN status = 'paid' AND reservation_kind = '".ReservationKind::DAILY_TICKET."' THEN COALESCE(invoice_amount, 0) ELSE 0 END) as daily_ticket_paid_amount"),
        ])->first();

        $distinctPlates = (int) (clone $base)
            ->whereNotNull('license_plate')
            ->where('license_plate', '!=', '')
            ->distinct()
            ->count('license_plate');

        $activeVehicles = Vehicle::query()
            ->where('user_id', $agency->id)
            ->where('status', Vehicle::STATUS_ACTIVE)
            ->count();

        $firstReservation = (clone $base)->orderBy('reservation_date')->orderBy('id')->value('reservation_date');
        $lastReservation = (clone $base)->orderByDesc('reservation_date')->orderByDesc('id')->value('reservation_date');

        $total = (int) ($aggregate->total ?? 0);
        $monthsElapsed = max(1, (int) now($tz)->month);

        return [
            'period_year' => $year,
            'period_from' => $from,
            'period_to' => $to,
            'total_reservations' => $total,
            'paid_reservations' => (int) ($aggregate->paid_count ?? 0),
            'free_reservations' => (int) ($aggregate->free_count ?? 0),
            'time_slots_count' => (int) ($aggregate->time_slots_count ?? 0),
            'daily_ticket_count' => (int) ($aggregate->daily_ticket_count ?? 0),
            'total_paid_amount' => (float) ($aggregate->total_paid_amount ?? 0),
            'time_slots_paid_amount' => (float) ($aggregate->time_slots_paid_amount ?? 0),
            'daily_ticket_paid_amount' => (float) ($aggregate->daily_ticket_paid_amount ?? 0),
            'distinct_license_plates' => $distinctPlates,
            'active_vehicles' => $activeVehicles,
            'first_reservation_date' => $firstReservation !== null ? Carbon::parse($firstReservation)->toDateString() : null,
            'last_reservation_date' => $lastReservation !== null ? Carbon::parse($lastReservation)->toDateString() : null,
            'avg_reservations_per_month' => $total > 0 ? round($total / $monthsElapsed, 1) : 0.0,
        ];
    }
}
