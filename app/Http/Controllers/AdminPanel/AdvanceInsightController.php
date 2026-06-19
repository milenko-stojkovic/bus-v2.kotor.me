<?php

namespace App\Http\Controllers\AdminPanel;

use App\Http\Controllers\Controller;
use App\Http\Requests\AdminPanel\AdminPanelAdvanceInsightSearchRequest;
use App\Models\AgencyAdvanceTopup;
use App\Services\AdminPanel\Insight\AdminAdvanceInsightService;
use Illuminate\Http\Request;
use Illuminate\View\View;

class AdvanceInsightController extends Controller
{
    public function index(Request $request, AdminAdvanceInsightService $insight): View
    {
        $this->ensureAdvanceFeature();

        $results = null;
        $criteria = $request->query();

        if ($request->query('search') === '1') {
            $validated = $request->validate((new AdminPanelAdvanceInsightSearchRequest)->rules());
            $results = $insight->search($validated);
            $criteria = $validated;
        }

        return view('admin-panel.insight.advance.index', [
            'navActive' => 'insight',
            'insightTab' => 'advance',
            'pageTitle' => 'Uvid — avans',
            'statuses' => [
                AgencyAdvanceTopup::STATUS_PENDING => AgencyAdvanceTopup::STATUS_PENDING,
                AgencyAdvanceTopup::STATUS_PAID => AgencyAdvanceTopup::STATUS_PAID,
                AgencyAdvanceTopup::STATUS_FAILED => AgencyAdvanceTopup::STATUS_FAILED,
                AgencyAdvanceTopup::STATUS_EXPIRED => AgencyAdvanceTopup::STATUS_EXPIRED,
            ],
            'criteria' => $criteria,
            'results' => $results,
        ]);
    }

    public function show(string $merchantTransactionId, AdminAdvanceInsightService $insight): View
    {
        $this->ensureAdvanceFeature();

        $case = $insight->case($merchantTransactionId);

        return view('admin-panel.insight.advance.show', [
            'navActive' => 'insight',
            'insightTab' => 'advance',
            'pageTitle' => 'Uvid — avans — '.$merchantTransactionId,
            'case' => $case,
        ]);
    }

    private function ensureAdvanceFeature(): void
    {
        if (! (bool) config('features.advance_payments')) {
            abort(404);
        }
    }
}
