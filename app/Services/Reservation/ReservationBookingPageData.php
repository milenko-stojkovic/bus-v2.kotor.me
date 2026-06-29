<?php

namespace App\Services\Reservation;

use App\Models\DailyParkingData;
use App\Models\ListOfTimeSlot;
use App\Models\User;
use App\Models\Vehicle;
use App\Models\VehicleType;
use App\Support\BankartBillingCountry;
use App\Support\ReservationKind;
use Carbon\Carbon;
use Illuminate\Http\Request;

/**
 * Shared slot/date/pricing data for guest reserve page and authenticated panel booking.
 * Mirrors rules in GuestReservationController (single source for calendar + slot availability).
 */
final class ReservationBookingPageData
{
    public function __construct(
        private readonly ReservationVehicleEligibilityService $vehicleEligibility,
        private readonly DuplicateReservationAttemptService $duplicateReservation,
    ) {}

    /**
     * Small helper for dynamic slot UIs (e.g. agency FZBR) that want the same
     * availability rules as the booking pages, but via JSON.
     *
     * @return array{
     *   selected_date: ?string,
     *   arrival_slots: array<int, array<string, mixed>>,
     *   departure_slots: array<int, array<string, mixed>>,
     *   departure_disabled: bool
     * }
     */
    public function slotPayload(
        string $dateStr,
        ?int $arrivalId,
        ?int $departureId,
        string $locale,
        int $requiredVehicles = 1,
        bool $strictFreeWindowCapacity = false,
    ): array {
        $selectedDate = $this->parseAllowedDate($dateStr);
        $slotPayload = $this->buildSlotPayload(
            $selectedDate,
            $arrivalId,
            $departureId,
            $locale,
            $requiredVehicles,
            $strictFreeWindowCapacity,
        );

        unset($slotPayload['effective_departure_id'], $slotPayload['selected_arrival_slot'], $slotPayload['selected_departure_slot']);

        return array_merge($slotPayload, [
            'selected_date' => $selectedDate?->toDateString(),
            // Echo the same value used for capacity checks (callers may clamp before passing).
            'required_vehicles' => max(1, (int) $requiredVehicles),
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    public function forGuest(Request $request): array
    {
        $locale = app()->getLocale();

        $dateStr = (string) $request->query('reservation_date', '');
        $selectedDate = $this->parseAllowedDate($dateStr);

        $reservationKind = (string) $request->query('reservation_kind', ReservationKind::TIME_SLOTS);
        if (! in_array($reservationKind, ReservationKind::ALL, true)) {
            $reservationKind = ReservationKind::TIME_SLOTS;
        }
        $isDailyTicketBooking = $reservationKind === ReservationKind::DAILY_TICKET;

        $arrivalId = $isDailyTicketBooking ? null : $this->asIntOrNull($request->query('drop_off_time_slot_id'));
        $departureId = $isDailyTicketBooking ? null : $this->asIntOrNull($request->query('pick_up_time_slot_id'));

        $slotPayload = $this->buildSlotPayload($selectedDate, $arrivalId, $departureId, $locale);
        $departureId = $slotPayload['effective_departure_id'];

        $vehicleTypes = $this->vehicleEligibility->filterVehicleTypesForKind(
            VehicleType::query()
                ->with('translations')
                ->orderBy('id')
                ->get(),
            $reservationKind,
        );

        $countries = BankartBillingCountry::selectableCountries($locale);

        $vehicleTypeId = $this->asIntOrNull($request->query('vehicle_type_id'));
        $selectedVehicleType = $vehicleTypeId ? $vehicleTypes->firstWhere('id', $vehicleTypeId) : null;
        if ($selectedVehicleType === null && $vehicleTypeId !== null) {
            $candidate = VehicleType::query()->with('translations')->find($vehicleTypeId);
            if ($candidate !== null
                && $this->vehicleEligibility->isVehicleTypeAllowedForKind((int) $candidate->id, $reservationKind)) {
                $selectedVehicleType = $candidate;
                $vehicleTypes = $vehicleTypes->push($candidate)->unique('id')->sortBy('id')->values();
            }
        }

        $isFreeReservation = false;
        $paidAmount = null;
        if ($isDailyTicketBooking && $selectedDate && $selectedVehicleType) {
            if (is_numeric((string) $selectedVehicleType->price)) {
                $paidAmount = number_format((float) $selectedVehicleType->price, 2, '.', '');
            }
        } elseif ($slotPayload['selected_arrival_slot'] && $slotPayload['selected_departure_slot']) {
            $isFreeReservation = FreeReservationRules::isFreeReservation(
                $slotPayload['selected_arrival_slot'],
                $slotPayload['selected_departure_slot']
            );
            if (! $isFreeReservation && $selectedVehicleType && is_numeric((string) $selectedVehicleType->price)) {
                $paidAmount = number_format((float) $selectedVehicleType->price, 2, '.', '');
            }
        }

        unset($slotPayload['effective_departure_id']);

        return array_merge($slotPayload, [
            'selected_date' => $selectedDate?->toDateString(),
            'arrival_id' => $arrivalId,
            'departure_id' => $departureId,
            'reservation_kind' => $reservationKind,
            'is_daily_ticket_booking' => $isDailyTicketBooking,
            'vehicle_types' => $vehicleTypes,
            'countries' => $countries,
            'paid_amount' => $paidAmount,
            'is_free_reservation' => $isFreeReservation,
            'booking_mode' => 'guest',
            'vehicles' => collect(),
            'vehicle_id' => null,
        ]);
    }

    /**
     * Admin panel — uvek cg; isti booking engine kao gost (kalendar 0–90 dana, ista pravila slotova).
     * GET query + old() (nakon redirecta) za repopulaciju polja.
     *
     * @return array<string, mixed>
     */
    public function forAdminPanel(Request $request): array
    {
        $locale = 'cg';

        $dateStr = (string) old('reservation_date', $request->query('reservation_date', ''));
        $selectedDate = $this->parseAllowedDate($dateStr);

        $arrivalId = $this->asIntOrNull(old('drop_off_time_slot_id', $request->query('drop_off_time_slot_id')));
        $departureId = $this->asIntOrNull(old('pick_up_time_slot_id', $request->query('pick_up_time_slot_id')));

        $slotPayload = $this->buildSlotPayload($selectedDate, $arrivalId, $departureId, $locale);
        $departureId = $slotPayload['effective_departure_id'];

        $vehicleTypes = VehicleType::query()
            ->with('translations')
            ->orderBy('id')
            ->get();

        $countries = BankartBillingCountry::selectableCountries($locale);

        unset($slotPayload['effective_departure_id']);

        return array_merge($slotPayload, [
            'selected_date' => $selectedDate?->toDateString(),
            'arrival_id' => $arrivalId,
            'departure_id' => $departureId,
            'vehicle_types' => $vehicleTypes,
            'countries' => $countries,
            'booking_mode' => 'admin_free',
            'vehicles' => collect(),
            'vehicle_id' => null,
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    public function forAuthenticated(Request $request, User $user): array
    {
        $locale = app()->getLocale();

        $dateStr = (string) $request->query('reservation_date', '');
        $selectedDate = $this->parseAllowedDate($dateStr);

        $reservationKind = (string) $request->query('reservation_kind', ReservationKind::TIME_SLOTS);
        if (! in_array($reservationKind, ReservationKind::ALL, true)) {
            $reservationKind = ReservationKind::TIME_SLOTS;
        }
        $isDailyTicketBooking = $reservationKind === ReservationKind::DAILY_TICKET;

        $arrivalId = $isDailyTicketBooking ? null : $this->asIntOrNull($request->query('drop_off_time_slot_id'));
        $departureId = $isDailyTicketBooking ? null : $this->asIntOrNull($request->query('pick_up_time_slot_id'));

        $slotPayload = $this->buildSlotPayload($selectedDate, $arrivalId, $departureId, $locale);
        $departureId = $slotPayload['effective_departure_id'];

        $eligibleVehicles = $this->vehicleEligibility->filterVehiclesForKind(
            $user->vehicles()
                ->where('status', \App\Models\Vehicle::STATUS_ACTIVE)
                ->with(['vehicleType.translations'])
                ->orderBy('license_plate')
                ->get(),
            $reservationKind,
        );

        $terminiVehiclesHiddenCount = 0;
        if (
            ! $isDailyTicketBooking
            && $selectedDate !== null
            && $arrivalId !== null
            && $departureId !== null
        ) {
            $filterResult = $this->duplicateReservation->filterVehiclesAllowedForTerminiSlots(
                $eligibleVehicles,
                $selectedDate->toDateString(),
                $arrivalId,
                $departureId,
            );
            $vehicles = $filterResult['vehicles'];
            $terminiVehiclesHiddenCount = $filterResult['hidden_count'];
        } else {
            $vehicles = $eligibleVehicles;
        }

        $vehicleId = $this->asIntOrNull($request->query('vehicle_id'));
        $selectedVehicle = null;
        if ($vehicleId !== null) {
            $selectedVehicle = $vehicles->firstWhere('id', $vehicleId);
            if (! $selectedVehicle) {
                $candidate = Vehicle::query()
                    ->where('user_id', $user->id)
                    ->where('status', \App\Models\Vehicle::STATUS_ACTIVE)
                    ->with(['vehicleType.translations'])
                    ->find($vehicleId);
                if ($candidate !== null
                    && $this->vehicleEligibility->isVehicleTypeAllowedForKind((int) $candidate->vehicle_type_id, $reservationKind)
                    && (
                        $isDailyTicketBooking
                        || $selectedDate === null
                        || $arrivalId === null
                        || $departureId === null
                        || ! $this->duplicateReservation->existsConflict(
                            $selectedDate->toDateString(),
                            (string) $candidate->license_plate,
                            $arrivalId,
                            $departureId,
                        )
                    )) {
                    $selectedVehicle = $candidate;
                    $vehicles = $vehicles->push($selectedVehicle)->unique('id')->sortBy('license_plate')->values();
                }
            }
        }

        $isFreeReservation = false;
        $paidAmount = null;
        if ($isDailyTicketBooking && $selectedDate && $selectedVehicle) {
            $vt = $selectedVehicle->vehicleType;
            if ($vt && is_numeric((string) $vt->price)) {
                $paidAmount = number_format((float) $vt->price, 2, '.', '');
            }
        } elseif ($slotPayload['selected_arrival_slot'] && $slotPayload['selected_departure_slot']) {
            $isFreeReservation = FreeReservationRules::isFreeReservation(
                $slotPayload['selected_arrival_slot'],
                $slotPayload['selected_departure_slot']
            );
            $vt = $selectedVehicle?->vehicleType;
            if (! $isFreeReservation && $vt && is_numeric((string) $vt->price)) {
                $paidAmount = number_format((float) $vt->price, 2, '.', '');
            }
        }

        unset($slotPayload['effective_departure_id']);

        return array_merge($slotPayload, [
            'selected_date' => $selectedDate?->toDateString(),
            'arrival_id' => $arrivalId,
            'departure_id' => $departureId,
            'reservation_kind' => $reservationKind,
            'is_daily_ticket_booking' => $isDailyTicketBooking,
            'vehicle_types' => collect(),
            'countries' => [],
            'paid_amount' => $paidAmount,
            'is_free_reservation' => $isFreeReservation,
            'booking_mode' => 'auth',
            'vehicles' => $vehicles,
            'vehicle_id' => $selectedVehicle?->id,
            'termini_vehicles_hidden_count' => $terminiVehiclesHiddenCount,
        ]);
    }

    /**
     * @return array{
     *   arrival_slots: array<int, array<string, mixed>>,
     *   departure_slots: array<int, array<string, mixed>>,
     *   departure_disabled: bool,
     *   selected_arrival_slot: ?ListOfTimeSlot,
     *   selected_departure_slot: ?ListOfTimeSlot
     * }
     */
    private function buildSlotPayload(
        ?Carbon $selectedDate,
        ?int $arrivalId,
        ?int $departureId,
        string $locale,
        int $requiredVehicles = 1,
        bool $strictFreeWindowCapacity = false,
    ): array {
        $required = max(1, (int) $requiredVehicles);

        $allSlots = ListOfTimeSlot::query()->orderBy('id')->get();
        $dailyBySlotId = collect();
        if ($selectedDate) {
            $dailyBySlotId = DailyParkingData::query()
                ->whereDate('date', $selectedDate->toDateString())
                ->get()
                ->keyBy('time_slot_id');
        }

        $arrivalSlots = [];
        if ($selectedDate) {
            $arrivalSlots = $allSlots->map(function (ListOfTimeSlot $slot) use ($selectedDate, $dailyBySlotId, $locale, $required, $strictFreeWindowCapacity) {
                $daily = $dailyBySlotId->get($slot->id);
                $spotsLeft = $daily ? $daily->availableCapacity() : 0;
                $isBlocked = (bool) ($daily?->is_blocked ?? false);
                $isFree = FreeReservationRules::isFreeWindowSlot($slot);
                $isPastForToday = $this->isPastSlotForToday($slot, $selectedDate);
                $capacityConstrained = (! $isFree) || $strictFreeWindowCapacity;
                $isFull = $capacityConstrained && $spotsLeft < $required;
                $disabled = $isPastForToday || $isBlocked || $isFull;

                return [
                    'id' => $slot->id,
                    'label' => $this->formatSlotLabel($slot->time_slot, $spotsLeft, $locale),
                    'spots_left' => $spotsLeft,
                    'is_free' => $isFree,
                    'is_blocked' => $isBlocked,
                    'disabled' => $disabled,
                ];
            })->all();
        }

        $selectedArrivalSlot = $arrivalId ? $allSlots->firstWhere('id', $arrivalId) : null;
        $effectiveDepartureId = $departureId;
        $selectedDepartureSlot = $effectiveDepartureId ? $allSlots->firstWhere('id', $effectiveDepartureId) : null;

        $departureSlots = [];
        $departureDisabled = $selectedDate === null || $selectedArrivalSlot === null;
        if (! $departureDisabled && $selectedArrivalSlot) {
            $arrivalStart = $selectedArrivalSlot->getStartTimeForDate($selectedDate);

            $departureSlots = $allSlots->map(function (ListOfTimeSlot $slot) use ($selectedDate, $dailyBySlotId, $arrivalStart, $locale, $required, $strictFreeWindowCapacity) {
                $daily = $dailyBySlotId->get($slot->id);
                $spotsLeft = $daily ? $daily->availableCapacity() : 0;
                $isBlocked = (bool) ($daily?->is_blocked ?? false);
                $isFree = FreeReservationRules::isFreeWindowSlot($slot);
                $isPastForToday = $this->isPastSlotForToday($slot, $selectedDate);
                $capacityConstrained = (! $isFree) || $strictFreeWindowCapacity;
                $isFull = $capacityConstrained && $spotsLeft < $required;

                $slotStart = $slot->getStartTimeForDate($selectedDate);
                $beforeArrival = $arrivalStart && $slotStart ? $slotStart->lt($arrivalStart) : false;

                $disabled = $isPastForToday || $isBlocked || $isFull || $beforeArrival;

                return [
                    'id' => $slot->id,
                    'label' => $this->formatSlotLabel($slot->time_slot, $spotsLeft, $locale),
                    'spots_left' => $spotsLeft,
                    'is_free' => $isFree,
                    'is_blocked' => $isBlocked,
                    'disabled' => $disabled,
                    'before_arrival' => $beforeArrival,
                ];
            })->all();
        }

        if ($effectiveDepartureId && ! $departureDisabled) {
            $validDepartureIds = collect($departureSlots)->filter(fn ($s) => ! $s['disabled'])->pluck('id')->all();
            if (! in_array($effectiveDepartureId, $validDepartureIds, true)) {
                $effectiveDepartureId = null;
                $selectedDepartureSlot = null;
            }
        }

        return [
            'arrival_slots' => $arrivalSlots,
            'departure_slots' => $departureSlots,
            'departure_disabled' => $departureDisabled,
            'selected_arrival_slot' => $selectedArrivalSlot,
            'selected_departure_slot' => $selectedDepartureSlot,
            'effective_departure_id' => $effectiveDepartureId,
        ];
    }

    private function parseAllowedDate(string $dateStr): ?Carbon
    {
        $dateStr = trim($dateStr);
        if ($dateStr === '') {
            return null;
        }

        try {
            $d = Carbon::parse($dateStr)->startOfDay();
        } catch (\Throwable) {
            return null;
        }

        $today = now()->startOfDay();
        if ($d->lt($today)) {
            return null;
        }
        if ($d->gt($today->copy()->addDays(90))) {
            return null;
        }

        return $d;
    }

    private function asIntOrNull(mixed $v): ?int
    {
        if ($v === null) {
            return null;
        }
        if (is_int($v)) {
            return $v;
        }
        if (! is_string($v)) {
            return null;
        }
        $v = trim($v);
        if ($v === '' || ! preg_match('/^\d+$/', $v)) {
            return null;
        }

        return (int) $v;
    }

    private function isPastSlotForToday(ListOfTimeSlot $slot, Carbon $selectedDate): bool
    {
        $today = now()->startOfDay();
        if (! $selectedDate->isSameDay($today)) {
            return false;
        }

        $end = $slot->getEndTimeForDate($selectedDate);
        if (! $end) {
            return false;
        }

        return now()->gte($end);
    }

    private function formatSlotLabel(string $timeSlot, int $spotsLeft, string $locale): string
    {
        if ($locale === 'cg') {
            return sprintf('%s (%d slobodna mjesta)', $timeSlot, $spotsLeft);
        }

        return sprintf('%s (%d spots left)', $timeSlot, $spotsLeft);
    }

}
