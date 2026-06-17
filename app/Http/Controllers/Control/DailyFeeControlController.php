<?php

namespace App\Http\Controllers\Control;

use App\Http\Controllers\Controller;
use App\Http\Requests\Control\DailyFeeControlCheckRequest;
use App\Services\Control\DailyFeeControlService;
use Illuminate\View\View;

final class DailyFeeControlController extends Controller
{
    public function index(DailyFeeControlService $service): View
    {
        return view('control.daily-fee-control', [
            'pageTitle' => 'Kontrola dnevne naknade',
            'result' => null,
            'submittedPlate' => null,
            'todayList' => $service->paidDailyFeeVehiclesForToday(),
        ]);
    }

    public function check(DailyFeeControlCheckRequest $request, DailyFeeControlService $service): View
    {
        $plate = (string) $request->validated('license_plate');

        return view('control.daily-fee-control', [
            'pageTitle' => 'Kontrola dnevne naknade',
            'result' => $service->checkPlateForToday($plate),
            'submittedPlate' => $plate,
            'todayList' => $service->paidDailyFeeVehiclesForToday(),
        ]);
    }
}
