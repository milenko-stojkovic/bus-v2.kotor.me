<?php

namespace App\Http\Controllers\AdminPanel;

use App\Http\Controllers\Controller;
use App\Http\Requests\AdminPanel\AdminPanelCapacityUpdateRequest;
use App\Http\Requests\AdminPanel\AdminPanelLimoIncidentEmailStoreRequest;
use App\Http\Requests\AdminPanel\AdminPanelPriceChangeRequest;
use App\Http\Requests\AdminPanel\AdminPanelReportEmailStoreRequest;
use App\Models\Admin;
use App\Models\PriceChangeSchedule;
use App\Models\ReportEmail;
use App\Models\SystemConfig;
use App\Models\VehicleType;
use App\Services\Pricing\PriceChangeAdmin;
use App\Services\Pricing\VehicleTariff;
use Carbon\Carbon;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class SettingsController extends Controller
{
    public function index(Request $request, VehicleTariff $tariff): View
    {
        $capacity = (int) (SystemConfig::getValue('available_parking_slots') ?? 0);
        if ($capacity < 1) {
            // Conservative fallback so UI is usable even if config missing.
            $capacity = 9;
        }

        $effectiveFrom = Carbon::now()->startOfDay()->addDays(91);
        $tariff->applyDue();

        $pendingPriceChange = PriceChangeSchedule::query()
            ->where('status', PriceChangeSchedule::STATUS_SCHEDULED)
            ->with(['lines.vehicleType.translations'])
            ->first();

        return view('admin-panel.settings.index', [
            'navActive' => 'settings',
            'pageTitle' => 'Podešavanja',
            'capacity' => $capacity,
            'capacityEffectiveFrom' => $effectiveFrom,
            'reportEmails' => ReportEmail::query()->forReport()->orderBy('email')->get(),
            'limoIncidentEmails' => ReportEmail::query()->forLimoIncidents()->orderBy('email')->get(),
            'priceChangeVehicleTypes' => VehicleType::query()->with('translations')->orderBy('id')->get(),
            'pendingPriceChange' => $pendingPriceChange,
            'priceChangeEditing' => $request->query('price_change') === 'edit' && $pendingPriceChange !== null,
            'priceChangeHistory' => PriceChangeSchedule::query()
                ->where('status', PriceChangeSchedule::STATUS_EFFECTIVE)
                ->with(['lines.vehicleType.translations'])
                ->orderByDesc('effective_on')
                ->orderByDesc('id')
                ->get(),
        ]);
    }

    public function updateCapacity(AdminPanelCapacityUpdateRequest $request): RedirectResponse
    {
        $capacity = (int) $request->validated('available_parking_slots');
        SystemConfig::setValue('available_parking_slots', $capacity);

        $effectiveFrom = Carbon::now()->startOfDay()->addDays(91)->format('d.m.Y.');

        return redirect()->to(route('panel_admin.settings', [], false))
            ->with('status', "Kapacitet je sačuvan. Promena ne važi retroaktivno i primenjivaće se od dana: {$effectiveFrom}");
    }

    public function storeReportEmail(AdminPanelReportEmailStoreRequest $request): RedirectResponse
    {
        $email = (string) $request->validated('email');

        // Duplicate safety (case-insensitive, but we store lowercase).
        if (ReportEmail::query()->forReport()->where('email', $email)->exists()) {
            return redirect()->to(route('panel_admin.settings', [], false))
                ->withErrors(['email' => 'Adresa se već nalazi na spisku.'])
                ->withInput();
        }

        ReportEmail::query()->create([
            'email' => $email,
            'purpose' => ReportEmail::PURPOSE_REPORT,
        ]);

        return redirect()->to(route('panel_admin.settings', [], false))
            ->with('status', 'Email adresa je dodata.');
    }

    public function storeLimoIncidentEmail(AdminPanelLimoIncidentEmailStoreRequest $request): RedirectResponse
    {
        $email = (string) $request->validated('limo_incident_email');

        if (ReportEmail::query()->forLimoIncidents()->where('email', $email)->exists()) {
            return redirect()->to(route('panel_admin.settings', [], false))
                ->withErrors(['limo_incident_email' => 'Adresa se već nalazi na spisku.'])
                ->withInput();
        }

        ReportEmail::query()->create([
            'email' => $email,
            'purpose' => ReportEmail::PURPOSE_LIMO_INCIDENTS,
        ]);

        return redirect()->to(route('panel_admin.settings', [], false))
            ->with('status', 'Email adresa za incidente je dodata.');
    }

    public function destroyReportEmail(ReportEmail $reportEmail): RedirectResponse
    {
        if ($reportEmail->purpose !== ReportEmail::PURPOSE_REPORT) {
            abort(404);
        }

        $email = $reportEmail->email;
        $reportEmail->delete();

        return redirect()->to(route('panel_admin.settings', [], false))
            ->with('status', "Email adresa {$email} je obrisana.");
    }

    public function destroyLimoIncidentEmail(ReportEmail $reportEmail): RedirectResponse
    {
        if ($reportEmail->purpose !== ReportEmail::PURPOSE_LIMO_INCIDENTS) {
            abort(404);
        }

        $email = $reportEmail->email;
        $reportEmail->delete();

        return redirect()->to(route('panel_admin.settings', [], false))
            ->with('status', "Email adresa za incidente {$email} je obrisana.");
    }

    public function storePriceChange(AdminPanelPriceChangeRequest $request, PriceChangeAdmin $priceChanges): RedirectResponse
    {
        $priceChanges->create($this->panelAdmin($request), $request->validated());

        return redirect()->to(route('panel_admin.settings', [], false))
            ->with('status', 'Zakazana promjena cijena je sačuvana. Nove cijene ne važe odmah.');
    }

    public function updatePriceChange(AdminPanelPriceChangeRequest $request, PriceChangeAdmin $priceChanges): RedirectResponse
    {
        $priceChanges->update($this->panelAdmin($request), $request->validated());

        return redirect()->to(route('panel_admin.settings', [], false))
            ->with('status', 'Zakazana promjena cijena je izmijenjena. Nove cijene ne važe odmah.');
    }

    public function destroyPriceChange(Request $request, PriceChangeAdmin $priceChanges): RedirectResponse
    {
        $priceChanges->cancel($this->panelAdmin($request));

        return redirect()->to(route('panel_admin.settings', [], false))
            ->with('status', 'Zakazana promjena cijena je uklonjena.');
    }

    private function panelAdmin(Request $request): Admin
    {
        $admin = $request->user();
        if (! $admin instanceof Admin) {
            abort(403);
        }

        return $admin;
    }
}

