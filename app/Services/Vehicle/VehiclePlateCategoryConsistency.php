<?php

namespace App\Services\Vehicle;

use App\Models\Vehicle;
use App\Services\Reservation\DuplicateReservationAttemptService;
use Illuminate\Support\Collection;

/**
 * Category identity for a plate is the canonical booking normalizer.
 * Stored plates are compared as already saved; this class does not rewrite them.
 * Lookup uses the normalized value, which matches plates stored by the vehicle form.
 */
final class VehiclePlateCategoryConsistency
{
    public static function normalize(?string $plate): string
    {
        return DuplicateReservationAttemptService::normalizeLicensePlate($plate);
    }

    /**
     * @return Collection<int, Vehicle>
     */
    public function lockedRows(string $normalizedPlate): Collection
    {
        return Vehicle::query()
            ->where('license_plate', $normalizedPlate)
            ->lockForUpdate()
            ->get();
    }

    /**
     * @param  Collection<int, Vehicle>  $rows
     */
    public function decideAdd(Collection $rows, int $userId, int $requestedTypeId): VehiclePlateCategoryDecision
    {
        if ($this->isConflicted($rows)) {
            throw new VehiclePlateCategoryConsistencyException(VehiclePlateCategoryConsistencyException::CONFLICT);
        }

        $recordedTypeId = $this->singleRecordedTypeId($rows);
        $ownRemoved = $rows->first(function (Vehicle $row) use ($userId): bool {
            return (int) $row->user_id === $userId && $row->status === Vehicle::STATUS_REMOVED;
        });

        if ($recordedTypeId !== null && $requestedTypeId === $recordedTypeId && $ownRemoved instanceof Vehicle) {
            return VehiclePlateCategoryDecision::reactivate($ownRemoved);
        }

        if ($recordedTypeId !== null && $requestedTypeId !== $recordedTypeId) {
            return VehiclePlateCategoryDecision::choose(
                $recordedTypeId,
                $ownRemoved instanceof Vehicle ? $ownRemoved : null,
            );
        }

        return VehiclePlateCategoryDecision::create();
    }

    /**
     * @param  Collection<int, Vehicle>  $rows
     */
    public function isConflicted(Collection $rows): bool
    {
        return count(array_unique($rows->map(fn (Vehicle $row): int => (int) $row->vehicle_type_id)->all())) > 1;
    }

    /**
     * @param  Collection<int, Vehicle>  $rows
     */
    public function singleRecordedTypeId(Collection $rows): ?int
    {
        $types = array_values(array_unique($rows->map(fn (Vehicle $row): int => (int) $row->vehicle_type_id)->all()));

        return count($types) === 1 ? $types[0] : null;
    }

    /**
     * @param  Collection<int, Vehicle>  $rows
     */
    public function assertAssignmentKeepsOneCategory(Collection $rows, ?int $vehicleId, int $newTypeId): void
    {
        if ($this->assignmentIsConsistent($rows, $vehicleId, $newTypeId)) {
            return;
        }

        throw $this->deny($rows);
    }

    /**
     * @param  Collection<int, Vehicle>  $rows
     */
    private function assignmentIsConsistent(Collection $rows, ?int $vehicleId, int $newTypeId): bool
    {
        $types = [];
        $seen = false;
        foreach ($rows as $row) {
            if ($vehicleId !== null && (int) $row->id === $vehicleId) {
                $seen = true;
                $types[] = $newTypeId;
                continue;
            }
            $types[] = (int) $row->vehicle_type_id;
        }
        if ($vehicleId === null || ! $seen) {
            $types[] = $newTypeId;
        }

        return count(array_unique($types)) <= 1;
    }

    /**
     * @param  Collection<int, Vehicle>  $rows
     */
    private function deny(Collection $rows): VehiclePlateCategoryConsistencyException
    {
        $existing = array_unique($rows->map(fn (Vehicle $row): int => (int) $row->vehicle_type_id)->all());

        return new VehiclePlateCategoryConsistencyException(
            count($existing) > 1
                ? VehiclePlateCategoryConsistencyException::CONFLICT
                : VehiclePlateCategoryConsistencyException::MISMATCH
        );
    }
}
