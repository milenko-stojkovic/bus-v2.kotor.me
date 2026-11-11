<?php

namespace App\Http\Controllers;

use App\Http\Requests\Panel\PanelStatisticsRequest;
use App\Services\Reservation\AgencyFinancialCardService;
use App\Services\Reservation\AgencyLicensePlateReportService;
use App\Services\Reservation\PanelReservationListService;
use App\Services\Reservation\PanelStatisticsDateBounds;
use App\Services\Reservation\PanelStatisticsService;
use App\Services\Reservation\VehicleReplacementCandidateService;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;
use Illuminate\View\View;

class PanelController extends Controller
{
    /**
     * @return array{user: \App\Models\User, from: Carbon, to: Carbon, minDate: string, maxDate: string}
     */
    private function resolveStatisticsRange(PanelStatisticsRequest $request): array
    {
        /** @var \App\Models\User $user */
        $user = $request->user();

        $bounds = app(PanelStatisticsDateBounds::class);
        $min = $bounds->minDateFor($user);
        $financialMin = app(AgencyFinancialCardService::class)->earliestSelectableDate($user);
        if ($financialMin instanceof Carbon && $financialMin->lt($min)) {
            $min = $financialMin;
        }
        $globalMin = $bounds->globalHistoricalMinDate();
        if ($globalMin instanceof Carbon && $globalMin->lt($min)) {
            $min = $globalMin;
        }
        $max = $bounds->maxDateFor($user);

        $validated = $request->validated();

        $from = isset($validated['date_from']) && is_string($validated['date_from']) && $validated['date_from'] !== ''
            ? Carbon::parse($validated['date_from'], $min->getTimezone())->startOfDay()
            : $min->copy();
        $to = isset($validated['date_to']) && is_string($validated['date_to']) && $validated['date_to'] !== ''
            ? Carbon::parse($validated['date_to'], $min->getTimezone())->startOfDay()
            : $max->copy();

        // Clamp to bounds (closed interval).
        if ($from->lt($min)) {
            $from = $min->copy();
        }
        if ($to->gt($max)) {
            $to = $max->copy();
        }
        if ($from->gt($to)) {
            $from = $min->copy();
            $to = $max->copy();
        }

        $report = match ($validated['report'] ?? null) {
            'financial_card' => 'financial_card',
            'license_plate' => 'license_plate',
            default => 'statistics',
        };

        return [
            'user' => $user,
            'from' => $from,
            'to' => $to,
            'minDate' => $min->toDateString(),
            'maxDate' => $max->toDateString(),
            'report' => $report,
            'license_plate' => $report === 'license_plate' && is_string($validated['license_plate'] ?? null)
                ? $validated['license_plate']
                : null,
        ];
    }

    public function upcoming(Request $request, PanelReservationListService $lists, VehicleReplacementCandidateService $candidates): View
    {
        $user = $request->user();
        $upcoming = $lists->upcomingFor($user);
        $vehicles = $user->vehicles()
            ->where('status', \App\Models\Vehicle::STATUS_ACTIVE)
            ->with('vehicleType')
            ->orderBy('license_plate')
            ->get();

        $allowedByReservationId = [];
        foreach ($upcoming as $r) {
            $allowedByReservationId[(int) $r->id] = PanelReservationListService::allowsPlateChange($r)
                ? $candidates->candidatesForReservation($user, $r)
                : collect();
        }

        return view('panel.upcoming', [
            'reservations' => $upcoming,
            'vehicles' => $vehicles,
            'allowedVehiclesByReservationId' => $allowedByReservationId,
        ]);
    }

    public function realized(Request $request, PanelReservationListService $lists): View
    {
        return view('panel.realized', [
            'reservations' => $lists->realizedFor($request->user()),
        ]);
    }

    public function statistics(
        PanelStatisticsRequest $request,
        PanelStatisticsService $statisticsService,
        AgencyFinancialCardService $financialCard,
        AgencyLicensePlateReportService $plateReport,
    ): View {
        $range = $this->resolveStatisticsRange($request);
        $shared = [
            'report' => $range['report'],
            'minDate' => $range['minDate'],
            'maxDate' => $range['maxDate'],
            'date_from' => $range['from']->toDateString(),
            'date_to' => $range['to']->toDateString(),
            'plates' => $plateReport->platesFor($range['user']),
            'license_plate' => $range['license_plate'],
        ];

        if ($range['report'] === 'financial_card') {
            return view('panel.statistics', [
                ...$shared,
                'financial_card' => $financialCard->build($range['user'], $range['from'], $range['to']),
            ]);
        }

        if ($range['report'] === 'license_plate') {
            return view('panel.statistics', [
                ...$shared,
                'license_plate_report' => $plateReport->build(
                    $range['user'],
                    (string) $range['license_plate'],
                    $range['from'],
                    $range['to'],
                    app()->getLocale(),
                ),
            ]);
        }

        return view('panel.statistics', [
            ...$statisticsService->overview($range['user'], $range['from'], $range['to']),
            ...$shared,
        ]);
    }

    public function statisticsPdf(
        PanelStatisticsRequest $request,
        PanelStatisticsService $statisticsService,
        AgencyFinancialCardService $financialCard,
        AgencyLicensePlateReportService $plateReport,
        \App\Services\Pdf\PanelStatisticsPdfGenerator $pdfGenerator,
        \App\Services\Pdf\AgencyFinancialCardPdfGenerator $financialCardPdf,
        \App\Services\Pdf\AgencyLicensePlateReportPdfGenerator $platePdf,
    ): Response {
        $range = $this->resolveStatisticsRange($request);

        if ($range['report'] === 'license_plate') {
            $userLocale = is_string($range['user']->lang ?? null) ? (string) $range['user']->lang : 'cg';
            $dataset = [
                ...$plateReport->build(
                    $range['user'],
                    (string) $range['license_plate'],
                    $range['from'],
                    $range['to'],
                    $userLocale,
                ),
                'agency_name' => (string) ($range['user']->name ?? ''),
            ];
            $binary = $platePdf->renderBinary($dataset, $userLocale);
            $safePlate = preg_replace('/[^A-Za-z0-9]+/', '', $dataset['license_plate']) ?: 'tablica';
            $filename = sprintf(
                'rezervacije-po-tablici-%s-%s-%s.pdf',
                $safePlate,
                $dataset['date_from'],
                $dataset['date_to'],
            );

            return response($binary, 200, [
                'Content-Type' => 'application/pdf',
                'Content-Disposition' => 'inline; filename="'.$filename.'"',
            ]);
        }

        if ($range['report'] === 'financial_card') {
            $dataset = [
                ...$financialCard->build($range['user'], $range['from'], $range['to']),
                'agency_name' => (string) ($range['user']->name ?? ''),
            ];
            $userLocale = is_string($range['user']->lang ?? null) ? (string) $range['user']->lang : 'cg';
            $binary = $financialCardPdf->renderBinary($dataset, $userLocale);
            $filename = sprintf(
                'finansijska-kartica-agencije-%s-%s.pdf',
                $dataset['date_from'],
                $dataset['date_to'],
            );

            return response($binary, 200, [
                'Content-Type' => 'application/pdf',
                'Content-Disposition' => 'inline; filename="'.$filename.'"',
            ]);
        }

        $dataset = $statisticsService->overview($range['user'], $range['from'], $range['to']);

        $userLocale = is_string($range['user']->lang ?? null) ? (string) $range['user']->lang : 'cg';

        $binary = $pdfGenerator->renderBinary([
            ...$dataset,
            'agency_name' => (string) ($range['user']->name ?? ''),
        ], $userLocale);

        $filename = sprintf(
            'statistika-agencije-%s-%s.pdf',
            $dataset['date_from'] ?? $range['from']->toDateString(),
            $dataset['date_to'] ?? $range['to']->toDateString(),
        );

        return response($binary, 200, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => 'inline; filename="'.$filename.'"',
        ]);
    }
}
