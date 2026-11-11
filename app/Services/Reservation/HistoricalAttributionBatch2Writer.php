<?php

namespace App\Services\Reservation;

use App\Exceptions\HistoricalAttributionException;
use App\Models\Admin;
use App\Models\Reservation;
use App\Models\ReservationAttribution;
use App\Models\User;
use App\Support\ReservationOrigin;
use Illuminate\Support\Facades\DB;

/**
 * Attributes one approved V1 batch 2 row at a time.
 *
 * A cheap audit read can skip rows that are already attributed or that have a
 * closed Batch 2 audit. Those rows do not rebuild identity maps and do not use
 * an attempt. Every row that can still be written opens its own transaction.
 * Inside that transaction the closed-audit rule is enforced before the
 * canonical email, name, and plate maps are read, and again under the
 * reservation lock before the audit insert. The maps are read before that lock.
 * HistoricalReservationAttributionService then writes the audit and user_id in
 * a savepoint of the same transaction.
 *
 * InnoDB REPEATABLE READ gives this transaction one snapshot, not
 * serializability. A concurrent account change that commits after the snapshot
 * starts is not part of the uniqueness check. User rows are not locked, so
 * account registration and editing stay available. A plate label is the label
 * in this same snapshot; caching that map across transactions would make the
 * label mean an earlier moment.
 *
 * Rollback reverses active Batch 2 audits for reservation ids listed in the
 * supplied CSV and selected category. An audit whose reservation is absent
 * from that CSV is outside the declared scope and is left unchanged.
 */
final class HistoricalAttributionBatch2Writer
{
    public const MAX_LIMIT = 50;

    public const ROLLBACK_EVIDENCE = 'rollback:v1-phase2-batch2';

    /** @var array<string, string> */
    public const ROLLBACK_EVIDENCE_BY_CATEGORY = [
        'R' => 'rollback:v1-phase2-batch2:plate_R',
        'A1' => 'rollback:v1-phase2-batch2:plate_A1',
        'A2' => 'rollback:v1-phase2-batch2:plate_A2',
        'B' => 'rollback:v1-phase2-batch2:plate_B',
        'C' => 'rollback:v1-phase2-batch2:plate_C',
    ];

    public function __construct(
        private readonly HistoricalAttributionBatch2Preflight $preflight,
        private readonly HistoricalAttributionBatch2DryRun $dryRun,
        private readonly HistoricalReservationAttributionService $attributions,
    ) {}

    /**
     * @return array{
     *     input_valid: bool,
     *     wrote: int,
     *     skipped: int,
     *     rejected: int,
     *     already_done: int,
     *     manual_review: int,
     *     not_selected: int,
     *     beyond_limit: int,
     *     beyond_attempts: int,
     *     attempts: int,
     *     attempt_limit: int,
     *     stopped: bool,
     *     stop_reason: ?string,
     *     rows: list<array{reservation_id: string, proposed_user_id: string, proposed_plate_category: string, outcome: string, reason: string, current_plate_category: string}>
     * }
     */
    public function execute(string $csvPath, Admin $actor, string $category, int $limit, int $attemptLimit): array
    {
        $candidates = $this->preflight->candidateRows($csvPath);
        if (! $candidates['input_valid']) {
            $review = $this->preflight->review($csvPath);

            return $this->emptyExecution($review['rows'], false, $attemptLimit);
        }

        $preliminary = $this->preliminary($candidates['rows']);
        $rows = [];
        $wrote = 0;
        $skipped = 0;
        $rejected = 0;
        $alreadyDone = 0;
        $manualReview = 0;
        $notSelected = 0;
        $beyondLimit = 0;
        $beyondAttempts = 0;
        $attempts = 0;
        $stopped = false;
        $stopReason = null;

        foreach ($candidates['rows'] as $row) {
            if (! $this->categorySelected($category, $row['proposed_plate_category'])) {
                $row['outcome'] = 'not_selected';
                $row['reason'] = 'category_not_selected';
                $rows[] = $row;
                $notSelected++;
                continue;
            }

            $cheap = $this->cheapDecision($row, $preliminary);
            if ($cheap !== null) {
                $rows[] = $cheap;
                $this->countOutcome($cheap['outcome'], $skipped, $rejected, $alreadyDone, $manualReview);
                continue;
            }

            if ($wrote >= $limit) {
                $rows[] = $this->with($row, 'beyond_limit', 'limit', '');
                $beyondLimit++;
                continue;
            }
            if ($attempts >= $attemptLimit) {
                $rows[] = $this->with($row, 'beyond_attempts', 'attempt_limit', '');
                $beyondAttempts++;
                continue;
            }

            $attempt = $this->attributeOne($row, $actor);
            $rows[] = $attempt['row'];
            if ($attempt['expensive']) {
                $attempts++;
            }
            if ($attempt['row']['outcome'] === 'written') {
                $wrote++;
                continue;
            }
            if ($attempt['row']['outcome'] === 'stopped') {
                $stopped = true;
                $stopReason = $attempt['row']['reason'];
                $rejected++;
                break;
            }
            $this->countOutcome($attempt['row']['outcome'], $skipped, $rejected, $alreadyDone, $manualReview);
        }

        return [
            'input_valid' => true,
            'wrote' => $wrote,
            'skipped' => $skipped,
            'rejected' => $rejected,
            'already_done' => $alreadyDone,
            'manual_review' => $manualReview,
            'not_selected' => $notSelected,
            'beyond_limit' => $beyondLimit,
            'beyond_attempts' => $beyondAttempts,
            'attempts' => $attempts,
            'attempt_limit' => $attemptLimit,
            'stopped' => $stopped,
            'stop_reason' => $stopReason,
            'rows' => $rows,
        ];
    }

    /**
     * @return array{input_valid: bool, reversible: int, reversed: int, stopped: bool, stop_reason: ?string, rows: list<array{reservation_id: string, outcome: string, reason: string}>}
     */
    public function previewRollback(string $csvPath, ?string $category): array
    {
        $scope = $this->rollbackScope($csvPath, $category);

        return [
            'input_valid' => $scope['input_valid'],
            'reversible' => $scope['reversible'],
            'reversed' => 0,
            'stopped' => false,
            'stop_reason' => $scope['input_valid'] ? null : 'invalid_csv',
            'rows' => [],
        ];
    }

    /**
     * @return array{input_valid: bool, reversible: int, reversed: int, stopped: bool, stop_reason: ?string, rows: list<array{reservation_id: string, outcome: string, reason: string}>}
     */
    public function rollback(Admin $actor, string $csvPath, ?string $category, int $limit): array
    {
        $scope = $this->rollbackScope($csvPath, $category);
        if (! $scope['input_valid']) {
            return [
                'input_valid' => false,
                'reversible' => 0,
                'reversed' => 0,
                'stopped' => true,
                'stop_reason' => 'invalid_csv',
                'rows' => [],
            ];
        }

        $rows = [];
        $reversed = 0;
        $stopped = false;
        $stopReason = null;
        foreach ($scope['targets'] as $target) {
            if ($target['outcome'] !== 'reversible') {
                $rows[] = [
                    'reservation_id' => $target['reservation_id'],
                    'outcome' => $target['outcome'],
                    'reason' => $target['reason'],
                ];
                continue;
            }
            if ($reversed >= $limit) {
                $rows[] = [
                    'reservation_id' => $target['reservation_id'],
                    'outcome' => 'beyond_limit',
                    'reason' => 'limit',
                ];
                continue;
            }

            $reservation = Reservation::query()->find((int) $target['reservation_id']);
            $agency = User::query()->find((int) $target['agency_user_id']);
            if ($reservation === null || $agency === null) {
                $stopped = true;
                $stopReason = $reservation === null
                    ? HistoricalAttributionBatch2Preflight::REASON_MISSING
                    : HistoricalAttributionBatch2Preflight::REASON_AGENCY_MISSING;
                $rows[] = [
                    'reservation_id' => $target['reservation_id'],
                    'outcome' => 'stopped',
                    'reason' => $stopReason,
                ];
                break;
            }

            try {
                $this->attributions->reverse($reservation, $agency, $actor, $target['reversal_evidence']);
            } catch (HistoricalAttributionException $e) {
                $stopped = true;
                $stopReason = $e->reason;
                $rows[] = [
                    'reservation_id' => $target['reservation_id'],
                    'outcome' => 'stopped',
                    'reason' => $e->reason,
                ];
                break;
            }

            $reversed++;
            $rows[] = [
                'reservation_id' => $target['reservation_id'],
                'outcome' => 'reversed',
                'reason' => $target['evidence'],
            ];
        }

        return [
            'input_valid' => true,
            'reversible' => $scope['reversible'],
            'reversed' => $reversed,
            'stopped' => $stopped,
            'stop_reason' => $stopReason,
            'rows' => $rows,
        ];
    }

    /**
     * @param  array{reservation_id: string, proposed_user_id: string, proposed_plate_category: string, outcome: string, reason: string, current_plate_category: string}  $row
     * @return array{reservation_id: string, proposed_user_id: string, proposed_plate_category: string, outcome: string, reason: string, current_plate_category: string}
     */
    private function attributeOne(array $row, Admin $actor): array
    {
        $expensive = false;
        try {
            $result = DB::transaction(function () use ($row, $actor, &$expensive): array {
                $reservationId = (int) $row['reservation_id'];
                $reservation = Reservation::query()->find($reservationId);
                $audits = DB::table('reservation_attributions')
                    ->where('reservation_id', $reservationId)
                    ->get(['evidence', 'active_reservation_id', 'agency_user_id']);
                $agencyExists = User::query()->whereKey((int) $row['proposed_user_id'])->exists();
                $decided = $this->decideFromState($row, $reservation, $audits, $agencyExists);
                if ($decided !== null) {
                    return $decided;
                }

                $expensive = true;
                $snapshot = $this->dryRun->currentIdentitySnapshot();
                if (! $reservation instanceof Reservation) {
                    return $this->with($row, 'rejected', HistoricalAttributionBatch2Preflight::REASON_MISSING, '');
                }
                $agency = User::query()->find((int) $row['proposed_user_id']);
                if (! $agency instanceof User) {
                    return $this->with($row, 'rejected', HistoricalAttributionBatch2Preflight::REASON_AGENCY_MISSING, '');
                }
                $evidence = HistoricalAttributionBatch2Preflight::EVIDENCE_BY_CATEGORY[$row['proposed_plate_category']];
                try {
                    $this->attributions->attribute(
                        $reservation,
                        $agency,
                        $evidence,
                        $actor,
                        function (Reservation $locked) use ($snapshot, $row): void {
                            $this->assertNoClosedBatch2Audit($locked);
                            $this->assertStillTheProposal($locked, $row, $snapshot);
                        },
                    );
                } catch (HistoricalAttributionException $e) {
                    return $this->outcomeAfterRefusal($row, $e);
                }

                return $this->with($row, 'written', '', $row['proposed_plate_category']);
            });
        } catch (\Throwable $e) {
            return [
                'row' => $this->with($row, 'stopped', 'unexpected_failure', ''),
                'expensive' => true,
            ];
        }

        return [
            'row' => $result,
            'expensive' => $expensive,
        ];
    }

    private function assertNoClosedBatch2Audit(Reservation $locked): void
    {
        $closed = DB::table('reservation_attributions')
            ->where('reservation_id', $locked->id)
            ->whereIn('evidence', $this->batchEvidence())
            ->whereNull('active_reservation_id')
            ->lockForUpdate()
            ->exists();
        if ($closed) {
            throw new HistoricalAttributionException(
                HistoricalAttributionBatch2Preflight::REASON_CLOSED_BATCH2,
                'A closed Batch 2 attribution requires manual review.',
            );
        }
    }

    /**
     * @param  array{
     *     emails: array<string, list<int>>,
     *     names: array<string, list<int>>,
     *     plates: array<string, list<int>>
     * }  $snapshot
     */
    private function assertStillTheProposal(Reservation $locked, array $row, array $snapshot): void
    {
        $classified = $this->dryRun->classifyIdentity(
            $locked->email,
            $locked->user_name,
            $locked->license_plate,
            $snapshot,
        );
        $category = $classified['category'];
        if (! in_array($category, HistoricalAttributionBatch2DryRun::IDENTITY_CATEGORIES, true)) {
            throw new HistoricalAttributionException(
                HistoricalAttributionBatch2Preflight::REASON_NOT_IDENTITY,
                'The reservation is no longer an identity candidate.',
            );
        }
        if ((int) $classified['agency_id'] !== (int) $row['proposed_user_id']) {
            throw new HistoricalAttributionException(
                HistoricalAttributionBatch2Preflight::REASON_USER_MISMATCH,
                'The unique email and name no longer identify the proposed user.',
            );
        }
        if ($category !== $row['proposed_plate_category']) {
            throw new HistoricalAttributionException(
                HistoricalAttributionBatch2Preflight::REASON_PLATE_CHANGED,
                'The plate category no longer matches the proposal.',
            );
        }
    }

    /**
     * @param  array{reservation_id: string, proposed_user_id: string, proposed_plate_category: string, outcome: string, reason: string, current_plate_category: string}  $row
     * @return array{reservation_id: string, proposed_user_id: string, proposed_plate_category: string, outcome: string, reason: string, current_plate_category: string}
     */
    private function outcomeAfterRefusal(array $row, HistoricalAttributionException $e): array
    {
        if ($e->reason === HistoricalAttributionException::ALREADY_ATTRIBUTED) {
            $audit = DB::table('reservation_attributions')
                ->where('active_reservation_id', (int) $row['reservation_id'])
                ->first();
            $batchEvidence = array_values(HistoricalAttributionBatch2Preflight::EVIDENCE_BY_CATEGORY);
            if ($audit !== null
                && in_array((string) $audit->evidence, $batchEvidence, true)
                && (int) $audit->agency_user_id === (int) $row['proposed_user_id']) {
                return $this->with($row, 'already_done', (string) $audit->evidence, $row['proposed_plate_category']);
            }

            $currentUserId = DB::table('reservations')->where('id', (int) $row['reservation_id'])->value('user_id');
            if ($currentUserId !== null && $audit === null) {
                return $this->with($row, 'rejected', HistoricalAttributionBatch2Preflight::REASON_USER_SET, '');
            }

            return $this->with($row, 'rejected', HistoricalAttributionBatch2Preflight::REASON_ACTIVE_AUDIT, '');
        }
        if ($e->reason === HistoricalAttributionException::NOT_IMPORTED_V1) {
            return $this->with($row, 'rejected', HistoricalAttributionBatch2Preflight::REASON_NOT_V1, '');
        }
        if ($e->reason === HistoricalAttributionBatch2Preflight::REASON_PLATE_CHANGED) {
            return $this->with($row, 'skipped', $e->reason, '');
        }
        if ($e->reason === HistoricalAttributionBatch2Preflight::REASON_CLOSED_BATCH2) {
            return $this->with($row, 'manual_review', $e->reason, '');
        }

        return $this->with($row, 'rejected', $e->reason, '');
    }

    /**
     * @param  list<array{reservation_id: string, proposed_user_id: string, proposed_plate_category: string, outcome: string, reason: string, current_plate_category: string}>  $rows
     * @return array{
     *     reservations: array<int, object>,
     *     audits: array<int, \Illuminate\Support\Collection<int, object>>,
     *     agencies: array<int, true>
     * }
     */
    private function preliminary(array $rows): array
    {
        $ids = [];
        $proposed = [];
        foreach ($rows as $row) {
            $ids[] = (int) $row['reservation_id'];
            $proposed[] = (int) $row['proposed_user_id'];
        }
        $ids = array_values(array_unique($ids));
        $proposed = array_values(array_unique($proposed));

        $reservations = [];
        foreach (array_chunk($ids, 500) as $chunk) {
            $found = DB::table('reservations')->whereIn('id', $chunk)->get(['id', 'user_id', 'created_at']);
            foreach ($found as $reservation) {
                $reservations[(int) $reservation->id] = $reservation;
            }
        }

        $audits = [];
        foreach (array_chunk($ids, 500) as $chunk) {
            $found = DB::table('reservation_attributions')
                ->whereIn('reservation_id', $chunk)
                ->get(['reservation_id', 'evidence', 'active_reservation_id', 'agency_user_id']);
            foreach ($found as $audit) {
                $audits[(int) $audit->reservation_id][] = $audit;
            }
        }

        $agencies = [];
        foreach (array_chunk($proposed, 500) as $chunk) {
            foreach (DB::table('users')->whereIn('id', $chunk)->pluck('id') as $id) {
                $agencies[(int) $id] = true;
            }
        }

        return [
            'reservations' => $reservations,
            'audits' => $audits,
            'agencies' => $agencies,
        ];
    }

    /**
     * @param  array{reservation_id: string, proposed_user_id: string, proposed_plate_category: string, outcome: string, reason: string, current_plate_category: string}  $row
     * @param  array{reservations: array<int, object>, audits: array<int, list<object>>, agencies: array<int, true>}  $preliminary
     * @return array{reservation_id: string, proposed_user_id: string, proposed_plate_category: string, outcome: string, reason: string, current_plate_category: string}|null
     */
    private function cheapDecision(array $row, array $preliminary): ?array
    {
        $id = (int) $row['reservation_id'];

        return $this->decideFromState(
            $row,
            $preliminary['reservations'][$id] ?? null,
            $preliminary['audits'][$id] ?? [],
            isset($preliminary['agencies'][(int) $row['proposed_user_id']]),
        );
    }

    /**
     * @param  array{reservation_id: string, proposed_user_id: string, proposed_plate_category: string, outcome: string, reason: string, current_plate_category: string}  $row
     * @param  iterable<int, object>  $audits
     * @return array{reservation_id: string, proposed_user_id: string, proposed_plate_category: string, outcome: string, reason: string, current_plate_category: string}|null
     */
    private function decideFromState(array $row, ?object $reservation, iterable $audits, bool $agencyExists): ?array
    {
        if ($reservation === null) {
            return $this->with($row, 'rejected', HistoricalAttributionBatch2Preflight::REASON_MISSING, '');
        }
        if (! ReservationOrigin::isImportedV1($reservation->created_at)) {
            return $this->with($row, 'rejected', HistoricalAttributionBatch2Preflight::REASON_NOT_V1, '');
        }

        $active = null;
        $closedBatch2 = false;
        foreach ($audits as $audit) {
            if ($audit->active_reservation_id !== null) {
                $active = $audit;
            }
            if ($audit->active_reservation_id === null && in_array((string) $audit->evidence, $this->batchEvidence(), true)) {
                $closedBatch2 = true;
            }
        }
        if ($active !== null) {
            $sameBatch = in_array((string) $active->evidence, $this->batchEvidence(), true)
                && (int) $active->agency_user_id === (int) $row['proposed_user_id'];
            if ($sameBatch) {
                return $this->with($row, 'already_done', (string) $active->evidence, $row['proposed_plate_category']);
            }

            return $this->with($row, 'rejected', HistoricalAttributionBatch2Preflight::REASON_ACTIVE_AUDIT, '');
        }
        if ($closedBatch2) {
            return $this->with($row, 'manual_review', HistoricalAttributionBatch2Preflight::REASON_CLOSED_BATCH2, '');
        }
        if ($reservation->user_id !== null) {
            return $this->with($row, 'rejected', HistoricalAttributionBatch2Preflight::REASON_USER_SET, '');
        }
        if (! $agencyExists) {
            return $this->with($row, 'rejected', HistoricalAttributionBatch2Preflight::REASON_AGENCY_MISSING, '');
        }

        return null;
    }

    /**
     * @return list<string>
     */
    private function batchEvidence(): array
    {
        return array_values(HistoricalAttributionBatch2Preflight::EVIDENCE_BY_CATEGORY);
    }

    /**
     * @return array{
     *     input_valid: bool,
     *     reversible: int,
     *     targets: list<array{reservation_id: string, outcome: string, reason: string, agency_user_id: int, evidence: string, reversal_evidence: string}>
     * }
     */
    private function rollbackScope(string $csvPath, ?string $category): array
    {
        $candidates = $this->preflight->candidateRows($csvPath);
        if (! $candidates['input_valid']) {
            return [
                'input_valid' => false,
                'reversible' => 0,
                'targets' => [],
            ];
        }

        $selected = [];
        foreach ($candidates['rows'] as $row) {
            if (! $this->categorySelected($category ?? 'all', $row['proposed_plate_category'])) {
                $selected[] = [
                    'reservation_id' => $row['reservation_id'],
                    'outcome' => 'not_selected',
                    'reason' => 'category_not_selected',
                    'agency_user_id' => 0,
                    'evidence' => '',
                    'reversal_evidence' => '',
                ];
                continue;
            }
            $selected[] = $row;
        }

        $ids = [];
        foreach ($selected as $row) {
            if ($row['outcome'] === 'not_selected') {
                continue;
            }
            $ids[] = (int) $row['reservation_id'];
        }
        $activeByReservation = [];
        foreach (array_chunk($ids, 500) as $chunk) {
            if ($chunk === []) {
                continue;
            }
            $found = ReservationAttribution::query()
                ->whereIn('reservation_id', $chunk)
                ->whereNotNull('active_reservation_id')
                ->get();
            foreach ($found as $audit) {
                $activeByReservation[(int) $audit->reservation_id] = $audit;
            }
        }

        $targets = [];
        $reversible = 0;
        foreach ($selected as $row) {
            if ($row['outcome'] === 'not_selected') {
                $targets[] = $row;
                continue;
            }
            $audit = $activeByReservation[(int) $row['reservation_id']] ?? null;
            $expectedEvidence = HistoricalAttributionBatch2Preflight::EVIDENCE_BY_CATEGORY[$row['proposed_plate_category']];
            if ($audit === null || (string) $audit->evidence !== $expectedEvidence) {
                $targets[] = [
                    'reservation_id' => $row['reservation_id'],
                    'outcome' => 'not_reversed',
                    'reason' => $audit === null ? 'not_active' : 'category_evidence_mismatch',
                    'agency_user_id' => 0,
                    'evidence' => '',
                    'reversal_evidence' => '',
                ];
                continue;
            }
            $reversible++;
            $targets[] = [
                'reservation_id' => $row['reservation_id'],
                'outcome' => 'reversible',
                'reason' => (string) $audit->evidence,
                'agency_user_id' => (int) $audit->agency_user_id,
                'evidence' => (string) $audit->evidence,
                'reversal_evidence' => self::ROLLBACK_EVIDENCE_BY_CATEGORY[$row['proposed_plate_category']],
            ];
        }

        return [
            'input_valid' => true,
            'reversible' => $reversible,
            'targets' => $targets,
        ];
    }

    private function countOutcome(string $outcome, int &$skipped, int &$rejected, int &$alreadyDone, int &$manualReview): void
    {
        if ($outcome === 'skipped') {
            $skipped++;

            return;
        }
        if ($outcome === 'already_done') {
            $alreadyDone++;

            return;
        }
        if ($outcome === 'manual_review') {
            $manualReview++;
            $skipped++;

            return;
        }
        if ($outcome === 'beyond_limit' || $outcome === 'beyond_attempts' || $outcome === 'not_selected' || $outcome === 'written') {
            return;
        }
        $rejected++;
    }

    private function categorySelected(string $category, string $proposed): bool
    {
        return $category === 'all' || $category === $proposed;
    }

    /**
     * @param  list<array{reservation_id: string, proposed_user_id: string, proposed_plate_category: string, outcome: string, reason: string, current_plate_category: string}>  $rows
     * @return array{
     *     input_valid: bool,
     *     wrote: int,
     *     skipped: int,
     *     rejected: int,
     *     already_done: int,
     *     manual_review: int,
     *     not_selected: int,
     *     beyond_limit: int,
     *     beyond_attempts: int,
     *     attempts: int,
     *     attempt_limit: int,
     *     stopped: bool,
     *     stop_reason: ?string,
     *     rows: list<array{reservation_id: string, proposed_user_id: string, proposed_plate_category: string, outcome: string, reason: string, current_plate_category: string}>
     * }
     */
    private function emptyExecution(array $rows, bool $inputValid, int $attemptLimit): array
    {
        $rejected = 0;
        $skipped = 0;
        $manualReview = 0;
        foreach ($rows as $row) {
            if ($row['outcome'] === 'rejected') {
                $rejected++;
            }
            if ($row['outcome'] === 'skipped' || $row['outcome'] === 'manual_review') {
                $skipped++;
            }
            if ($row['outcome'] === 'manual_review') {
                $manualReview++;
            }
        }

        return [
            'input_valid' => $inputValid,
            'wrote' => 0,
            'skipped' => $skipped,
            'rejected' => $rejected,
            'already_done' => 0,
            'manual_review' => $manualReview,
            'not_selected' => 0,
            'beyond_limit' => 0,
            'beyond_attempts' => 0,
            'attempts' => 0,
            'attempt_limit' => $attemptLimit,
            'stopped' => false,
            'stop_reason' => null,
            'rows' => $rows,
        ];
    }

    /**
     * @param  array{reservation_id: string, proposed_user_id: string, proposed_plate_category: string, outcome: string, reason: string, current_plate_category: string}  $row
     * @return array{reservation_id: string, proposed_user_id: string, proposed_plate_category: string, outcome: string, reason: string, current_plate_category: string}
     */
    private function with(array $row, string $outcome, string $reason, string $current): array
    {
        $row['outcome'] = $outcome;
        $row['reason'] = $reason;
        $row['current_plate_category'] = $current;

        return $row;
    }
}
