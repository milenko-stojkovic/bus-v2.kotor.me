<?php

namespace App\Services\AdminPanel\Insight;

use App\Models\AgencyAdvanceTopup;
use App\Models\AgencyAdvanceTransaction;
use App\Models\Reservation;
use App\Models\User;
use App\Services\AdminPanel\Agency\AdminAgencySearchService;
use Illuminate\Support\Collection;

final class AdminAdvanceInsightService
{
    public function __construct(
        private readonly AdminAgencySearchService $agencySearch,
        private readonly PaymentLogTimelineService $timeline,
    ) {}

    /**
     * @param  array<string, mixed>  $criteria
     * @return array{results:list<array<string,mixed>>}
     */
    public function search(array $criteria): array
    {
        $q = AgencyAdvanceTopup::query()
            ->with(['agencyUser'])
            ->orderByDesc('created_at');

        if (! empty($criteria['merchant_transaction_id'])) {
            $q->where('merchant_transaction_id', 'like', '%'.trim((string) $criteria['merchant_transaction_id']).'%');
        }
        if (! empty($criteria['date_from'])) {
            $q->whereDate('created_at', '>=', (string) $criteria['date_from']);
        }
        if (! empty($criteria['date_to'])) {
            $q->whereDate('created_at', '<=', (string) $criteria['date_to']);
        }
        if (! empty($criteria['status'])) {
            $q->where('status', (string) $criteria['status']);
        }
        if (! empty($criteria['agency_q'])) {
            $agencyIds = $this->matchingAgencyUserIds((string) $criteria['agency_q']);
            if ($agencyIds === []) {
                return ['results' => []];
            }
            $q->whereIn('agency_user_id', $agencyIds);
        }

        /** @var Collection<int, AgencyAdvanceTopup> $rows */
        $rows = $q->limit(200)->get();

        $topupIds = $rows->pluck('id')->all();
        $ledgerByTopupId = AgencyAdvanceTransaction::query()
            ->where('reference_type', 'advance_topup')
            ->whereIn('reference_id', $topupIds)
            ->get()
            ->keyBy('reference_id');

        $out = [];
        foreach ($rows as $topup) {
            $agency = $topup->agencyUser;
            $ledger = $ledgerByTopupId->get($topup->id);

            $out[] = [
                'merchant_transaction_id' => (string) $topup->merchant_transaction_id,
                'topup_id' => (int) $topup->id,
                'created_at' => $topup->created_at?->toDateTimeString(),
                'status' => (string) $topup->status,
                'amount' => (string) $topup->amount,
                'agency_user_id' => (int) $topup->agency_user_id,
                'agency_name' => (string) ($agency->name ?? ''),
                'agency_email' => (string) ($agency->email ?? ''),
                'paid_at' => $topup->paid_at?->toDateTimeString(),
                'failed_at' => $topup->failed_at?->toDateTimeString(),
                'ledger_exists' => $ledger !== null,
                'confirmation_sent_at' => $topup->confirmation_sent_at?->toDateTimeString(),
            ];
        }

        return ['results' => $out];
    }

    /**
     * @return array<string, mixed>
     */
    public function case(string $merchantTransactionId): array
    {
        $mtid = trim($merchantTransactionId);

        $topup = AgencyAdvanceTopup::query()
            ->with(['agencyUser'])
            ->where('merchant_transaction_id', $mtid)
            ->first();

        if (! $topup) {
            abort(404);
        }

        $ledgerTopup = AgencyAdvanceTransaction::query()
            ->where('reference_type', 'advance_topup')
            ->where('reference_id', $topup->id)
            ->first();

        $ledgerByMtid = AgencyAdvanceTransaction::query()
            ->where('merchant_transaction_id', $mtid)
            ->orderByDesc('id')
            ->get();

        $reservation = Reservation::query()
            ->where('merchant_transaction_id', $mtid)
            ->first();

        $timeline = $this->timeline->timelineForMtid($mtid);

        return [
            'merchant_transaction_id' => $mtid,
            'topup' => $topup,
            'agency' => $topup->agencyUser,
            'ledger_topup' => $ledgerTopup,
            'ledger_rows' => $ledgerByMtid,
            'reservation' => $reservation,
            'timeline' => $timeline['events'],
            'timeline_available' => $timeline['available'],
            'timeline_note' => $timeline['note'],
        ];
    }

    /**
     * @return list<int>
     */
    private function matchingAgencyUserIds(string $term): array
    {
        $q = User::query();
        $this->agencySearch->applySearch($q, $term);

        return $q->limit(500)->pluck('id')->map(fn ($id) => (int) $id)->all();
    }
}
