<?php

namespace App\Services\Reservation;

use App\Exceptions\HistoricalAttributionException;
use App\Models\Admin;
use App\Models\Reservation;
use App\Models\ReservationAttribution;
use App\Models\User;
use App\Support\ReservationOrigin;
use Closure;
use Illuminate\Support\Facades\DB;

/**
 * Controlled attribution of an imported V1 reservation to one agency.
 *
 * Not invoked by any route or scheduler. A production write still requires
 * a separate explicit authorization and an approved reservation id list.
 * This service does not decide that a match is reliable.
 */
final class HistoricalReservationAttributionService
{
    /**
     * Written when an agency account is deleted and its active attribution is closed.
     * There is no admin actor for that closure.
     */
    public const ACCOUNT_DELETED_EVIDENCE = 'agency_account_deleted';

    public function attribute(
        Reservation $reservation,
        User $agency,
        string $evidence,
        Admin $actor,
        ?Closure $assertLocked = null,
    ): ReservationAttribution {
        $evidence = trim($evidence);
        if ($evidence === '') {
            throw new HistoricalAttributionException(
                HistoricalAttributionException::EVIDENCE_REQUIRED,
                'Attribution evidence is required.',
            );
        }

        return DB::transaction(function () use ($reservation, $agency, $evidence, $actor, $assertLocked): ReservationAttribution {
            $locked = Reservation::query()->whereKey($reservation->id)->lockForUpdate()->firstOrFail();
            $this->assertImportedV1($locked);

            if ($locked->user_id !== null || $this->activeFor($locked) !== null) {
                throw new HistoricalAttributionException(
                    HistoricalAttributionException::ALREADY_ATTRIBUTED,
                    'Reservation already has an agency attribution.',
                );
            }

            if ($assertLocked !== null) {
                $assertLocked($locked);
            }

            $previousUserId = $locked->user_id;

            $audit = ReservationAttribution::query()->create([
                'reservation_id' => $locked->id,
                'active_reservation_id' => $locked->id,
                'agency_user_id' => $agency->id,
                'previous_user_id' => $previousUserId,
                'evidence' => $evidence,
                'attributed_at' => now(),
                'attributed_by_admin_id' => $actor->id,
            ]);

            DB::table('reservations')->where('id', $locked->id)->update([
                'user_id' => $agency->id,
            ]);

            return $audit;
        });
    }

    public function reverse(Reservation $reservation, User $expectedAgency, Admin $actor, string $evidence): ReservationAttribution
    {
        $evidence = trim($evidence);
        if ($evidence === '') {
            throw new HistoricalAttributionException(
                HistoricalAttributionException::EVIDENCE_REQUIRED,
                'Reversal evidence is required.',
            );
        }

        return DB::transaction(function () use ($reservation, $expectedAgency, $actor, $evidence): ReservationAttribution {
            $locked = Reservation::query()->whereKey($reservation->id)->lockForUpdate()->firstOrFail();
            $this->assertImportedV1($locked);

            if ($locked->user_id === null) {
                throw new HistoricalAttributionException(
                    HistoricalAttributionException::NOT_ATTRIBUTED,
                    'Reservation has no agency attribution to reverse.',
                );
            }

            if ((int) $locked->user_id !== (int) $expectedAgency->id) {
                throw new HistoricalAttributionException(
                    HistoricalAttributionException::ATTRIBUTION_MISMATCH,
                    'Current attribution does not match the expected agency.',
                );
            }

            $audit = ReservationAttribution::query()
                ->where('active_reservation_id', $locked->id)
                ->where('agency_user_id', $expectedAgency->id)
                ->lockForUpdate()
                ->first();

            if ($audit === null) {
                throw new HistoricalAttributionException(
                    HistoricalAttributionException::AUDIT_MISSING,
                    'No active attribution audit row matches this reservation.',
                );
            }

            $audit->forceFill([
                'active_reservation_id' => null,
                'reversed_at' => now(),
                'reversed_by_admin_id' => $actor->id,
                'reversal_evidence' => $evidence,
            ])->save();

            DB::table('reservations')->where('id', $locked->id)->update([
                'user_id' => null,
            ]);

            return $audit->refresh();
        });
    }

    /**
     * Close active attribution rows after the agency account has been deleted.
     *
     * Called from the User deleted event, so a failed delete does not run it.
     * ProfileController::destroy wraps that delete in one transaction, so a failure
     * here rolls the account deletion back with this cleanup. A nested call joins
     * that transaction. The audit row and agency_user_id stay. On MySQL,
     * reservations.user_id is already null via ON DELETE SET NULL inside the same
     * transaction. Where that link is still present, clear only that column on the
     * reservations covered by these active rows.
     */
    public function releaseAfterAgencyAccountDeleted(int $agencyUserId): void
    {
        DB::transaction(function () use ($agencyUserId): void {
            $audits = ReservationAttribution::query()
                ->where('agency_user_id', $agencyUserId)
                ->whereNull('reversed_at')
                ->lockForUpdate()
                ->get();

            foreach ($audits as $audit) {
                DB::table('reservations')
                    ->where('id', $audit->reservation_id)
                    ->where('user_id', $agencyUserId)
                    ->update(['user_id' => null]);

                $audit->forceFill([
                    'active_reservation_id' => null,
                    'reversed_at' => now(),
                    'reversed_by_admin_id' => null,
                    'reversal_evidence' => self::ACCOUNT_DELETED_EVIDENCE,
                ])->save();
            }
        });
    }

    private function assertImportedV1(Reservation $reservation): void
    {
        if (! ReservationOrigin::isImportedV1($reservation->created_at)) {
            throw new HistoricalAttributionException(
                HistoricalAttributionException::NOT_IMPORTED_V1,
                'Only an imported V1 reservation can be attributed.',
            );
        }
    }

    private function activeFor(Reservation $reservation): ?ReservationAttribution
    {
        return ReservationAttribution::query()
            ->where('active_reservation_id', $reservation->id)
            ->first();
    }
}
