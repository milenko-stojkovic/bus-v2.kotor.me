<?php

namespace App\Services\Reservation;

use App\Exceptions\HistoricalAttributionException;
use App\Models\Admin;
use App\Models\Reservation;
use App\Models\ReservationAttribution;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * Applies one approved V1 attribution batch through HistoricalReservationAttributionService.
 *
 * Preflight reuses the dry-run rules. Any blocking difference cancels the run before the first write.
 * Each attribution is its own short transaction and reads the current user and vehicle rows before it writes.
 * A later row is not started after a failure.
 */
final class HistoricalAttributionBatchWriter
{
    public const BATCH_ID = 'v1-phase2-batch1';

    public const EVIDENCE = 'v1-phase2-batch1:exact_email_unique_exact_name_exclusive_plate';

    public const ROLLBACK_EVIDENCE = 'rollback:v1-phase2-batch1';

    public const CONFIRM_APPLY = 'APPLY-V1-BATCH-1';

    public const CONFIRM_ROLLBACK = 'ROLLBACK-V1-BATCH-1';

    public function __construct(
        private readonly HistoricalAttributionBatchDryRun $dryRun,
        private readonly HistoricalReservationAttributionService $attributions,
    ) {}

    /**
     * @return array{
     *     can_write: bool,
     *     total: int,
     *     ready: list<array{reservation_id: string, proposed_user_id: string}>,
     *     already_done: list<array{reservation_id: string, proposed_user_id: string, reason: string}>,
     *     blocking: list<array{reservation_id: string, proposed_user_id: string, reason: string}>,
     *     wrote: int,
     *     stopped: bool,
     *     stop_reservation_id: ?string,
     *     stop_reason: ?string,
     *     limited: bool,
     *     remaining: int,
     *     complete: bool
     * }
     */
    public function preflight(string $csvPath): array
    {
        $dry = $this->dryRun->evaluate($csvPath);
        $alreadyDone = [];
        $blocking = [];
        $completed = $this->completedBatchReservationIds($dry['exclusions']);

        foreach ($dry['exclusions'] as $exclusion) {
            if (isset($completed[(int) $exclusion['reservation_id']])
                && (int) $completed[(int) $exclusion['reservation_id']] === (int) $exclusion['proposed_user_id']) {
                $alreadyDone[] = $exclusion;
                continue;
            }
            $blocking[] = $exclusion;
        }

        $ready = $dry['accepted_rows'];

        return [
            'can_write' => $blocking === [],
            'total' => $dry['total'],
            'ready' => $ready,
            'already_done' => $alreadyDone,
            'blocking' => $blocking,
            'wrote' => 0,
            'stopped' => false,
            'stop_reservation_id' => null,
            'stop_reason' => null,
            'limited' => false,
            'remaining' => count($ready),
            'complete' => $blocking === [] && $ready === [],
        ];
    }

    /**
     * @return array{
     *     can_write: bool,
     *     total: int,
     *     ready: list<array{reservation_id: string, proposed_user_id: string}>,
     *     already_done: list<array{reservation_id: string, proposed_user_id: string, reason: string}>,
     *     blocking: list<array{reservation_id: string, proposed_user_id: string, reason: string}>,
     *     wrote: int,
     *     stopped: bool,
     *     stop_reservation_id: ?string,
     *     stop_reason: ?string,
     *     limited: bool,
     *     remaining: int,
     *     complete: bool
     * }
     */
    public function execute(string $csvPath, Admin $actor, ?int $limit = null): array
    {
        $plan = $this->preflight($csvPath);
        if (! $plan['can_write']) {
            return $plan;
        }

        $wrote = 0;
        foreach ($plan['ready'] as $row) {
            if ($limit !== null && $wrote >= $limit) {
                return $this->limited($plan, $wrote);
            }

            $reservation = Reservation::query()->find((int) $row['reservation_id']);
            $agency = User::query()->find((int) $row['proposed_user_id']);
            if ($reservation === null || $agency === null) {
                return $this->stopped($plan, $wrote, $row['reservation_id'], HistoricalAttributionBatchDryRun::REASON_RESERVATION_MISSING);
            }

            try {
                $this->attributions->attribute(
                    $reservation,
                    $agency,
                    self::EVIDENCE,
                    $actor,
                    function (Reservation $locked) use ($row): void {
                        $reason = $this->dryRun->criterionMismatch($locked, (int) $row['proposed_user_id']);
                        if ($reason !== null) {
                            throw new HistoricalAttributionException(
                                $reason,
                                'Candidate no longer matches the approved batch criterion.',
                            );
                        }
                    },
                );
            } catch (HistoricalAttributionException $e) {
                return $this->stopped($plan, $wrote, $row['reservation_id'], $e->reason);
            }

            $wrote++;
        }

        $plan['wrote'] = $wrote;
        $plan['remaining'] = count($plan['ready']) - $wrote;
        $plan['complete'] = $plan['remaining'] === 0;

        return $plan;
    }

    /**
     * Reverse only active audit rows written with this batch's evidence.
     *
     * @return array{reversed: int, stopped: bool, stop_reservation_id: ?string, stop_reason: ?string}
     */
    public function rollback(Admin $actor): array
    {
        $audits = ReservationAttribution::query()
            ->where('evidence', self::EVIDENCE)
            ->whereNotNull('active_reservation_id')
            ->orderBy('id')
            ->get();

        $reversed = 0;
        foreach ($audits as $audit) {
            $reservation = Reservation::query()->find($audit->reservation_id);
            $agency = User::query()->find($audit->agency_user_id);
            if ($reservation === null || $agency === null) {
                return [
                    'reversed' => $reversed,
                    'stopped' => true,
                    'stop_reservation_id' => (string) $audit->reservation_id,
                    'stop_reason' => $reservation === null
                        ? HistoricalAttributionBatchDryRun::REASON_RESERVATION_MISSING
                        : HistoricalAttributionBatchDryRun::REASON_AGENCY_MISSING,
                ];
            }

            try {
                $this->attributions->reverse($reservation, $agency, $actor, self::ROLLBACK_EVIDENCE);
            } catch (HistoricalAttributionException $e) {
                return [
                    'reversed' => $reversed,
                    'stopped' => true,
                    'stop_reservation_id' => (string) $audit->reservation_id,
                    'stop_reason' => $e->reason,
                ];
            }

            $reversed++;
        }

        return [
            'reversed' => $reversed,
            'stopped' => false,
            'stop_reservation_id' => null,
            'stop_reason' => null,
        ];
    }

    /**
     * @param  list<array{reservation_id: string, proposed_user_id: string, reason: string}>  $exclusions
     * @return array<int, int>
     */
    private function completedBatchReservationIds(array $exclusions): array
    {
        $ids = [];
        foreach ($exclusions as $exclusion) {
            if ($exclusion['reason'] !== HistoricalAttributionBatchDryRun::REASON_USER_ID_NOT_NULL) {
                continue;
            }
            if (preg_match('/^[1-9][0-9]*$/', $exclusion['reservation_id']) === 1) {
                $ids[] = (int) $exclusion['reservation_id'];
            }
        }
        if ($ids === []) {
            return [];
        }

        $userIds = [];
        foreach (array_chunk($ids, 500) as $chunk) {
            $rows = DB::table('reservations')->whereIn('id', $chunk)->get(['id', 'user_id']);
            foreach ($rows as $row) {
                $userIds[(int) $row->id] = $row->user_id === null ? null : (int) $row->user_id;
            }
        }

        $completed = [];
        foreach (array_chunk($ids, 500) as $chunk) {
            $audits = DB::table('reservation_attributions')
                ->whereIn('active_reservation_id', $chunk)
                ->where('evidence', self::EVIDENCE)
                ->get(['active_reservation_id', 'agency_user_id']);
            foreach ($audits as $audit) {
                $reservationId = (int) $audit->active_reservation_id;
                $agencyId = (int) $audit->agency_user_id;
                if (($userIds[$reservationId] ?? null) === $agencyId) {
                    $completed[$reservationId] = $agencyId;
                }
            }
        }

        return $completed;
    }

    /**
     * @param  array{
     *     can_write: bool,
     *     total: int,
     *     ready: list<array{reservation_id: string, proposed_user_id: string}>,
     *     already_done: list<array{reservation_id: string, proposed_user_id: string, reason: string}>,
     *     blocking: list<array{reservation_id: string, proposed_user_id: string, reason: string}>,
     *     wrote: int,
     *     stopped: bool,
     *     stop_reservation_id: ?string,
     *     stop_reason: ?string,
     *     limited: bool,
     *     remaining: int,
     *     complete: bool
     * }  $plan
     * @return array{
     *     can_write: bool,
     *     total: int,
     *     ready: list<array{reservation_id: string, proposed_user_id: string}>,
     *     already_done: list<array{reservation_id: string, proposed_user_id: string, reason: string}>,
     *     blocking: list<array{reservation_id: string, proposed_user_id: string, reason: string}>,
     *     wrote: int,
     *     stopped: bool,
     *     stop_reservation_id: ?string,
     *     stop_reason: ?string,
     *     limited: bool,
     *     remaining: int,
     *     complete: bool
     * }
     */
    private function stopped(array $plan, int $wrote, string $reservationId, string $reason): array
    {
        $plan['wrote'] = $wrote;
        $plan['stopped'] = true;
        $plan['stop_reservation_id'] = $reservationId;
        $plan['stop_reason'] = $reason;
        $plan['limited'] = false;
        $plan['remaining'] = count($plan['ready']) - $wrote;
        $plan['complete'] = false;

        return $plan;
    }

    /**
     * @param  array{
     *     can_write: bool,
     *     total: int,
     *     ready: list<array{reservation_id: string, proposed_user_id: string}>,
     *     already_done: list<array{reservation_id: string, proposed_user_id: string, reason: string}>,
     *     blocking: list<array{reservation_id: string, proposed_user_id: string, reason: string}>,
     *     wrote: int,
     *     stopped: bool,
     *     stop_reservation_id: ?string,
     *     stop_reason: ?string,
     *     limited: bool,
     *     remaining: int,
     *     complete: bool
     * }  $plan
     * @return array{
     *     can_write: bool,
     *     total: int,
     *     ready: list<array{reservation_id: string, proposed_user_id: string}>,
     *     already_done: list<array{reservation_id: string, proposed_user_id: string, reason: string}>,
     *     blocking: list<array{reservation_id: string, proposed_user_id: string, reason: string}>,
     *     wrote: int,
     *     stopped: bool,
     *     stop_reservation_id: ?string,
     *     stop_reason: ?string,
     *     limited: bool,
     *     remaining: int,
     *     complete: bool
     * }
     */
    private function limited(array $plan, int $wrote): array
    {
        $plan['wrote'] = $wrote;
        $plan['limited'] = true;
        $plan['remaining'] = count($plan['ready']) - $wrote;
        $plan['complete'] = false;

        return $plan;
    }
}
