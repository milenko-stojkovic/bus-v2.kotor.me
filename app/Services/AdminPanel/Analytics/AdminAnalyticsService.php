<?php

namespace App\Services\AdminPanel\Analytics;

use App\Models\DailyParkingData;
use App\Models\LimoPickupEvent;
use App\Models\ListOfTimeSlot;
use App\Models\PostFiscalizationData;
use App\Models\Reservation;
use App\Models\TempData;
use App\Models\VehicleType;
use App\Support\ReservationKind;
use Carbon\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

final class AdminAnalyticsService
{
    /**
     * @return array<string, mixed> Aggregate dataset (dashboard + PDF).
     */
    public function build(string $dateFrom, string $dateTo, bool $includeFree): array
    {
        $from = Carbon::parse($dateFrom)->startOfDay();
        $to = Carbon::parse($dateTo)->startOfDay();

        $slots = ListOfTimeSlot::query()->orderBy('id')->get();
        $slotById = $slots->keyBy('id');
        $slotCount = $slots->count();
        $dayCount = (int) $from->diffInDays($to) + 1;

        $reservations = Reservation::query()
            ->with(['user', 'vehicleType.translations', 'dropOffTimeSlot', 'pickUpTimeSlot'])
            // Use whereDate to be safe across SQLite/MySQL date storage differences.
            ->whereDate('reservation_date', '>=', $from->toDateString())
            ->whereDate('reservation_date', '<=', $to->toDateString())
            ->when(! $includeFree, fn ($q) => $q->where('status', 'paid'))
            ->get();

        // Extra free reservations for agency breakdown when include_free is OFF.
        // Keep it lightweight: only what we need for counts/percentages.
        $freeForAgencyWhenExcluded = collect();
        if (! $includeFree) {
            $freeForAgencyWhenExcluded = Reservation::query()
                ->where('status', 'free')
                ->whereNotNull('user_id')
                ->where('created_by_admin', false)
                ->whereDate('reservation_date', '>=', $from->toDateString())
                ->whereDate('reservation_date', '<=', $to->toDateString())
                ->get(['id', 'user_id']);
        }

        $paid = $reservations->where('status', 'paid');
        $free = $reservations->where('status', 'free');

        $timeSlotsReservations = $this->onlyTimeSlots($reservations);
        $dailyTicketReservations = $this->onlyDailyTickets($reservations);
        $paidTimeSlots = $this->onlyTimeSlots($paid);
        $paidDailyTickets = $this->onlyDailyTickets($paid);

        $revenueTotal = (float) $paid->sum(fn (Reservation $r) => (float) ($r->invoice_amount ?? 0));
        $timeSlotsRevenue = (float) $paidTimeSlots->sum(fn (Reservation $r) => (float) ($r->invoice_amount ?? 0));
        $dailyTicketRevenue = (float) $paidDailyTickets->sum(fn (Reservation $r) => (float) ($r->invoice_amount ?? 0));
        $paidCount = $paid->count();
        $freeCount = $free->count();
        $reservationCount = $reservations->count();
        $timeSlotsCount = $timeSlotsReservations->count();
        $dailyTicketCount = $dailyTicketReservations->count();

        $occupiedSlotsTotal = $this->occupiedSlotsFor($reservations);
        $occupiedSlotsPaid = $this->occupiedSlotsFor($paid);
        $occupiedSlotsFree = $this->occupiedSlotsFor($free);

        $maxSlotOccurrences = max(1, $slotCount * $dayCount);
        $avgOccupancySlotLevel = $occupiedSlotsTotal / $maxSlotOccurrences;

        // Blocking / capacity loss metrics from daily_parking_data (source of truth).
        $daily = DailyParkingData::query()
            ->whereDate('date', '>=', $from->toDateString())
            ->whereDate('date', '<=', $to->toDateString())
            ->get();
        $blockedRows = $daily->where('is_blocked', true);
        $blockedSlotRowsCount = $blockedRows->count();
        $blockedCapacity = (int) $blockedRows->sum('capacity');
        $totalCapacity = (int) $daily->sum('capacity');
        $blockedCapacityPct = $totalCapacity > 0 ? ($blockedCapacity / $totalCapacity) : 0.0;

        // Fully blocked day = all slots exist and are blocked.
        $blockedByDate = $blockedRows->groupBy(fn (DailyParkingData $d) => $d->date->toDateString());
        $fullyBlockedDays = 0;
        foreach ($blockedByDate as $dateStr => $rows) {
            $blockedSlotIds = $rows->pluck('time_slot_id')->unique()->count();
            if ($slotCount > 0 && $blockedSlotIds >= $slotCount) {
                $fullyBlockedDays++;
            }
        }

        $blockingTopDays = $blockedByDate
            ->map(function (Collection $rows, string $dateStr) use ($slotCount): array {
                $cnt = $rows->pluck('time_slot_id')->unique()->count();

                return [
                    'date' => $dateStr,
                    'blocked_slots' => $cnt,
                    'blocked_slots_pct' => $slotCount > 0 ? ($cnt / $slotCount) : 0.0,
                ];
            })
            ->sortByDesc('blocked_slots')
            ->take(10)
            ->values()
            ->all();

        // Trend by day.
        $trendByDay = $this->trendByDay($from, $to, $reservations);

        // Day parts (time_slots only) + optional daily ticket row outside slot grouping.
        $dayParts = $this->groupByDayPart($timeSlotsReservations, $slotById, $dailyTicketReservations);

        // Vehicle types.
        $byVehicleType = $this->groupByVehicleType($reservations);

        // Agencies (registered users).
        $byAgency = $this->groupByAgency($reservations, $slotById, $revenueTotal, $includeFree, $freeForAgencyWhenExcluded);

        // Admin free (FZBR) — separate section, independent from include_free.
        $adminFreeReservations = Reservation::query()
            ->with(['user', 'vehicleType.translations', 'dropOffTimeSlot', 'pickUpTimeSlot'])
            ->where('status', 'free')
            ->where('created_by_admin', true)
            ->whereDate('reservation_date', '>=', $from->toDateString())
            ->whereDate('reservation_date', '<=', $to->toDateString())
            ->get();
        $adminFreeByAgency = $this->groupAdminFreeByAgency($adminFreeReservations, $slotById);

        // Countries.
        $byCountry = $this->groupByCountry($reservations);

        // Paid vs free section (always present; free values may be 0 if includeFree=false).
        $paidVsFree = [
            'paid_reservations' => $paidCount,
            'free_reservations' => $freeCount,
            'paid_occupied_slots' => $occupiedSlotsPaid,
            'free_occupied_slots' => $occupiedSlotsFree,
            'paid_revenue' => $revenueTotal,
            'free_capacity_pct_by_slots' => $occupiedSlotsTotal > 0 ? ($occupiedSlotsFree / $occupiedSlotsTotal) : 0.0,
        ];

        // Operational problems / recovery (from existing payment state machine tables).
        $ops = $this->operationalProblems($from, $to);
        $ops['paid_reservations_fully_in_free_zone'] = $this->countPaidReservationsFullyInFreeZones($timeSlotsReservations, $slotById);
        $ops['double_paid_same_slot_pairs'] = $this->countDoublePaidSameSlotPairs($from, $to);

        $limo = $this->limoPickupAnalytics($dateFrom, $dateTo);
        $revenueGrandTotal = $revenueTotal + $limo['revenue_total'];

        $kpi = [
            'revenue_reservations' => $revenueTotal,
            'total_revenue' => $revenueTotal,
            'time_slots_revenue' => $timeSlotsRevenue,
            'daily_ticket_revenue' => $dailyTicketRevenue,
            'limo_revenue_total' => $limo['revenue_total'],
            'revenue_grand_total' => $revenueGrandTotal,
            'reservations_total' => $reservationCount,
            'time_slots_count' => $timeSlotsCount,
            'daily_ticket_count' => $dailyTicketCount,
            'paid_reservations' => $paidCount,
            'free_reservations' => $freeCount,
            'avg_revenue_per_paid' => $paidCount > 0 ? ($revenueTotal / $paidCount) : 0.0,
            'occupied_slots_total' => $occupiedSlotsTotal,
            'avg_occupancy_slot_level' => $avgOccupancySlotLevel,
            'blocked_slot_rows' => $blockedSlotRowsCount,
            'fully_blocked_days' => $fullyBlockedDays,
            'blocked_capacity_pct' => $blockedCapacityPct,
        ];

        $reservationKinds = [
            'time_slots' => [
                'count' => $timeSlotsCount,
                'paid_count' => $paidTimeSlots->count(),
                'revenue' => $timeSlotsRevenue,
            ],
            'daily_ticket' => [
                'count' => $dailyTicketCount,
                'paid_count' => $paidDailyTickets->count(),
                'revenue' => $dailyTicketRevenue,
            ],
        ];

        $advanceBalancesTotal = null;
        $advanceBalancesByAgency = null;
        if ((bool) config('features.advance_payments')) {
            [$advanceBalancesTotal, $advanceBalancesByAgency] = $this->advanceBalancesByAgency();
        }

        return [
            'filters' => [
                'date_from' => $from->toDateString(),
                'date_to' => $to->toDateString(),
                'include_free' => $includeFree,
                'day_count' => $dayCount,
            ],
            'kpi' => $kpi,
            'reservation_kinds' => $reservationKinds,
            'trend_by_day' => $trendByDay,
            'day_parts' => $dayParts,
            'by_vehicle_type' => $byVehicleType,
            'by_agency' => $byAgency,
            'admin_free_by_agency' => $adminFreeByAgency,
            'by_country' => $byCountry,
            'paid_vs_free' => $paidVsFree,
            'blocking' => [
                'blocked_slot_rows' => $blockedSlotRowsCount,
                'fully_blocked_days' => $fullyBlockedDays,
                'blocked_capacity_pct' => $blockedCapacityPct,
                'top_days' => $blockingTopDays,
            ],
            'ops' => $ops,
            'advance_balances_total' => $advanceBalancesTotal,
            'advance_balances_by_agency' => $advanceBalancesByAgency,
            'limo' => $limo,
        ];
    }

    /**
     * Limo pickup metrics: not reservations; excludes status=incident from revenue/count.
     *
     * @return array{
     *   revenue_total: float,
     *   pickup_count: int,
     *   qr_count: int,
     *   plate_count: int,
     *   pending_fiscal_count: int,
     *   fiscalized_count: int,
     *   fiscal_failed_count: int
     * }
     */
    private function limoPickupAnalytics(string $dateFrom, string $dateTo): array
    {
        $tz = 'Europe/Podgorica';
        $occurredFrom = Carbon::parse($dateFrom, $tz)->startOfDay();
        $occurredTo = Carbon::parse($dateTo, $tz)->endOfDay();

        $rows = LimoPickupEvent::query()
            ->whereBetween('occurred_at', [$occurredFrom, $occurredTo])
            ->whereIn('status', ['pending_fiscal', 'fiscalized', 'fiscal_failed'])
            ->get(['amount_snapshot', 'source', 'status']);

        $revenue = 0.0;
        $qrCount = 0;
        $plateCount = 0;
        $pending = 0;
        $fiscalized = 0;
        $failed = 0;

        foreach ($rows as $row) {
            $revenue += (float) ($row->amount_snapshot ?? 0);
            if ($row->source === 'qr') {
                $qrCount++;
            } elseif ($row->source === 'plate') {
                $plateCount++;
            }

            match ($row->status) {
                'pending_fiscal' => $pending++,
                'fiscalized' => $fiscalized++,
                'fiscal_failed' => $failed++,
                default => null,
            };
        }

        return [
            'revenue_total' => $revenue,
            'pickup_count' => $rows->count(),
            'qr_count' => $qrCount,
            'plate_count' => $plateCount,
            'pending_fiscal_count' => $pending,
            'fiscalized_count' => $fiscalized,
            'fiscal_failed_count' => $failed,
        ];
    }

    /**
     * Returns current advance state by agency (ledger = source of truth).
     *
     * @return array{0: float, 1: array<int, array<string, mixed>>}
     */
    private function advanceBalancesByAgency(): array
    {
        $rows = DB::table('agency_advance_transactions as aat')
            ->join('users', 'users.id', '=', 'aat.agency_user_id')
            ->select([
                'aat.agency_user_id',
                'users.name as agency_name',
                'users.email as agency_email',
                DB::raw("COALESCE(SUM(CASE WHEN aat.type = 'topup' THEN aat.amount ELSE 0 END), 0) as topup_total"),
                // usage is negative; display/return as positive total
                DB::raw("COALESCE(-1 * SUM(CASE WHEN aat.type = 'usage' THEN aat.amount ELSE 0 END), 0) as usage_total"),
                DB::raw("COALESCE(SUM(CASE WHEN aat.type = 'correction' THEN aat.amount ELSE 0 END), 0) as correction_total"),
                DB::raw('COALESCE(SUM(aat.amount), 0) as balance'),
                DB::raw('MAX(aat.created_at) as last_activity'),
            ])
            ->groupBy('aat.agency_user_id', 'users.name', 'users.email')
            ->orderByDesc(DB::raw('COALESCE(SUM(aat.amount), 0)'))
            ->get();

        $total = (float) $rows->sum(fn ($r) => (float) $r->balance);

        $list = [];
        foreach ($rows as $r) {
            $list[] = [
                'agency_user_id' => (int) $r->agency_user_id,
                'agency' => (string) $r->agency_name,
                'email' => (string) $r->agency_email,
                'topup_total' => (float) $r->topup_total,
                'usage_total' => (float) $r->usage_total,
                'correction_total' => (float) $r->correction_total,
                'balance' => (float) $r->balance,
                'last_activity' => (string) $r->last_activity,
            ];
        }

        return [$total, $list];
    }

    /**
     * Paid reservations where BOTH drop-off and pick-up slots start in "free zones":
     * - start hour < 07:00 OR start hour >= 20:00 (parsed from ListOfTimeSlot::time_slot "HH:MM - ...")
     *
     * @param  Collection<int, Reservation>  $reservations
     * @param  Collection<int, ListOfTimeSlot>  $slotById
     */
    private function countPaidReservationsFullyInFreeZones(Collection $reservations, Collection $slotById): int
    {
        $cnt = 0;
        foreach ($reservations as $r) {
            if ($r->status !== 'paid' || ! $r->isTimeSlots()) {
                continue;
            }

            $drop = $slotById->get((int) $r->drop_off_time_slot_id);
            $pick = $slotById->get((int) $r->pick_up_time_slot_id);
            if (! $this->slotStartsInFreeZone($drop) || ! $this->slotStartsInFreeZone($pick)) {
                continue;
            }

            $cnt++;
        }

        return $cnt;
    }

    private function slotStartsInFreeZone(?ListOfTimeSlot $slot): bool
    {
        $h = $this->slotStartHour($slot?->time_slot ?? '');
        if ($h === null) {
            return false;
        }

        return $h < 7 || $h >= 20;
    }

    private function slotStartHour(string $timeSlot): ?int
    {
        if (! preg_match('/^\s*(\d{1,2}):(\d{2})\s*-\s*/', $timeSlot, $m)) {
            return null;
        }

        $h = (int) $m[1];
        if ($h < 0 || $h > 24) {
            return null;
        }

        return $h;
    }

    /**
     * Suspicious double payment pairs:
     * - both reservations paid
     * - same reservation_date
     * - same license_plate
     * - same drop_off_time_slot_id OR same pick_up_time_slot_id
     *   (NOTE: cross match drop=pick / pick=drop is intentionally NOT counted)
     *
     * Counts PAIRS (i < j) within each (date, plate) group.
     *
     * NOTE: independent from include_free filter (paid only).
     */
    private function countDoublePaidSameSlotPairs(Carbon $from, Carbon $to): int
    {
        $rows = Reservation::query()
            ->where('status', 'paid')
            ->where('reservation_kind', ReservationKind::TIME_SLOTS)
            ->whereDate('reservation_date', '>=', $from->toDateString())
            ->whereDate('reservation_date', '<=', $to->toDateString())
            ->get(['id', 'reservation_date', 'license_plate', 'drop_off_time_slot_id', 'pick_up_time_slot_id', 'reservation_kind']);

        $groups = $rows->groupBy(fn (Reservation $r) => $r->reservation_date->toDateString().'|'.(string) $r->license_plate);

        $pairs = 0;
        foreach ($groups as $g) {
            $list = $g->values();
            $n = $list->count();
            for ($i = 0; $i < $n; $i++) {
                /** @var Reservation $a */
                $a = $list[$i];
                $a1 = (int) $a->drop_off_time_slot_id;
                $a2 = (int) $a->pick_up_time_slot_id;
                for ($j = $i + 1; $j < $n; $j++) {
                    /** @var Reservation $b */
                    $b = $list[$j];
                    $b1 = (int) $b->drop_off_time_slot_id;
                    $b2 = (int) $b->pick_up_time_slot_id;

                    $overlap = ($a1 === $b1) || ($a2 === $b2);
                    if ($overlap) {
                        $pairs++;
                    }
                }
            }
        }

        return $pairs;
    }

    /**
     * @param  Collection<int, Reservation>  $reservations
     */
    /**
     * @param  Collection<int, Reservation>  $reservations
     * @return Collection<int, Reservation>
     */
    private function onlyTimeSlots(Collection $reservations): Collection
    {
        return $reservations->filter(fn (Reservation $r) => $r->isTimeSlots())->values();
    }

    /**
     * @param  Collection<int, Reservation>  $reservations
     * @return Collection<int, Reservation>
     */
    private function onlyDailyTickets(Collection $reservations): Collection
    {
        return $reservations->filter(fn (Reservation $r) => $r->isDailyTicket())->values();
    }

    /**
     * Benovo slot occupancy: only {@see ReservationKind::TIME_SLOTS} reservations count.
     *
     * @param  Collection<int, Reservation>  $reservations
     */
    private function occupiedSlotsFor(Collection $reservations): int
    {
        $total = 0;
        foreach ($reservations as $r) {
            if (! $r->isTimeSlots()) {
                continue;
            }
            $drop = (int) $r->drop_off_time_slot_id;
            $pick = (int) $r->pick_up_time_slot_id;
            if ($drop < 1 || $pick < 1) {
                continue;
            }
            $total += ($drop === $pick) ? 1 : 2;
        }

        return $total;
    }

    /**
     * @param  Collection<int, Reservation>  $reservations
     * @param  Collection<int, ListOfTimeSlot>  $slotById
     * @return list<array{key:string,label:string,reservations:int,occupied_slots:int,revenue:float,share_occupied:float,share_revenue:float}>
     */
    /**
     * @param  Collection<int, Reservation>  $timeSlotsReservations
     * @param  Collection<int, Reservation>  $dailyTicketReservations
     */
    private function groupByDayPart(Collection $timeSlotsReservations, Collection $slotById, Collection $dailyTicketReservations): array
    {
        $groups = [
            AdminAnalyticsDefinitions::PART_FREE_MORNING => ['reservations' => 0, 'occupied' => 0, 'revenue' => 0.0],
            AdminAnalyticsDefinitions::PART_PAID_DAY => ['reservations' => 0, 'occupied' => 0, 'revenue' => 0.0],
            AdminAnalyticsDefinitions::PART_FREE_EVENING => ['reservations' => 0, 'occupied' => 0, 'revenue' => 0.0],
        ];

        $totalOcc = $this->occupiedSlotsFor($timeSlotsReservations);
        $totalRev = (float) $timeSlotsReservations->where('status', 'paid')->sum(fn (Reservation $r) => (float) ($r->invoice_amount ?? 0));

        foreach ($timeSlotsReservations as $r) {
            $dropSlot = $slotById->get((int) $r->drop_off_time_slot_id);
            $part = AdminAnalyticsDefinitions::dayPartForSlot($dropSlot);
            $groups[$part]['reservations']++;
            $groups[$part]['occupied'] += ((int) $r->drop_off_time_slot_id === (int) $r->pick_up_time_slot_id) ? 1 : 2;
            if ($r->status === 'paid') {
                $groups[$part]['revenue'] += (float) ($r->invoice_amount ?? 0);
            }
        }

        $out = [];
        foreach ($groups as $key => $g) {
            $out[] = [
                'key' => $key,
                'label' => AdminAnalyticsDefinitions::dayPartLabel($key),
                'reservations' => (int) $g['reservations'],
                'occupied_slots' => (int) $g['occupied'],
                'revenue' => (float) $g['revenue'],
                'share_occupied' => $totalOcc > 0 ? ((int) $g['occupied'] / $totalOcc) : 0.0,
                'share_revenue' => $totalRev > 0 ? ((float) $g['revenue'] / $totalRev) : 0.0,
                'is_daily_ticket' => false,
            ];
        }

        if ($dailyTicketReservations->isNotEmpty()) {
            $dailyRev = (float) $dailyTicketReservations->where('status', 'paid')
                ->sum(fn (Reservation $r) => (float) ($r->invoice_amount ?? 0));
            $reservationRevenueTotal = $totalRev + $dailyRev;
            $out[] = [
                'key' => AdminAnalyticsDefinitions::PART_DAILY_TICKET,
                'label' => AdminAnalyticsDefinitions::dayPartLabel(AdminAnalyticsDefinitions::PART_DAILY_TICKET),
                'reservations' => $dailyTicketReservations->count(),
                'occupied_slots' => 0,
                'revenue' => $dailyRev,
                'share_occupied' => 0.0,
                'share_revenue' => $reservationRevenueTotal > 0 ? ($dailyRev / $reservationRevenueTotal) : 0.0,
                'is_daily_ticket' => true,
            ];
        }

        return $out;
    }

    /**
     * @param  Collection<int, Reservation>  $reservations
     * @return list<array{vehicle_type_id:int,name:string,reservations:int,occupied_slots:int,revenue:float,avg_revenue:float}>
     */
    private function groupByVehicleType(Collection $reservations): array
    {
        $locale = 'cg';

        $groups = $reservations->groupBy('vehicle_type_id')->map(function (Collection $rows, $vtId) use ($locale): array {
            $paidRows = $rows->where('status', 'paid');
            $rev = (float) $paidRows->sum(fn (Reservation $r) => (float) ($r->invoice_amount ?? 0));
            $cnt = $rows->count();
            $occ = $this->occupiedSlotsFor($rows);

            /** @var Reservation|null $first */
            $first = $rows->first();
            $name = $first?->vehicleType?->getTranslatedName($locale) ?: ('#'.$vtId);

            return [
                'vehicle_type_id' => (int) $vtId,
                'name' => $name,
                'reservations' => $cnt,
                'occupied_slots' => $occ,
                'revenue' => $rev,
                'avg_revenue' => $cnt > 0 ? ($rev / $cnt) : 0.0,
            ];
        });

        return $groups->sortByDesc('revenue')->values()->all();
    }

    /**
     * @param  Collection<int, Reservation>  $reservations  Main analytics dataset (respects include_free).
     * @param  Collection<int, ListOfTimeSlot>  $slotById
     * @param  Collection<int, Reservation>  $freeForAgencyWhenExcluded  Free reservations by agency (only when include_free=false).
     * @return list<array{
     *   key:string,
     *   user_id:int|null,
     *   agency:string,
     *   revenue:float,
     *   revenue_share:float,
     *   reservations_total:int,
     *   paid_reservations:int,
     *   free_reservations:int,
     *   free_pct:float,
     *   avg_revenue_per_paid:float,
     *   occupied_slots:int,
     *   avg_slots_per_reservation:float,
     *   top_vehicle_type:string,
     *   top_vehicle_type_pct:float,
     *   morning_pct:float,
     *   day_pct:float,
     *   evening_pct:float
     * }>
     */
    private function groupByAgency(
        Collection $reservations,
        Collection $slotById,
        float $revenueTotal,
        bool $includeFree,
        Collection $freeForAgencyWhenExcluded,
    ): array {
        $locale = 'cg';

        // Exclude admin-created reservations from "agency" focus. Guest handled separately.
        $agencyRows = $reservations
            ->filter(fn (Reservation $r) => $r->user_id !== null && ! (bool) $r->created_by_admin);

        $freeExtraByUserId = $freeForAgencyWhenExcluded
            ->groupBy(fn (Reservation $r) => (int) $r->user_id)
            ->map(fn (Collection $rows) => $rows->count());

        $groups = $agencyRows->groupBy(fn (Reservation $r) => (int) $r->user_id)->map(
            function (Collection $rows, int $userId) use ($slotById, $revenueTotal, $includeFree, $freeExtraByUserId, $locale): array {
                $paidRows = $rows->where('status', 'paid');
                $freeRows = $rows->where('status', 'free');

                $paidCount = $paidRows->count();
                $freeCount = $includeFree ? $freeRows->count() : (int) ($freeExtraByUserId->get($userId, 0));

                $reservationsTotal = $rows->count(); // already respects include_free
                $rev = (float) $paidRows->sum(fn (Reservation $r) => (float) ($r->invoice_amount ?? 0));

                $occupiedSlots = $this->occupiedSlotsFor($rows);

                // Most common vehicle type (within the dataset).
                $topVehicleType = '—';
                $topVehicleTypePct = 0.0;
                if ($reservationsTotal > 0) {
                    $counts = $rows->groupBy('vehicle_type_id')->map(fn (Collection $g) => $g->count())->sortDesc();
                    $topId = $counts->keys()->first();
                    $topCount = (int) ($counts->first() ?? 0);

                    /** @var Reservation|null $first */
                    $first = $rows->firstWhere('vehicle_type_id', $topId);
                    $vt = $first?->vehicleType;
                    if ($vt instanceof VehicleType) {
                        $name = $vt->getTranslatedName($locale);
                        $desc = trim((string) ($vt->getTranslatedDescription($locale) ?? ''));
                        $label = $name !== '' ? $name : ('#'.$vt->id);
                        if ($desc !== '') {
                            $label .= ' ('.$desc.')';
                        }
                        $topVehicleType = $label;
                    } else {
                        $topVehicleType = $topId !== null ? ('#'.$topId) : '—';
                    }
                    $topVehicleTypePct = $reservationsTotal > 0 ? ($topCount / $reservationsTotal) : 0.0;
                }

                // Day-part distribution by occupied slots (within the dataset).
                $occMorning = 0;
                $occDay = 0;
                $occEvening = 0;
                $dailyTicketCountAgency = $rows->where(fn (Reservation $r) => $r->isDailyTicket())->count();
                $dailyTicketRevenueAgency = (float) $rows
                    ->where(fn (Reservation $r) => $r->isDailyTicket() && $r->status === 'paid')
                    ->sum(fn (Reservation $r) => (float) ($r->invoice_amount ?? 0));

                foreach ($rows as $r) {
                    if (! $r->isTimeSlots()) {
                        continue;
                    }
                    $dropSlot = $slotById->get((int) $r->drop_off_time_slot_id);
                    $part = AdminAnalyticsDefinitions::dayPartForSlot($dropSlot);
                    $occ = ((int) $r->drop_off_time_slot_id === (int) $r->pick_up_time_slot_id) ? 1 : 2;
                    if ($part === AdminAnalyticsDefinitions::PART_FREE_MORNING) {
                        $occMorning += $occ;
                    } elseif ($part === AdminAnalyticsDefinitions::PART_FREE_EVENING) {
                        $occEvening += $occ;
                    } else {
                        $occDay += $occ;
                    }
                }
                $occTotal = max(0, $occupiedSlots);
                $morningPct = $occTotal > 0 ? ($occMorning / $occTotal) : 0.0;
                $dayPct = $occTotal > 0 ? ($occDay / $occTotal) : 0.0;
                $eveningPct = $occTotal > 0 ? ($occEvening / $occTotal) : 0.0;

                /** @var Reservation|null $firstRow */
                $firstRow = $rows->first();
                $u = $firstRow?->user;
                $agencyName = trim((string) ($u?->name ?? ''));
                if ($agencyName === '') {
                    $agencyName = trim((string) ($firstRow?->user_name ?? ''));
                }
                if ($agencyName === '') {
                    $agencyName = '#'.$userId;
                }

                $freePct = ($paidCount + $freeCount) > 0 ? ($freeCount / ($paidCount + $freeCount)) : 0.0;

                return [
                    'key' => 'user:'.$userId,
                    'user_id' => $userId,
                    'agency' => $agencyName,
                    'revenue' => $rev,
                    'revenue_share' => $revenueTotal > 0 ? ($rev / $revenueTotal) : 0.0,
                    'reservations_total' => $reservationsTotal,
                    'paid_reservations' => $paidCount,
                    'free_reservations' => $freeCount,
                    'free_pct' => $freePct,
                    'avg_revenue_per_paid' => $paidCount > 0 ? ($rev / $paidCount) : 0.0,
                    'occupied_slots' => $occupiedSlots,
                    'avg_slots_per_reservation' => $reservationsTotal > 0 ? ($occupiedSlots / $reservationsTotal) : 0.0,
                    'top_vehicle_type' => $topVehicleType,
                    'top_vehicle_type_pct' => $topVehicleTypePct,
                    'morning_pct' => $morningPct,
                    'day_pct' => $dayPct,
                    'evening_pct' => $eveningPct,
                    'daily_ticket_count' => $dailyTicketCountAgency,
                    'daily_ticket_revenue' => $dailyTicketRevenueAgency,
                ];
            }
        );

        // Optional "Guest / bez naloga" row.
        $guestRows = $reservations->filter(fn (Reservation $r) => $r->user_id === null && ! (bool) $r->created_by_admin);
        if ($guestRows->isNotEmpty()) {
            $paidRows = $guestRows->where('status', 'paid');
            $freeRows = $guestRows->where('status', 'free');
            $rev = (float) $paidRows->sum(fn (Reservation $r) => (float) ($r->invoice_amount ?? 0));
            $paidCount = $paidRows->count();
            $freeCount = $freeRows->count();
            $reservationsTotal = $guestRows->count();
            $occupiedSlots = $this->occupiedSlotsFor($guestRows);
            $freePct = ($paidCount + $freeCount) > 0 ? ($freeCount / ($paidCount + $freeCount)) : 0.0;

            $groups->put('guest', [
                'key' => 'guest',
                'user_id' => null,
                'agency' => 'Guest / bez naloga',
                'revenue' => $rev,
                'revenue_share' => $revenueTotal > 0 ? ($rev / $revenueTotal) : 0.0,
                'reservations_total' => $reservationsTotal,
                'paid_reservations' => $paidCount,
                'free_reservations' => $freeCount,
                'free_pct' => $freePct,
                'avg_revenue_per_paid' => $paidCount > 0 ? ($rev / $paidCount) : 0.0,
                'occupied_slots' => $occupiedSlots,
                'avg_slots_per_reservation' => $reservationsTotal > 0 ? ($occupiedSlots / $reservationsTotal) : 0.0,
                'top_vehicle_type' => '—',
                'top_vehicle_type_pct' => 0.0,
                'morning_pct' => 0.0,
                'day_pct' => 0.0,
                'evening_pct' => 0.0,
                'daily_ticket_count' => $guestRows->where(fn (Reservation $r) => $r->isDailyTicket())->count(),
                'daily_ticket_revenue' => (float) $guestRows
                    ->where(fn (Reservation $r) => $r->isDailyTicket() && $r->status === 'paid')
                    ->sum(fn (Reservation $r) => (float) ($r->invoice_amount ?? 0)),
            ]);
        }

        return $groups->sortByDesc('revenue')->values()->all();
    }

    /**
     * Admin-created free reservations (FZBR) grouped by agency (user_id), plus "Bez agencije" for null user_id.
     *
     * @param  Collection<int, Reservation>  $adminFreeReservations  filtered: status=free AND created_by_admin=true
     * @param  Collection<int, ListOfTimeSlot>  $slotById
     * @return list<array{
     *   key:string,
     *   user_id:int|null,
     *   agency:string,
     *   reservations:int,
     *   occupied_slots:int,
     *   avg_slots_per_reservation:float,
     *   top_vehicle_type:string,
     *   top_vehicle_type_pct:float,
     *   morning_pct:float,
     *   day_pct:float,
     *   evening_pct:float
     * }>
     */
    private function groupAdminFreeByAgency(Collection $adminFreeReservations, Collection $slotById): array
    {
        $locale = 'cg';

        $groups = $adminFreeReservations
            ->groupBy(fn (Reservation $r) => $r->user_id === null ? 'none' : (string) (int) $r->user_id)
            ->map(function (Collection $rows, string $key) use ($slotById, $locale): array {
                $reservationsTotal = $rows->count();
                $occupiedSlots = $this->occupiedSlotsFor($rows);

                $agencyName = 'Bez agencije';
                $userId = null;
                if ($key !== 'none') {
                    $userId = (int) $key;
                    /** @var Reservation|null $first */
                    $first = $rows->first();
                    $u = $first?->user;
                    $agencyName = trim((string) ($u?->name ?? ''));
                    if ($agencyName === '') {
                        $agencyName = '#'.$userId;
                    }
                }

                // Most common vehicle type.
                $topVehicleType = '—';
                $topVehicleTypePct = 0.0;
                if ($reservationsTotal > 0) {
                    $counts = $rows->groupBy('vehicle_type_id')->map(fn (Collection $g) => $g->count())->sortDesc();
                    $topId = $counts->keys()->first();
                    $topCount = (int) ($counts->first() ?? 0);

                    /** @var Reservation|null $firstByType */
                    $firstByType = $rows->firstWhere('vehicle_type_id', $topId);
                    $vt = $firstByType?->vehicleType;
                    if ($vt instanceof VehicleType) {
                        $name = $vt->getTranslatedName($locale);
                        $desc = trim((string) ($vt->getTranslatedDescription($locale) ?? ''));
                        $label = $name !== '' ? $name : ('#'.$vt->id);
                        if ($desc !== '') {
                            $label .= ' ('.$desc.')';
                        }
                        $topVehicleType = $label;
                    } else {
                        $topVehicleType = $topId !== null ? ('#'.$topId) : '—';
                    }
                    $topVehicleTypePct = $reservationsTotal > 0 ? ($topCount / $reservationsTotal) : 0.0;
                }

                // Day-part distribution by occupied slots.
                $occMorning = 0;
                $occDay = 0;
                $occEvening = 0;
                foreach ($rows as $r) {
                    if (! $r->isTimeSlots()) {
                        continue;
                    }
                    $dropSlot = $slotById->get((int) $r->drop_off_time_slot_id);
                    $part = AdminAnalyticsDefinitions::dayPartForSlot($dropSlot);
                    $occ = ((int) $r->drop_off_time_slot_id === (int) $r->pick_up_time_slot_id) ? 1 : 2;
                    if ($part === AdminAnalyticsDefinitions::PART_FREE_MORNING) {
                        $occMorning += $occ;
                    } elseif ($part === AdminAnalyticsDefinitions::PART_FREE_EVENING) {
                        $occEvening += $occ;
                    } else {
                        $occDay += $occ;
                    }
                }
                $occTotal = max(0, $occupiedSlots);

                return [
                    'key' => $key === 'none' ? 'none' : 'user:'.$userId,
                    'user_id' => $userId,
                    'agency' => $agencyName,
                    'reservations' => $reservationsTotal,
                    'occupied_slots' => $occupiedSlots,
                    'avg_slots_per_reservation' => $reservationsTotal > 0 ? ($occupiedSlots / $reservationsTotal) : 0.0,
                    'top_vehicle_type' => $topVehicleType,
                    'top_vehicle_type_pct' => $topVehicleTypePct,
                    'morning_pct' => $occTotal > 0 ? ($occMorning / $occTotal) : 0.0,
                    'day_pct' => $occTotal > 0 ? ($occDay / $occTotal) : 0.0,
                    'evening_pct' => $occTotal > 0 ? ($occEvening / $occTotal) : 0.0,
                ];
            });

        return $groups->sortByDesc('reservations')->values()->all();
    }

    /**
     * @param  Collection<int, Reservation>  $reservations
     * @return list<array{country:string,reservations:int,paid:int,free:int,revenue:float}>
     */
    private function groupByCountry(Collection $reservations): array
    {
        $groups = $reservations->groupBy('country')->map(function (Collection $rows, $cc): array {
            $paidRows = $rows->where('status', 'paid');
            $freeRows = $rows->where('status', 'free');
            $rev = (float) $paidRows->sum(fn (Reservation $r) => (float) ($r->invoice_amount ?? 0));

            return [
                'country' => (string) $cc,
                'reservations' => $rows->count(),
                'paid' => $paidRows->count(),
                'free' => $freeRows->count(),
                'revenue' => $rev,
            ];
        });

        return $groups->sortByDesc('revenue')->values()->all();
    }

    /**
     * @param  Collection<int, Reservation>  $reservations
     * @return list<array{date:string,reservations:int,paid:int,free:int,revenue:float,occupied_slots:int}>
     */
    private function trendByDay(Carbon $from, Carbon $to, Collection $reservations): array
    {
        $map = $reservations->groupBy(fn (Reservation $r) => $r->reservation_date->toDateString());

        $out = [];
        $cursor = $from->copy();
        while ($cursor->lte($to)) {
            $d = $cursor->toDateString();
            $rows = $map->get($d, collect());
            $paidRows = $rows->where('status', 'paid');
            $freeRows = $rows->where('status', 'free');
            $out[] = [
                'date' => $d,
                'reservations' => $rows->count(),
                'paid' => $paidRows->count(),
                'free' => $freeRows->count(),
                'revenue' => (float) $paidRows->sum(fn (Reservation $r) => (float) ($r->invoice_amount ?? 0)),
                'occupied_slots' => $this->occupiedSlotsFor($rows),
            ];
            $cursor->addDay();
        }

        return $out;
    }

    /**
     * Operational problems / recovery overview (no new logic; uses existing state machine tables).
     *
     * @return array<string,int>
     */
    private function operationalProblems(Carbon $from, Carbon $to): array
    {
        $fromStr = $from->toDateString();
        $toStr = $to->toDateString();

        // Payment attempts (temp_data) keyed by reservation_date in selected interval.
        $failed = TempData::query()
            ->whereDate('reservation_date', '>=', $fromStr)
            ->whereDate('reservation_date', '<=', $toStr)
            ->whereIn('status', array_values(array_unique([
                // Older SQLite test schema uses 'failed' enum value.
                'failed',
                // Newer schemas may have canceled as separate terminal state.
                TempData::STATUS_CANCELED,
            ])))
            ->count();
        $expired = TempData::query()
            ->whereDate('reservation_date', '>=', $fromStr)
            ->whereDate('reservation_date', '<=', $toStr)
            ->where('status', TempData::STATUS_EXPIRED)
            ->count();
        $lateSuccess = TempData::query()
            ->whereDate('reservation_date', '>=', $fromStr)
            ->whereDate('reservation_date', '<=', $toStr)
            ->where('status', TempData::STATUS_LATE_SUCCESS)
            ->count();

        // Delayed fiscalization: paid reservations without fiscal_jir OR unresolved post_fiscal rows.
        $paidNoFiscal = Reservation::query()
            ->whereDate('reservation_date', '>=', $fromStr)
            ->whereDate('reservation_date', '<=', $toStr)
            ->where('status', 'paid')
            ->whereNull('fiscal_jir')
            ->count();

        $unresolvedPostFiscal = PostFiscalizationData::query()
            ->unresolved()
            ->whereHas('reservation', function ($q) use ($fromStr, $toStr): void {
                $q->whereDate('reservation_date', '>=', $fromStr)->whereDate('reservation_date', '<=', $toStr);
            })
            ->count();

        // Successful post-fiscal recovery: resolved_at within interval (best effort).
        $resolvedPostFiscal = PostFiscalizationData::query()
            ->whereNotNull('resolved_at')
            ->whereBetween(DB::raw('DATE(resolved_at)'), [$fromStr, $toStr])
            ->count();

        return [
            'failed_payments' => $failed,
            'expired_payments' => $expired,
            'late_success' => $lateSuccess,
            'paid_without_fiscal_jir' => $paidNoFiscal,
            'unresolved_post_fiscal' => $unresolvedPostFiscal,
            'resolved_post_fiscal' => $resolvedPostFiscal,
        ];
    }
}

