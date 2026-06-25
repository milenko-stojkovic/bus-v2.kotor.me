<?php

namespace App\Services\AdminPanel\FreeReservation;

use App\Exceptions\AmbiguousFreeReservationLinkException;
use App\Exceptions\FreeReservationLinkedToOtherRequestException;
use App\Models\FreeReservationRequest;
use App\Models\Reservation;
use App\Services\Reservation\DuplicateReservationAttemptService;
use Illuminate\Support\Collection;

/**
 * Matches admin-free reservations to free reservation request vehicle lines.
 */
final class FreeReservationRequestReservationMatcher
{
    /**
     * @return list<array{
     *     segment_id: int,
     *     date: string,
     *     drop_off_time_slot_id: int,
     *     pick_up_time_slot_id: int,
     *     license_plate: string,
     *     vehicle_type_id: int
     * }>
     */
    public function expectedLines(FreeReservationRequest $req): array
    {
        $req->loadMissing(['segments.vehicles']);
        $fallbackDate = $req->reservation_date?->toDateString() ?? '';
        $lines = [];

        foreach ($req->segments as $seg) {
            $date = $seg->reservation_date?->toDateString() ?? $fallbackDate;
            if ($date === '') {
                continue;
            }

            foreach ($seg->vehicles as $vehicle) {
                $lines[] = [
                    'segment_id' => (int) $seg->id,
                    'date' => $date,
                    'drop_off_time_slot_id' => (int) $seg->drop_off_time_slot_id,
                    'pick_up_time_slot_id' => (int) $seg->pick_up_time_slot_id,
                    'license_plate' => (string) $vehicle->license_plate,
                    'vehicle_type_id' => (int) $vehicle->vehicle_type_id,
                ];
            }
        }

        return $lines;
    }

    /**
     * @param  array{
     *     segment_id: int,
     *     date: string,
     *     drop_off_time_slot_id: int,
     *     pick_up_time_slot_id: int,
     *     license_plate: string,
     *     vehicle_type_id: int
     * }  $line
     */
    public function reservationMatchesLine(Reservation $reservation, array $line, FreeReservationRequest $req): bool
    {
        if ($reservation->status !== 'free' || ! (bool) $reservation->created_by_admin) {
            return false;
        }

        if ((string) $reservation->invoice_amount !== '0.00') {
            return false;
        }

        if (trim((string) $reservation->email) !== trim((string) $req->institution_email)) {
            return false;
        }

        if (trim((string) $reservation->user_name) !== trim((string) $req->institution_name)) {
            return false;
        }

        if (trim((string) $reservation->country) !== trim((string) $req->country)) {
            return false;
        }

        if ($reservation->reservation_date?->toDateString() !== $line['date']) {
            return false;
        }

        if ((int) $reservation->drop_off_time_slot_id !== (int) $line['drop_off_time_slot_id']) {
            return false;
        }

        if ((int) $reservation->pick_up_time_slot_id !== (int) $line['pick_up_time_slot_id']) {
            return false;
        }

        if ((int) $reservation->vehicle_type_id !== (int) $line['vehicle_type_id']) {
            return false;
        }

        return DuplicateReservationAttemptService::normalizeLicensePlate($reservation->license_plate)
            === DuplicateReservationAttemptService::normalizeLicensePlate($line['license_plate']);
    }

    public function reservationMatchesRequest(Reservation $reservation, FreeReservationRequest $req): bool
    {
        foreach ($this->expectedLines($req) as $line) {
            if ($this->reservationMatchesLine($reservation, $line, $req)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param  array{
     *     segment_id: int,
     *     date: string,
     *     drop_off_time_slot_id: int,
     *     pick_up_time_slot_id: int,
     *     license_plate: string,
     *     vehicle_type_id: int
     * }  $line
     * @param  list<int>  $exceptReservationIds
     * @return Collection<int, Reservation>
     */
    public function findCandidatesForLine(
        FreeReservationRequest $req,
        array $line,
        array $exceptReservationIds = [],
        bool $allowRelinkFromWrongRequest = false,
    ): Collection {
        $candidates = Reservation::query()
            ->where('status', 'free')
            ->where('created_by_admin', true)
            ->whereDate('reservation_date', $line['date'])
            ->where('drop_off_time_slot_id', (int) $line['drop_off_time_slot_id'])
            ->where('pick_up_time_slot_id', (int) $line['pick_up_time_slot_id'])
            ->where('email', $req->institution_email)
            ->where('user_name', $req->institution_name)
            ->where('country', $req->country)
            ->where('invoice_amount', '0.00')
            ->where('vehicle_type_id', (int) $line['vehicle_type_id'])
            ->when($exceptReservationIds !== [], fn ($q) => $q->whereNotIn('id', $exceptReservationIds))
            ->get();

        $normalizedPlate = DuplicateReservationAttemptService::normalizeLicensePlate($line['license_plate']);

        return $candidates->filter(function (Reservation $reservation) use ($req, $normalizedPlate, $allowRelinkFromWrongRequest): bool {
            if (DuplicateReservationAttemptService::normalizeLicensePlate($reservation->license_plate) !== $normalizedPlate) {
                return false;
            }

            $fk = $reservation->free_reservation_request_id;
            if ($fk === null || (int) $fk === (int) $req->id) {
                return true;
            }

            if ($allowRelinkFromWrongRequest) {
                $other = FreeReservationRequest::query()
                    ->with(['segments.vehicles'])
                    ->find((int) $fk);

                if ($other === null || ! $this->reservationMatchesRequest($reservation, $other)) {
                    return true;
                }

                return false;
            }

            return false;
        })->values();
    }

    /**
     * @return list<array{
     *     line: array,
     *     action: 'use_linked'|'link'|'create',
     *     reservation: Reservation|null
     * }>
     */
    public function buildPlan(FreeReservationRequest $req, bool $allowRelinkFromWrongRequest = false): array
    {
        $lines = $this->expectedLines($req);
        if ($lines === []) {
            return [];
        }

        $plan = [];
        $assignedReservationIds = [];

        foreach ($lines as $line) {
            $linked = Reservation::query()
                ->where('free_reservation_request_id', $req->id)
                ->get()
                ->first(fn (Reservation $r) => $this->reservationMatchesLine($r, $line, $req));

            if ($linked !== null) {
                $assignedReservationIds[] = (int) $linked->id;
                $plan[] = [
                    'line' => $line,
                    'action' => 'use_linked',
                    'reservation' => $linked,
                ];

                continue;
            }

            $candidates = $this->findCandidatesForLine(
                $req,
                $line,
                $assignedReservationIds,
                $allowRelinkFromWrongRequest,
            );

            foreach ($candidates as $candidate) {
                $fk = $candidate->free_reservation_request_id;
                if ($fk !== null && (int) $fk !== (int) $req->id) {
                    $other = FreeReservationRequest::query()
                        ->with(['segments.vehicles'])
                        ->find((int) $fk);
                    if ($other !== null && $this->reservationMatchesRequest($candidate, $other)) {
                        throw new FreeReservationLinkedToOtherRequestException((int) $fk);
                    }
                }
            }

            if ($candidates->count() > 1) {
                throw new AmbiguousFreeReservationLinkException(
                    'Pronađeno je više od jedne odgovarajuće besplatne rezervacije za tablicu '
                    .$line['license_plate'].' ('.$line['date'].').'
                );
            }

            if ($candidates->count() === 1) {
                /** @var Reservation $match */
                $match = $candidates->first();
                $assignedReservationIds[] = (int) $match->id;
                $plan[] = [
                    'line' => $line,
                    'action' => (int) ($match->free_reservation_request_id ?? 0) === (int) $req->id ? 'use_linked' : 'link',
                    'reservation' => $match,
                ];

                continue;
            }

            $plan[] = [
                'line' => $line,
                'action' => 'create',
                'reservation' => null,
            ];
        }

        return $plan;
    }
}
