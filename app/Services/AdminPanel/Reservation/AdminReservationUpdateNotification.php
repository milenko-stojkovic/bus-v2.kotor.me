<?php

namespace App\Services\AdminPanel\Reservation;

use App\Jobs\SendAdminUpdatedReservationDocumentJob;
use App\Models\Reservation;
use Illuminate\Support\Facades\DB;

final class AdminReservationUpdateNotification
{
    /**
     * @param  list<string>  $changedFields
     */
    public static function dispatchAfterSuccessfulUpdate(
        Reservation $reservation,
        ?int $adminId,
        array $changedFields,
    ): void {
        if ($changedFields === []) {
            return;
        }

        $reservationId = $reservation->id;

        DB::afterCommit(function () use ($reservationId, $adminId, $changedFields): void {
            SendAdminUpdatedReservationDocumentJob::dispatch($reservationId, $adminId, $changedFields);
        });
    }
}
