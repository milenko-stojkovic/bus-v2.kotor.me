<?php

namespace App\Http\Controllers\AdminPanel;

use App\Http\Controllers\Controller;
use App\Http\Requests\AdminPanel\AdminPanelInsightSearchRequest;
use App\Models\TempData;
use App\Services\AdminPanel\Insight\AdminInsightService;
use Illuminate\Http\Request;
use Illuminate\View\View;

class InsightController extends Controller
{
    public function index(Request $request, AdminInsightService $insight): View
    {
        $results = null;
        $criteria = $request->query();

        if ($request->query('search') === '1') {
            $validated = $request->validate((new AdminPanelInsightSearchRequest)->rules());
            $results = $insight->search($validated);
            $criteria = $validated;
        }

        return view('admin-panel.insight.index', [
            'navActive' => 'insight',
            'insightTab' => 'reservations',
            'pageTitle' => 'Uvid',
            'countries' => (array) config('countries', []),
            'statuses' => [
                TempData::STATUS_PENDING => TempData::STATUS_PENDING,
                TempData::STATUS_PROCESSED => TempData::STATUS_PROCESSED,
                TempData::STATUS_LATE_SUCCESS => TempData::STATUS_LATE_SUCCESS,
                TempData::STATUS_LATE_MANUAL_REVIEW => TempData::STATUS_LATE_MANUAL_REVIEW,
                TempData::STATUS_LATE_REJECTED => TempData::STATUS_LATE_REJECTED,
                TempData::STATUS_CANCELED => TempData::STATUS_CANCELED,
                TempData::STATUS_EXPIRED => TempData::STATUS_EXPIRED,
                // compatibility with older sqlite schema
                'failed' => 'failed',
            ],
            'resolutionReasons' => TempData::query()
                ->whereNotNull('resolution_reason')
                ->where('resolution_reason', '!=', '')
                ->distinct()
                ->orderBy('resolution_reason')
                ->pluck('resolution_reason')
                ->values()
                ->all(),
            'criteria' => $criteria,
            'results' => $results,
        ]);
    }

    public function show(string $merchantTransactionId, AdminInsightService $insight): View
    {
        $case = $insight->case($merchantTransactionId);

        return view('admin-panel.insight.show', [
            'navActive' => 'insight',
            'insightTab' => 'reservations',
            'pageTitle' => 'Uvid — '.$merchantTransactionId,
            'case' => $case,
        ]);
    }
}

