<?php

namespace App\Services\AdminPanel\Reservation;

use App\Models\Reservation;
use App\Models\VehicleType;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

final class AdminDailyTicketUpdateService
{
    /**
     * @param  array{
     *   reservation_date:string,
     *   user_name:string,
     *   country:string,
     *   license_plate:string,
     *   vehicle_type_id:int,
     *   email:string,
     * }  $data
     * @return list<string>
     */
    public function apply(Reservation $reservation, array $data): array
    {
        if (! $reservation->isDailyTicket()) {
            throw new \RuntimeException('Rezervacija nije dnevna karta.');
        }

        $newVtId = (int) $data['vehicle_type_id'];
        $vt = VehicleType::query()->findOrFail($newVtId);
        $currentVt = $reservation->vehicleType;
        $currentPrice = $currentVt !== null ? (float) $currentVt->price : 0.0;
        if ((float) $vt->price > $currentPrice) {
            throw new \RuntimeException('Nije dozvoljena veća kategorija vozila od trenutne.');
        }

        $changedFields = [];

        DB::transaction(function () use ($reservation, $data, $newVtId, &$changedFields): void {
            /** @var Reservation|null $r */
            $r = Reservation::query()->whereKey($reservation->id)->lockForUpdate()->first();
            if (! $r || ! $r->isDailyTicket()) {
                throw new \RuntimeException('Rezervacija nije pronađena.');
            }

            $changedFields = AdminReservationFieldChangeTracker::diff($r, $data, includeSlots: false);

            $r->update([
                'reservation_date' => $data['reservation_date'],
                'user_name' => $data['user_name'],
                'country' => $data['country'],
                'license_plate' => $data['license_plate'],
                'vehicle_type_id' => $newVtId,
                'email' => $data['email'],
                'invoice_sent_at' => null,
                'email_sent' => Reservation::EMAIL_NOT_SENT,
            ]);

            Log::channel('payments')->info('admin_panel_daily_ticket_updated', [
                'reservation_id' => $r->id,
                'merchant_transaction_id' => $r->merchant_transaction_id,
                'changed_fields' => $changedFields,
            ]);
        });

        return $changedFields;
    }
}
