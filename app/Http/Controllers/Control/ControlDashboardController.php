<?php

namespace App\Http\Controllers\Control;

use App\Http\Controllers\Controller;
use App\Http\Requests\Control\ControlReservationSearchRequest;
use App\Models\Reservation;
use App\Models\VehicleType;
use App\Services\Control\ControlArrivalSlots;
use App\Services\Operations\DailyCapacityChartService;
use App\Support\MontenegroLicensePlate;
use App\Support\ReservationKind;
use Illuminate\Http\RedirectResponse;
use Illuminate\View\View;

class ControlDashboardController extends Controller
{
    public function index(
        ControlReservationSearchRequest $request,
        ControlArrivalSlots $arrivals,
        DailyCapacityChartService $capacityCharts
    ): View|RedirectResponse
    {
        if ($request->submittedSearch() && ! $request->hasSearchCriteria()) {
            return redirect()
                ->to(route('control.dashboard', [], false))
                ->withErrors([
                    'search' => 'Unesite bar jedan kriterijum.',
                ])
                ->withInput($request->except('search'));
        }

        $arrivalGroups = $arrivals->groupsWithinNextHours(ControlArrivalSlots::PREVIEW_HOURS_BEFORE_START);

        $searchResults = null;
        if ($request->hasSearchCriteria()) {
            $searchResults = $this->searchReservations($request);
        }

        $vehicleTypes = VehicleType::query()
            ->with('translations')
            ->orderBy('id')
            ->get();

        return view('control.dashboard', [
            'arrivalGroups' => $arrivalGroups,
            'searchResults' => $searchResults,
            'vehicleTypes' => $vehicleTypes,
            'searchInput' => $request->only(['date', 'name', 'email', 'vehicle_type_id', 'license_plate', 'status']),
            'capacityCharts' => $capacityCharts->todayAndTomorrow(),
        ]);
    }

    /**
     * @return \Illuminate\Database\Eloquent\Collection<int, Reservation>
     */
    private function searchReservations(ControlReservationSearchRequest $request)
    {
        $q = Reservation::query()
            ->with(['pickUpTimeSlot', 'dropOffTimeSlot', 'vehicleType.translations'])
            ->where(function ($query): void {
                $query->where('reservation_kind', ReservationKind::TIME_SLOTS)
                    ->orWhereNull('reservation_kind');
            });

        if ($request->filled('date')) {
            // Explicit date: strict match only (past/present/future as selected).
            $q->whereDate('reservation_date', $request->date('date'));
        }
        // No date: search all reservation dates (no implicit today/future cutoff).

        if ($request->filled('name')) {
            $term = '%'.str_replace(['%', '_'], ['\\%', '\\_'], $request->input('name')).'%';
            $q->where('user_name', 'like', $term);
        }

        if ($request->filled('email')) {
            $term = '%'.str_replace(['%', '_'], ['\\%', '\\_'], $request->input('email')).'%';
            $q->where('email', 'like', $term);
        }

        if ($request->filled('vehicle_type_id')) {
            $q->where('vehicle_type_id', (int) $request->input('vehicle_type_id'));
        }

        if ($request->filled('license_plate')) {
            $plate = MontenegroLicensePlate::normalizeAscii((string) $request->input('license_plate'));
            if ($plate !== '') {
                $q->whereRaw("REPLACE(UPPER(license_plate), ' ', '') LIKE ?", ['%'.$plate.'%']);
            }
        }

        if ($request->filled('status')) {
            $q->where('status', (string) $request->input('status'));
        }

        if ($request->filled('date')) {
            return $q
                ->orderBy('reservation_date')
                ->orderBy('id')
                ->get();
        }

        return $q
            ->orderByDesc('reservation_date')
            ->orderByDesc('id')
            ->get();
    }
}
