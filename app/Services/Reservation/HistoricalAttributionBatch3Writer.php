<?php

namespace App\Services\Reservation;

use App\Exceptions\HistoricalAttributionException;
use App\Models\Admin;
use App\Models\Reservation;
use App\Models\User;
use App\Support\ReservationOrigin;
use Illuminate\Support\Facades\DB;
use RuntimeException;
use Throwable;

/**
 * Attributes one approved V1 batch 3 row at a time.
 *
 * The authoritative decision runs inside the row transaction after the
 * reservation and its attribution rows are locked. Identity maps are read
 * only when that locked state can still be written. A preflight label is not
 * reused here.
 *
 * InnoDB REPEATABLE READ gives the identity-map read one snapshot, not
 * serializability. A user-name or user-email change that commits after the
 * snapshot starts is invisible to this attempt. User rows are not locked.
 * The reservation row is a locking read, so its email, name, and user_id are
 * the latest committed values.
 *
 * SQLite tests run on one connection. They do not prove MariaDB or InnoDB
 * concurrency. A listener on that connection can see its own uncommitted
 * writes, which a second InnoDB transaction under REPEATABLE READ would not.
 *
 * Rollback reverses active Batch 3 audits for reservation ids in the approved
 * CSV when the evidence matches that approval. Batch 1 and Batch 2 audits stay
 * unchanged, including audits whose reservation ids are absent from the CSV.
 */
final class HistoricalAttributionBatch3Writer
{
    public function __construct(
        private readonly HistoricalAttributionBatch3Approval $approval,
        private readonly HistoricalAttributionBatch3DryRun $dryRun,
        private readonly HistoricalReservationAttributionService $attributions,
    ) {}

    /**
     * @return array{rows: list<array{reservation_id: string, proposed_user_id: string, disposition: string, group_id: string, outcome: string, reason: string, evidence: string}>, accepted: int, rejected: int, already_done: int, manual_review: int}
     */
    public function preflight(string $csvPath): array
    {
        $verified = $this->approval->verify($csvPath);
        $rows = [];
        $accepted = 0;
        $rejected = 0;
        $alreadyDone = 0;
        $manualReview = 0;
        foreach ($verified['rows'] as $row) {
            $reservation = Reservation::query()->find($row['reservation_id']);
            $audits = DB::table('reservation_attributions')->where('reservation_id', $row['reservation_id'])->get()->all();
            $decided = $this->prefix($row, $reservation, $audits);
            if ($decided === null) {
                $decided = $this->identityVerdict($row, $reservation, $audits, HistoricalAttributionBatch3Catalog::kind($verified['record']));
            }
            if ($decided['outcome'] === 'written') {
                $decided['outcome'] = 'accepted';
                $decided['evidence'] = '';
                $accepted++;
            } else {
                $this->count($decided['outcome'], $rejected, $alreadyDone, $manualReview);
            }
            $rows[] = $decided;
        }

        return [
            'rows' => $rows,
            'accepted' => $accepted,
            'rejected' => $rejected,
            'already_done' => $alreadyDone,
            'manual_review' => $manualReview,
        ];
    }

    /**
     * @return array{rows: list<array{reservation_id: string, proposed_user_id: string, disposition: string, group_id: string, outcome: string, reason: string, evidence: string}>, wrote: int, rejected: int, already_done: int, manual_review: int, beyond_limit: int, beyond_attempts: int, attempts: int, attempt_limit: int, stopped: bool, stop_reason: ?string, group_id: string, evidence: string}
     */
    public function execute(string $csvPath, Admin $actor, string $confirm, int $limit, int $attemptLimit): array
    {
        $verified = $this->guardWrite($csvPath, $actor, $confirm, HistoricalAttributionBatch3Catalog::executeConfirm($this->approval->verify($csvPath)['record']), $limit, $attemptLimit);
        $kind = HistoricalAttributionBatch3Catalog::kind($verified['record']);
        $evidence = HistoricalAttributionBatch3Catalog::evidence($verified['record']);
        $rows = [];
        $wrote = 0;
        $rejected = 0;
        $alreadyDone = 0;
        $manualReview = 0;
        $beyondLimit = 0;
        $beyondAttempts = 0;
        $attempts = 0;
        $stopped = false;
        $stopReason = null;

        foreach ($verified['rows'] as $row) {
            $attempt = $this->attributeOne($row, $actor, $kind, $evidence, $wrote, $limit, $attempts, $attemptLimit);
            $rows[] = $attempt['row'];
            if ($attempt['expensive']) {
                $attempts++;
            }
            if ($attempt['row']['outcome'] === 'written') {
                $wrote++;
            } elseif ($attempt['row']['outcome'] === 'beyond_limit') {
                $beyondLimit++;
            } elseif ($attempt['row']['outcome'] === 'beyond_attempts') {
                $beyondAttempts++;
            } elseif ($attempt['row']['outcome'] === 'stopped') {
                $stopped = true;
                $stopReason = $attempt['row']['reason'];
                break;
            } else {
                $this->count($attempt['row']['outcome'], $rejected, $alreadyDone, $manualReview);
            }
        }

        return [
            'rows' => $rows,
            'wrote' => $wrote,
            'rejected' => $rejected,
            'already_done' => $alreadyDone,
            'manual_review' => $manualReview,
            'beyond_limit' => $beyondLimit,
            'beyond_attempts' => $beyondAttempts,
            'attempts' => $attempts,
            'attempt_limit' => $attemptLimit,
            'stopped' => $stopped,
            'stop_reason' => $stopReason,
            'group_id' => (string) $verified['record']['group_id'],
            'evidence' => $evidence,
        ];
    }

    /**
     * @return array{reversible: int, rows: list<array{reservation_id: string, outcome: string, reason: string}>}
     */
    public function previewRollback(string $csvPath): array
    {
        $verified = $this->approval->verify($csvPath);
        $plan = $this->rollbackPlan($verified['rows'], HistoricalAttributionBatch3Catalog::evidence($verified['record']));

        return [
            'reversible' => $plan['reversible'],
            'rows' => $plan['rows'],
        ];
    }

    /**
     * @return array{reversed: int, reversible: int, stopped: bool, stop_reason: ?string, rows: list<array{reservation_id: string, outcome: string, reason: string}>}
     */
    public function rollback(Admin $actor, string $csvPath, string $confirm, int $limit): array
    {
        $verified = $this->guardWrite($csvPath, $actor, $confirm, HistoricalAttributionBatch3Catalog::rollbackConfirm($this->approval->verify($csvPath)['record']), $limit, $limit);
        $evidence = HistoricalAttributionBatch3Catalog::evidence($verified['record']);
        $reversalEvidence = HistoricalAttributionBatch3Catalog::rollbackEvidence($verified['record']);
        $plan = $this->rollbackPlan($verified['rows'], $evidence);
        $rows = [];
        $reversed = 0;
        $stopped = false;
        $stopReason = null;

        foreach ($plan['targets'] as $target) {
            if ($target['outcome'] !== 'reversible') {
                $rows[] = [
                    'reservation_id' => $target['reservation_id'],
                    'outcome' => 'not_reversed',
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
            try {
                DB::transaction(function () use ($target, $actor, $reversalEvidence, $evidence): void {
                    $reservationId = (int) $target['reservation_id'];
                    $reservation = Reservation::query()->whereKey($reservationId)->lockForUpdate()->first();
                    $audit = DB::table('reservation_attributions')
                        ->where('reservation_id', $reservationId)
                        ->whereNotNull('active_reservation_id')
                        ->lockForUpdate()
                        ->first();
                    if (! $reservation instanceof Reservation || $audit === null) {
                        throw new HistoricalAttributionException('not_active', 'The active Batch 3 audit is no longer present.');
                    }
                    if ((string) $audit->evidence !== $evidence || (int) $audit->agency_user_id !== (int) $target['agency_user_id']) {
                        throw new HistoricalAttributionException('evidence_mismatch', 'The active audit is not the approved Batch 3 evidence.');
                    }
                    $agency = User::query()->find((int) $target['agency_user_id']);
                    if (! $agency instanceof User) {
                        throw new HistoricalAttributionException('agency_missing', 'The agency is missing.');
                    }
                    $this->attributions->reverse($reservation, $agency, $actor, $reversalEvidence);
                });
            } catch (Throwable $e) {
                $stopped = true;
                $stopReason = $e instanceof HistoricalAttributionException ? $e->reason : 'unexpected_failure';
                $rows[] = [
                    'reservation_id' => $target['reservation_id'],
                    'outcome' => 'stopped',
                    'reason' => $stopReason,
                ];
                break;
            }
            $reversed++;
            $rows[] = [
                'reservation_id' => $target['reservation_id'],
                'outcome' => 'reversed',
                'reason' => $evidence,
            ];
        }

        return [
            'reversed' => $reversed,
            'reversible' => $plan['reversible'],
            'stopped' => $stopped,
            'stop_reason' => $stopReason,
            'rows' => $rows,
        ];
    }

    /**
     * @return array{record: array<string, mixed>, rows: list<array{reservation_id: int, proposed_user_id: int, disposition: string, source_class: string, group_id: string, hold_reason: string}>}
     */
    private function guardWrite(string $csvPath, Admin $actor, string $confirm, string $expectedConfirm, int $limit, int $attemptLimit): array
    {
        if ($limit < 1 || $limit > HistoricalAttributionBatch3Catalog::MAX_LIMIT || $attemptLimit < 1 || $attemptLimit > HistoricalAttributionBatch3Catalog::MAX_LIMIT) {
            throw new RuntimeException('Pass --limit and --attempts as integers from 1 to '.HistoricalAttributionBatch3Catalog::MAX_LIMIT.'.');
        }
        $verified = $this->approval->verify($csvPath);
        if ($confirm !== $expectedConfirm) {
            throw new RuntimeException('Refusing to write. Pass --confirm='.$expectedConfirm.' exactly.');
        }
        if ((int) ($verified['record']['admin_id'] ?? 0) !== (int) $actor->id || $actor->admin_access !== true) {
            throw new RuntimeException('Refusing to write. The admin id does not match the approval record.');
        }

        return $verified;
    }

    /**
     * @param  array{reservation_id: int, proposed_user_id: int, disposition: string, source_class: string, group_id: string, hold_reason: string}  $row
     * @return array{row: array{reservation_id: string, proposed_user_id: string, disposition: string, group_id: string, outcome: string, reason: string, evidence: string}, expensive: bool}
     */
    private function attributeOne(array $row, Admin $actor, string $kind, string $evidence, int $wrote, int $limit, int $attempts, int $attemptLimit): array
    {
        try {
            return DB::transaction(function () use ($row, $actor, $kind, $evidence, $wrote, $limit, $attempts, $attemptLimit): array {
                $reservation = Reservation::query()->whereKey($row['reservation_id'])->lockForUpdate()->first();
                $audits = DB::table('reservation_attributions')->where('reservation_id', $row['reservation_id'])->lockForUpdate()->get()->all();
                $prefix = $this->prefix($row, $reservation, $audits);
                if ($prefix !== null) {
                    return ['row' => $prefix, 'expensive' => false];
                }
                if ($wrote >= $limit) {
                    return ['row' => $this->line($row, 'beyond_limit', 'limit', ''), 'expensive' => false];
                }
                if ($attempts >= $attemptLimit) {
                    return ['row' => $this->line($row, 'beyond_attempts', 'attempt_limit', ''), 'expensive' => false];
                }
                if (! $reservation instanceof Reservation) {
                    return ['row' => $this->line($row, 'stopped', 'reservation_missing', ''), 'expensive' => false];
                }
                $snapshot = app(HistoricalAttributionBatch2DryRun::class)->currentIdentitySnapshot();
                $decision = $this->dryRun->classifyRow($reservation, $audits, $snapshot);
                if ($decision['class'] !== HistoricalAttributionBatch3DryRun::CANDIDATE || (int) $decision['name_owner_user_id'] !== $row['proposed_user_id']) {
                    return ['row' => $this->line($row, 'rejected', 'no_longer_candidate:'.$decision['class'], ''), 'expensive' => true];
                }
                $scope = $this->scopeVerdict($row, $kind);
                if ($scope !== null) {
                    return ['row' => $scope, 'expensive' => true];
                }
                $agency = User::query()->find($row['proposed_user_id']);
                if (! $agency instanceof User) {
                    return ['row' => $this->line($row, 'rejected', 'agency_missing', ''), 'expensive' => true];
                }
                try {
                    $this->attributions->attribute(
                        $reservation,
                        $agency,
                        $evidence,
                        $actor,
                        function (Reservation $locked) use ($snapshot, $audits, $row, $kind): void {
                            $this->assertStillCandidate($locked, $audits, $snapshot, $row, $kind);
                        },
                    );
                } catch (HistoricalAttributionException $e) {
                    return ['row' => $this->fromException($row, $e), 'expensive' => true];
                }

                return ['row' => $this->line($row, 'written', '', $evidence), 'expensive' => true];
            });
        } catch (Throwable $e) {
            return ['row' => $this->line($row, 'stopped', 'unexpected_failure', ''), 'expensive' => true];
        }
    }

    /**
     * @param  array{reservation_id: int, proposed_user_id: int, disposition: string, source_class: string, group_id: string, hold_reason: string}  $row
     * @param  list<object>  $audits
     * @return array{reservation_id: string, proposed_user_id: string, disposition: string, group_id: string, outcome: string, reason: string, evidence: string}|null
     */
    private function prefix(array $row, ?object $reservation, array $audits): ?array
    {
        if (! $reservation instanceof Reservation) {
            return $this->line($row, 'stopped', 'reservation_missing', '');
        }
        if (! ReservationOrigin::isImportedV1($reservation->created_at)) {
            return $this->line($row, 'rejected', 'not_imported_v1', '');
        }

        $proposed = $row['proposed_user_id'];
        $userId = $reservation->user_id === null || $reservation->user_id === '' ? null : (int) $reservation->user_id;
        $active = null;
        foreach ($audits as $audit) {
            if ($audit->active_reservation_id !== null) {
                $active = $audit;
            }
        }
        if ($active !== null) {
            $evidence = (string) $active->evidence;
            $agencyId = (int) $active->agency_user_id;
            $batch3 = $evidence === HistoricalAttributionBatch3Catalog::EVIDENCE
                || $evidence === HistoricalAttributionBatch3Catalog::EVIDENCE_REVIEWED;
            if ($batch3 && $agencyId === $proposed && $userId === $proposed) {
                return $this->line($row, 'already_done', $evidence, $evidence);
            }
            if ($batch3 && $agencyId === $proposed && $userId !== $proposed) {
                return $this->line($row, 'stopped', 'audit_user_disagreement', $evidence);
            }

            return $this->line($row, 'rejected', 'active_audit_conflict', '');
        }
        if ($userId !== null) {
            return $this->line($row, 'rejected', 'user_id_without_batch3_audit', '');
        }
        $closed = $this->closedReason($audits);
        if ($closed === 'closed_batch3_audit') {
            return $this->line($row, 'manual_review', 'closed_batch3_audit', '');
        }
        if ($closed !== null) {
            return $this->line($row, 'rejected', $closed, '');
        }

        return null;
    }

    /**
     * @param  array{reservation_id: int, proposed_user_id: int, disposition: string, source_class: string, group_id: string, hold_reason: string}  $row
     * @param  list<object>  $audits
     * @return array{reservation_id: string, proposed_user_id: string, disposition: string, group_id: string, outcome: string, reason: string, evidence: string}
     */
    private function identityVerdict(array $row, ?object $reservation, array $audits, string $kind): array
    {
        if (! $reservation instanceof Reservation) {
            return $this->line($row, 'stopped', 'reservation_missing', '');
        }
        $snapshot = app(HistoricalAttributionBatch2DryRun::class)->currentIdentitySnapshot();
        $decision = $this->dryRun->classifyRow($reservation, $audits, $snapshot);
        if ($decision['class'] !== HistoricalAttributionBatch3DryRun::CANDIDATE || (int) $decision['name_owner_user_id'] !== $row['proposed_user_id']) {
            return $this->line($row, 'rejected', 'no_longer_candidate:'.$decision['class'], '');
        }
        $scope = $this->scopeVerdict($row, $kind);
        if ($scope !== null) {
            return $scope;
        }

        return $this->line($row, 'written', '', '');
    }

    /**
     * @param  array{reservation_id: int, proposed_user_id: int, disposition: string, source_class: string, group_id: string, hold_reason: string}  $row
     * @return array{reservation_id: string, proposed_user_id: string, disposition: string, group_id: string, outcome: string, reason: string, evidence: string}|null
     */
    private function scopeVerdict(array $row, string $kind): ?array
    {
        $hold = HistoricalAttributionBatch3Catalog::isHoldAgency($row['proposed_user_id']);
        if ($kind === 'ordinary' && $hold) {
            return $this->line($row, 'manual_review', 'ambiguous_agency_name', '');
        }
        if ($kind === 'review' && ! $hold) {
            return $this->line($row, 'rejected', 'review_scope_mismatch', '');
        }

        return null;
    }

    /**
     * @param  list<object>  $audits
     * @param  array{emails: array<string, list<int>>, names: array<string, list<int>>, plates: array<string, list<int>>}  $snapshot
     * @param  array{reservation_id: int, proposed_user_id: int, disposition: string, source_class: string, group_id: string, hold_reason: string}  $row
     */
    private function assertStillCandidate(Reservation $locked, array $audits, array $snapshot, array $row, string $kind): void
    {
        $decision = $this->dryRun->classifyRow($locked, $audits, $snapshot);
        if ($decision['class'] !== HistoricalAttributionBatch3DryRun::CANDIDATE || (int) $decision['name_owner_user_id'] !== $row['proposed_user_id']) {
            throw new HistoricalAttributionException('no_longer_candidate:'.$decision['class'], 'The reservation is no longer a class-8 candidate.');
        }
        $hold = HistoricalAttributionBatch3Catalog::isHoldAgency($row['proposed_user_id']);
        if ($kind === 'ordinary' && $hold) {
            throw new HistoricalAttributionException('ambiguous_agency_name', 'This agency is held for manual review.');
        }
        if ($kind === 'review' && ! $hold) {
            throw new HistoricalAttributionException('review_scope_mismatch', 'Reviewed approval cannot write an ordinary candidate.');
        }
    }

    /**
     * @param  array{reservation_id: int, proposed_user_id: int, disposition: string, source_class: string, group_id: string, hold_reason: string}  $row
     * @return array{reservation_id: string, proposed_user_id: string, disposition: string, group_id: string, outcome: string, reason: string, evidence: string}
     */
    private function fromException(array $row, HistoricalAttributionException $exception): array
    {
        if ($exception->reason === 'ambiguous_agency_name') {
            return $this->line($row, 'manual_review', $exception->reason, '');
        }
        if (str_starts_with($exception->reason, 'no_longer_candidate')) {
            return $this->line($row, 'rejected', $exception->reason, '');
        }

        return $this->line($row, 'rejected', $exception->reason, '');
    }

    /**
     * @param  list<object>  $audits
     */
    private function closedReason(array $audits): ?string
    {
        $any = false;
        $batch3 = false;
        $batch2 = false;
        $deleted = false;
        $batch1 = false;
        $batch2Evidence = array_values(HistoricalAttributionBatch2Preflight::EVIDENCE_BY_CATEGORY);
        foreach ($audits as $audit) {
            if ($audit->active_reservation_id !== null) {
                continue;
            }
            $any = true;
            $evidence = (string) $audit->evidence;
            if ($evidence === HistoricalAttributionBatch3Catalog::EVIDENCE || $evidence === HistoricalAttributionBatch3Catalog::EVIDENCE_REVIEWED) {
                $batch3 = true;
            }
            if (in_array($evidence, $batch2Evidence, true)) {
                $batch2 = true;
            }
            if ((string) ($audit->reversal_evidence ?? '') === HistoricalReservationAttributionService::ACCOUNT_DELETED_EVIDENCE) {
                $deleted = true;
            }
            if ($evidence === HistoricalAttributionBatchWriter::EVIDENCE) {
                $batch1 = true;
            }
        }
        if (! $any) {
            return null;
        }
        if ($batch3) {
            return 'closed_batch3_audit';
        }
        if ($batch2) {
            return 'closed_batch2_audit';
        }
        if ($deleted) {
            return 'closed_account_deleted';
        }
        if ($batch1) {
            return 'closed_batch1_audit';
        }

        return 'other_closed_audit';
    }

    /**
     * @param  list<array{reservation_id: int, proposed_user_id: int, disposition: string, source_class: string, group_id: string, hold_reason: string}>  $rows
     * @return array{reversible: int, rows: list<array{reservation_id: string, outcome: string, reason: string}>, targets: list<array{reservation_id: string, outcome: string, reason: string, agency_user_id: int}>}
     */
    private function rollbackPlan(array $rows, string $evidence): array
    {
        $ids = array_map(static fn (array $row): int => $row['reservation_id'], $rows);
        $activeByReservation = [];
        foreach (array_chunk($ids, 500) as $chunk) {
            $found = DB::table('reservation_attributions')
                ->whereIn('reservation_id', $chunk)
                ->whereNotNull('active_reservation_id')
                ->get();
            foreach ($found as $audit) {
                $activeByReservation[(int) $audit->reservation_id] = $audit;
            }
        }
        $targets = [];
        $preview = [];
        $reversible = 0;
        foreach ($rows as $row) {
            $audit = $activeByReservation[$row['reservation_id']] ?? null;
            if ($audit === null) {
                $targets[] = ['reservation_id' => (string) $row['reservation_id'], 'outcome' => 'not_reversed', 'reason' => 'not_active', 'agency_user_id' => 0];
                $preview[] = ['reservation_id' => (string) $row['reservation_id'], 'outcome' => 'not_reversed', 'reason' => 'not_active'];
                continue;
            }
            if ((string) $audit->evidence !== $evidence) {
                $targets[] = ['reservation_id' => (string) $row['reservation_id'], 'outcome' => 'not_reversed', 'reason' => 'evidence_mismatch', 'agency_user_id' => 0];
                $preview[] = ['reservation_id' => (string) $row['reservation_id'], 'outcome' => 'not_reversed', 'reason' => 'evidence_mismatch'];
                continue;
            }
            if ((int) $audit->agency_user_id !== $row['proposed_user_id']) {
                $targets[] = ['reservation_id' => (string) $row['reservation_id'], 'outcome' => 'not_reversed', 'reason' => 'agency_mismatch', 'agency_user_id' => 0];
                $preview[] = ['reservation_id' => (string) $row['reservation_id'], 'outcome' => 'not_reversed', 'reason' => 'agency_mismatch'];
                continue;
            }
            $reversible++;
            $targets[] = [
                'reservation_id' => (string) $row['reservation_id'],
                'outcome' => 'reversible',
                'reason' => $evidence,
                'agency_user_id' => $row['proposed_user_id'],
            ];
            $preview[] = ['reservation_id' => (string) $row['reservation_id'], 'outcome' => 'reversible', 'reason' => $evidence];
        }

        return [
            'reversible' => $reversible,
            'rows' => $preview,
            'targets' => $targets,
        ];
    }

    private function count(string $outcome, int &$rejected, int &$alreadyDone, int &$manualReview): void
    {
        if ($outcome === 'already_done') {
            $alreadyDone++;

            return;
        }
        if ($outcome === 'manual_review') {
            $manualReview++;

            return;
        }
        if ($outcome === 'accepted' || $outcome === 'written' || $outcome === 'beyond_limit' || $outcome === 'beyond_attempts') {
            return;
        }
        $rejected++;
    }

    /**
     * @param  array{reservation_id: int, proposed_user_id: int, disposition: string, source_class: string, group_id: string, hold_reason: string}  $row
     * @return array{reservation_id: string, proposed_user_id: string, disposition: string, group_id: string, outcome: string, reason: string, evidence: string}
     */
    private function line(array $row, string $outcome, string $reason, string $evidence): array
    {
        return [
            'reservation_id' => (string) $row['reservation_id'],
            'proposed_user_id' => (string) $row['proposed_user_id'],
            'disposition' => $row['disposition'],
            'group_id' => $row['group_id'],
            'outcome' => $outcome,
            'reason' => $reason,
            'evidence' => $evidence,
        ];
    }
}
