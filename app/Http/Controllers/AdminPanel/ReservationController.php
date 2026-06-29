<?php

namespace App\Http\Controllers\AdminPanel;

use App\Http\Controllers\Controller;
use App\Http\Requests\AdminPanel\AdminReservationSearchRequest;
use App\Http\Requests\AdminPanel\AdminReservationUpdateRequest;
use App\Models\DailyParkingData;
use App\Models\ListOfTimeSlot;
use App\Models\Reservation;
use App\Models\User;
use App\Models\VehicleType;
use App\Services\AdminPanel\Reservation\AdminDailyTicketUpdateService;
use App\Services\AdminPanel\Reservation\AdminReservationDateBounds;
use App\Services\AdminPanel\Reservation\AdminReservationEditPolicy;
use App\Services\AdminPanel\Reservation\AdminReservationUpdateNotification;
use App\Services\AdminPanel\Reservation\AdminReservationSearchService;
use App\Services\AdminPanel\Reservation\AdminReservationSlotRules;
use App\Services\AdminPanel\Reservation\AdminReservationUpdateService;
use App\Services\Pdf\FreeReservationPdfGenerator;
use App\Services\Pdf\PaidInvoicePdfGenerator;
use App\Services\Reservation\PanelReservationListService;
use App\Support\BankartBillingCountry;
use App\Support\ReservationPdfFilename;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\StreamedResponse;

class ReservationController extends Controller
{
    public function index(
        Request $request,
        AdminReservationSearchService $searchService,
        AdminReservationDateBounds $dateBounds,
    ): View {
        AdminReservationSearchRequest::applyInputNormalization($request);

        $filters = [];
        $results = null;
        $hasCriteria = $this->requestHasSearchCriteria($request);

        if ($hasCriteria) {
            $validated = $request->validate(AdminReservationSearchRequest::rulesForSearch());
            if ($request->boolean('use_interval') && ($request->filled('date_from') && $request->filled('date_to'))
                && $request->input('date_from') >= $request->input('date_to')) {
                throw ValidationException::withMessages([
                    'date_to' => ['Krajnji datum mora biti poslije početnog.'],
                ]);
            }
            $filters = $this->buildFiltersFromValidatedArray($validated, $request, $searchService);
            $results = $searchService->search($filters);
        }

        return view('admin-panel.reservations.index', [
            'navActive' => 'reservations',
            'pageTitle' => 'Rezervacije',
            'results' => $results,
            'filters' => array_merge($this->defaultFilters(), $request->all()),
            'hasCriteria' => $hasCriteria,
            'dateMin' => $dateBounds->searchMinDate()->toDateString(),
            'dateMax' => $dateBounds->searchMaxDate()->toDateString(),
            'countries' => (array) config('countries', []),
            'vehicleTypes' => VehicleType::query()->with('translations')->orderBy('price')->orderBy('id')->get(),
            'agencies' => User::query()->orderBy('name')->orderBy('email')->get(['id', 'name', 'email', 'country']),
        ]);
    }

    public function edit(
        Request $request,
        Reservation $reservation,
        AdminReservationDateBounds $dateBounds,
        AdminReservationSlotRules $slotRules,
    ): View {
        $reservation->load(['pickUpTimeSlot', 'dropOffTimeSlot', 'vehicleType']);

        try {
            AdminReservationEditPolicy::assertEditable($reservation);
        } catch (\RuntimeException) {
            abort(403);
        }

        if ($reservation->isDailyTicket()) {
            $returnQuery = (string) $request->query('rq', '');
            $boundsMin = $dateBounds->editMinDate()->toDateString();
            $boundsMax = $dateBounds->editMaxDate()->toDateString();
            $currentVt = $reservation->vehicleType;
            $currentPrice = $currentVt !== null ? (float) $currentVt->price : 0.0;
            $vehicleTypesAllowed = VehicleType::query()
                ->where('price', '<=', $currentPrice)
                ->with('translations')
                ->orderBy('price')
                ->orderBy('id')
                ->get();

            return view('admin-panel.reservations.edit-daily-ticket', [
                'navActive' => 'reservations',
                'pageTitle' => 'Rezervacija #'.$reservation->id.' — Dnevna naknada',
                'reservation' => $reservation,
                'returnQuery' => $returnQuery,
                'dateMin' => $boundsMin,
                'dateMax' => $boundsMax,
                'countries' => BankartBillingCountry::selectableCountries(),
                'vehicleTypesAllowed' => $vehicleTypesAllowed,
                'cancelUrl' => route('panel_admin.reservations', [], false)
                    .($returnQuery !== '' ? '?'.$returnQuery : ''),
            ]);
        }

        $formDate = (string) $request->query('form_date', $reservation->reservation_date->toDateString());
        $boundsMin = $dateBounds->editMinDate()->toDateString();
        $boundsMax = $dateBounds->editMaxDate()->toDateString();
        if ($formDate < $boundsMin || $formDate > $boundsMax) {
            $formDate = $reservation->reservation_date->toDateString();
        }

        $slots = ListOfTimeSlot::query()->orderBy('id')->get();
        $dailyBySlotId = DailyParkingData::query()
            ->whereDate('date', $formDate)
            ->get()
            ->keyBy('time_slot_id');

        $slotOptions = [];
        foreach ($slots as $slot) {
            $daily = $dailyBySlotId->get($slot->id);
            $selectable = $daily !== null && $slotRules->slotSelectableOnDaily($reservation, $formDate, $slot->id, $daily);
            $slotOptions[] = [
                'slot' => $slot,
                'daily' => $daily,
                'selectable' => $selectable,
            ];
        }

        $currentVt = $reservation->vehicleType;
        $currentPrice = $currentVt !== null ? (float) $currentVt->price : 0.0;
        $vehicleTypesAllowed = VehicleType::query()
            ->where('price', '<=', $currentPrice)
            ->with('translations')
            ->orderBy('price')
            ->orderBy('id')
            ->get();

        return view('admin-panel.reservations.edit', [
            'navActive' => 'reservations',
            'pageTitle' => 'Uredi rezervaciju #'.$reservation->id,
            'reservation' => $reservation,
            'formDate' => $formDate,
            'dateMin' => $boundsMin,
            'dateMax' => $boundsMax,
            'slotOptions' => $slotOptions,
            'countries' => BankartBillingCountry::selectableCountries(),
            'vehicleTypesAllowed' => $vehicleTypesAllowed,
            'returnQuery' => (string) $request->query('rq', ''),
            'pickUpOnly' => AdminReservationEditPolicy::isPickUpOnlyMode($reservation),
        ]);
    }

    public function update(
        AdminReservationUpdateRequest $request,
        Reservation $reservation,
        AdminReservationUpdateService $updateService,
        AdminDailyTicketUpdateService $dailyUpdateService,
    ): RedirectResponse {
        try {
            AdminReservationEditPolicy::assertEditable($reservation);
        } catch (\RuntimeException $e) {
            return redirect()
                ->route('panel_admin.reservations.edit', ['reservation' => $reservation])
                ->with('error', $e->getMessage());
        }

        $data = $request->validated();
        $rq = $data['return_query'] ?? '';
        unset($data['return_query']);

        try {
            $adminId = auth('panel_admin')->id();
            if ($reservation->isDailyTicket()) {
                $changedFields = $dailyUpdateService->apply($reservation, $data);
            } else {
                $data = AdminReservationEditPolicy::enforcePickUpOnlyFields($reservation, $data);
                $changedFields = $updateService->apply($reservation, $data);
            }

            AdminReservationUpdateNotification::dispatchAfterSuccessfulUpdate(
                $reservation->fresh() ?? $reservation,
                is_int($adminId) ? $adminId : null,
                $changedFields,
            );
        } catch (\RuntimeException $e) {
            return redirect()
                ->route('panel_admin.reservations.edit', array_filter([
                    'reservation' => $reservation->id,
                    'rq' => $rq !== '' ? $rq : null,
                    'form_date' => $data['reservation_date'] ?? null,
                ]))
                ->withInput()
                ->with('error', $e->getMessage());
        }

        return $this->redirectAfterUpdate($rq, 'Izmjena je sačuvana; dokument je u redu za slanje.');
    }

    private function redirectAfterUpdate(string $rq, string $statusMessage): RedirectResponse
    {
        $redirectUrl = route('panel_admin.reservations', [], false);
        if ($rq !== '') {
            $redirectUrl .= '?'.$rq;
        }

        return redirect()->to($redirectUrl)->with('status', $statusMessage);
    }

    public function pdf(Reservation $reservation): StreamedResponse|\Illuminate\Http\Response
    {
        $reservation->load(['pickUpTimeSlot', 'dropOffTimeSlot', 'vehicleType']);

        try {
            $binary = $this->pdfBinary($reservation);
        } catch (\Throwable $e) {
            report($e);
            abort(503);
        }
        abort_if($binary === '', 404);

        return response()->streamDownload(
            static function () use ($binary): void {
                echo $binary;
            },
            ReservationPdfFilename::forReservation($reservation),
            ['Content-Type' => 'application/pdf'],
        );
    }

    private function pdfBinary(Reservation $reservation): string
    {
        if ($reservation->status === 'free') {
            return app(FreeReservationPdfGenerator::class)->renderBinary($reservation);
        }
        if ($reservation->status === 'paid') {
            $isFiscal = $reservation->fiscal_jir !== null;

            return app(PaidInvoicePdfGenerator::class)->renderBinary($reservation, $isFiscal);
        }

        return '';
    }

    private function requestHasSearchCriteria(Request $request): bool
    {
        $interval = $request->boolean('use_interval');

        return $request->filled('merchant_transaction_id')
            || $request->filled('date_single')
            || ($interval && $request->filled('date_from') && $request->filled('date_to'))
            || $request->filled('name')
            || $request->filled('email')
            || $request->filled('vehicle_type_id')
            || $request->filled('license_plate')
            || $request->filled('country')
            || $request->filled('status')
            || $request->filled('agency_user_id');
    }

    /**
     * @param  array<string, mixed>  $v
     * @return array<string, mixed>
     */
    private function buildFiltersFromValidatedArray(
        array $v,
        Request $request,
        AdminReservationSearchService $searchService,
    ): array {
        $filters = [];

        if (! empty($v['merchant_transaction_id'])) {
            $filters['merchant_transaction_id'] = trim((string) $v['merchant_transaction_id']);
        }
        if (! empty($v['agency_user_id'])) {
            $filters['user_id'] = (int) $v['agency_user_id'];
        }
        if ($request->boolean('use_interval')) {
            if (! empty($v['date_from']) && ! empty($v['date_to'])) {
                $filters['date_interval'] = true;
                $filters['date_from'] = $v['date_from'];
                $filters['date_to'] = $v['date_to'];
            }
        } elseif (! empty($v['date_single'])) {
            $filters['date_single'] = $v['date_single'];
        }

        if (! empty($v['vehicle_type_id'])) {
            $filters['vehicle_type_id'] = (int) $v['vehicle_type_id'];
        }
        if (! empty($v['license_plate'])) {
            $filters['license_plate'] = $v['license_plate'];
        }
        if (! empty($v['country'])) {
            $filters['country'] = $v['country'];
        }
        if (! empty($v['status'])) {
            $filters['status'] = $v['status'];
        }
        if (! empty($v['reservation_kind'])) {
            $filters['reservation_kind'] = (string) $v['reservation_kind'];
        }

        $heur = $searchService->buildHeuristicPatterns($v['name'] ?? null, $v['email'] ?? null);
        $filters = array_merge($filters, $heur);

        return $filters;
    }

    /**
     * @return array<string, mixed>
     */
    private function defaultFilters(): array
    {
        return [
            'merchant_transaction_id' => '',
            'use_interval' => false,
            'date_single' => '',
            'date_from' => '',
            'date_to' => '',
            'name' => '',
            'email' => '',
            'vehicle_type_id' => '',
            'license_plate' => '',
            'country' => '',
            'status' => '',
            'agency_user_id' => '',
            'reservation_kind' => '',
        ];
    }
}
