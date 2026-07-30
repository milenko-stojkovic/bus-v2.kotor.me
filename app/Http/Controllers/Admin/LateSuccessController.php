<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\TempData;
use App\Services\Payment\LateSuccessCapacityAssessor;
use App\Services\Payment\LateSuccessManualResolutionService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class LateSuccessController extends Controller
{
    public function index(Request $request): View
    {
        $date = $request->string('date')->toString();
        $status = $request->string('status')->toString();
        $resolutionReason = $request->string('resolution_reason')->toString();

        $rows = TempData::query()
            ->with(['vehicleType', 'dropOffTimeSlot', 'pickUpTimeSlot'])
            ->whereIn('status', [
                TempData::STATUS_LATE_SUCCESS,
                TempData::STATUS_LATE_MANUAL_REVIEW,
                TempData::STATUS_LATE_REJECTED,
                TempData::STATUS_PROCESSED,
            ])
            ->when($date !== '', fn ($q) => $q->whereDate('reservation_date', $date))
            ->when($status !== '', fn ($q) => $q->where('status', $status))
            ->when($resolutionReason !== '', fn ($q) => $q->where('resolution_reason', $resolutionReason))
            ->orderByDesc('id')
            ->paginate(20)
            ->withQueryString();

        return view('admin.late-success.index', [
            'rows' => $rows,
            'filters' => [
                'date' => $date,
                'status' => $status,
                'resolution_reason' => $resolutionReason,
            ],
        ]);
    }

    public function show(
        int $id,
        LateSuccessManualResolutionService $resolution,
        LateSuccessCapacityAssessor $capacityAssessor,
    ): View {
        $row = TempData::query()
            ->with(['vehicleType.translations', 'dropOffTimeSlot', 'pickUpTimeSlot', 'user'])
            ->findOrFail($id);

        $canAct = $resolution->exposesManualActions($row);
        $capacity = $canAct ? $capacityAssessor->assess($row) : null;

        return view('admin.late-success.show', [
            'row' => $row,
            'canAct' => $canAct,
            'capacity' => $capacity,
        ]);
    }

    public function forceCreate(int $id, LateSuccessManualResolutionService $resolution): RedirectResponse
    {
        $result = $resolution->forceCreate($id);

        if (! $result['ok']) {
            return redirect()->back()->with('error', $result['message']);
        }

        return redirect()->back()->with('message', $result['message']);
    }

    public function reject(int $id, LateSuccessManualResolutionService $resolution): RedirectResponse
    {
        $result = $resolution->reject($id);

        if (! $result['ok']) {
            return redirect()->back()->with('error', $result['message']);
        }

        return redirect()->back()->with('message', $result['message']);
    }
}
