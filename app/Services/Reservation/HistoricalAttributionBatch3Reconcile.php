<?php

namespace App\Services\Reservation;

use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Read-only comparison of active Batch 3 audits with the union of approval records.
 *
 * Counts are reported beside the sets. A matching total is not treated as proof.
 */
final class HistoricalAttributionBatch3Reconcile
{
    /**
     * @return array{
     *     frozen_class8: int,
     *     frozen_hold: int,
     *     frozen_eligible: int,
     *     approved: int,
     *     active_batch3: int,
     *     pending: list<int>,
     *     problems: list<array{reservation_id: string, problem: string, evidence: string, agency_user_id: string}>,
     *     batch1_active: int,
     *     batch2_active: int,
     *     agreed: bool
     * }
     */
    public function compare(string $manifestPath, string $approvedDirectory): array
    {
        if (! is_file($manifestPath)) {
            throw new RuntimeException('The frozen manifest was not found.');
        }
        $manifest = HistoricalAttributionBatch3Catalog::readJson($manifestPath);
        $frozenHold = array_map(static fn (array $row): int => (int) $row['reservation_id'], $manifest['hold']['rows'] ?? []);
        $frozenEligible = [];
        foreach ($manifest['groups'] ?? [] as $group) {
            foreach ($group['rows'] ?? [] as $row) {
                $frozenEligible[] = (int) $row['reservation_id'];
            }
        }
        $frozen = array_fill_keys([...$frozenHold, ...$frozenEligible], true);

        $approved = [];
        $problems = [];
        if (is_dir($approvedDirectory)) {
            $sidecars = glob($approvedDirectory.DIRECTORY_SEPARATOR.'*.approval.json') ?: [];
            sort($sidecars);
            foreach ($sidecars as $sidecar) {
                $csv = substr($sidecar, 0, -strlen('.approval.json')).'.csv';
                if (! is_file($csv)) {
                    $problems[] = $this->problem('0', 'approved_file_missing', '', '');
                    continue;
                }
                try {
                    $verified = app(HistoricalAttributionBatch3Approval::class)->verify($csv);
                } catch (RuntimeException) {
                    $problems[] = $this->problem('0', 'approved_file_changed', basename($csv), '');
                    continue;
                }
                $kind = HistoricalAttributionBatch3Catalog::kind($verified['record']);
                foreach ($verified['rows'] as $row) {
                    if (isset($approved[$row['reservation_id']])) {
                        $problems[] = $this->problem((string) $row['reservation_id'], 'duplicate_approval', '', '');
                        continue;
                    }
                    $approved[$row['reservation_id']] = [
                        'user_id' => $row['proposed_user_id'],
                        'kind' => $kind,
                    ];
                }
            }
        }

        $batch1 = HistoricalAttributionBatchWriter::EVIDENCE;
        $batch2 = array_values(HistoricalAttributionBatch2Preflight::EVIDENCE_BY_CATEGORY);
        $batch3 = [HistoricalAttributionBatch3Catalog::EVIDENCE, HistoricalAttributionBatch3Catalog::EVIDENCE_REVIEWED];
        $active = DB::table('reservation_attributions')->whereNotNull('active_reservation_id')->get();
        $batch1Active = 0;
        $batch2Active = 0;
        $activeBatch3 = [];
        foreach ($active as $audit) {
            $evidence = (string) $audit->evidence;
            if ($evidence === $batch1) {
                $batch1Active++;
            } elseif (in_array($evidence, $batch2, true)) {
                $batch2Active++;
            } elseif (in_array($evidence, $batch3, true)) {
                $activeBatch3[(int) $audit->reservation_id] = $audit;
            }
        }

        $reservationUserIds = [];
        $ids = array_keys($activeBatch3);
        foreach (array_chunk($ids, 500) as $chunk) {
            if ($chunk === []) {
                continue;
            }
            foreach (DB::table('reservations')->whereIn('id', $chunk)->get(['id', 'user_id']) as $reservation) {
                $reservationUserIds[(int) $reservation->id] = $reservation->user_id === null ? null : (int) $reservation->user_id;
            }
        }

        foreach ($activeBatch3 as $reservationId => $audit) {
            $approval = $approved[$reservationId] ?? null;
            $evidence = (string) $audit->evidence;
            $agencyId = (int) $audit->agency_user_id;
            if ($approval === null) {
                $problems[] = $this->problem((string) $reservationId, isset($frozen[$reservationId]) ? 'unapproved_frozen_audit' : 'active_outside_approval', $evidence, (string) $agencyId);
                continue;
            }
            $expectedEvidence = $approval['kind'] === 'review'
                ? HistoricalAttributionBatch3Catalog::EVIDENCE_REVIEWED
                : HistoricalAttributionBatch3Catalog::EVIDENCE;
            if ($evidence !== $expectedEvidence) {
                $problems[] = $this->problem((string) $reservationId, 'evidence_kind_mismatch', $evidence, (string) $agencyId);
            }
            if ($agencyId !== $approval['user_id']) {
                $problems[] = $this->problem((string) $reservationId, 'agency_mismatch', $evidence, (string) $agencyId);
            }
            $userId = $reservationUserIds[$reservationId] ?? null;
            if ($userId !== $agencyId) {
                $problems[] = $this->problem((string) $reservationId, 'user_id_mismatch', $evidence, (string) $agencyId);
            }
        }

        $pending = [];
        foreach ($approved as $reservationId => $approval) {
            if (! isset($activeBatch3[$reservationId])) {
                $pending[] = $reservationId;
            }
        }
        sort($pending);

        return [
            'frozen_class8' => count($frozen),
            'frozen_hold' => count($frozenHold),
            'frozen_eligible' => count($frozenEligible),
            'approved' => count($approved),
            'active_batch3' => count($activeBatch3),
            'pending' => $pending,
            'problems' => $problems,
            'batch1_active' => $batch1Active,
            'batch2_active' => $batch2Active,
            'agreed' => $problems === [],
        ];
    }

    /**
     * @return array{reservation_id: string, problem: string, evidence: string, agency_user_id: string}
     */
    private function problem(string $reservationId, string $problem, string $evidence, string $agencyId): array
    {
        return [
            'reservation_id' => $reservationId,
            'problem' => $problem,
            'evidence' => $evidence,
            'agency_user_id' => $agencyId,
        ];
    }
}
