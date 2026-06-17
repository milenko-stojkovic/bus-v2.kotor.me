<?php

namespace App\Services\Reservation;

use App\Models\Vehicle;
use App\Models\VehicleType;
use App\Models\VehicleTypeTranslation;
use App\Support\ReservationKind;
use Illuminate\Support\Collection;

/**
 * Booking eligibility: limo passenger categories (4+1–7+1) are Daily fee only.
 *
 * IDs are resolved from vehicle_type_translations (not hardcoded) so split types
 * in production remain covered without code changes.
 */
final class ReservationVehicleEligibilityService
{
    /** @var list<int>|null */
    private static ?array $dailyFeeOnlyIdsCache = null;

    /** @var list<int>|null */
    private static ?array $minibusEightPlusOneIdsCache = null;

    /** @var list<int>|null */
    private static ?array $controlDailyFeeListIdsCache = null;

    /**
     * Vehicle type IDs reserved for Daily fee (limo passenger categories).
     *
     * Baseline seed: vehicle_type_id = 1 (Putničko vozilo / 4+1–7+1 range).
     *
     * @return list<int>
     */
    public function dailyFeeOnlyVehicleTypeIds(): array
    {
        if (self::$dailyFeeOnlyIdsCache !== null) {
            return self::$dailyFeeOnlyIdsCache;
        }

        $matched = [];
        /** @var Collection<int, VehicleTypeTranslation> $rows */
        $rows = VehicleTypeTranslation::query()->get(['vehicle_type_id', 'name', 'description']);

        foreach ($rows as $row) {
            if ($this->translationMatchesDailyFeeOnlyCategory($row)) {
                $matched[(int) $row->vehicle_type_id] = true;
            }
        }

        $ids = array_map('intval', array_keys($matched));
        sort($ids);

        self::$dailyFeeOnlyIdsCache = $ids;

        return $ids;
    }

    /**
     * Minibus 8+1 category for Control panel daily-fee list (resolved from translations).
     *
     * Baseline seed: vehicle_type_id = 2 (Mini bus / 8+1).
     *
     * @return list<int>
     */
    public function minibusEightPlusOneVehicleTypeIds(): array
    {
        if (self::$minibusEightPlusOneIdsCache !== null) {
            return self::$minibusEightPlusOneIdsCache;
        }

        $matched = [];
        /** @var Collection<int, VehicleTypeTranslation> $rows */
        $rows = VehicleTypeTranslation::query()->get(['vehicle_type_id', 'name', 'description']);

        foreach ($rows as $row) {
            if ($this->translationMatchesMinibusEightPlusOne($row)) {
                $matched[(int) $row->vehicle_type_id] = true;
            }
        }

        $ids = array_map('intval', array_keys($matched));
        sort($ids);

        self::$minibusEightPlusOneIdsCache = $ids;

        return $ids;
    }

    /**
     * Vehicle types shown on Control → daily fee list (passenger/limo 4+1–7+1 + minibus 8+1).
     *
     * @return list<int>
     */
    public function controlDailyFeeListVehicleTypeIds(): array
    {
        if (self::$controlDailyFeeListIdsCache !== null) {
            return self::$controlDailyFeeListIdsCache;
        }

        $ids = array_values(array_unique(array_merge(
            $this->dailyFeeOnlyVehicleTypeIds(),
            $this->minibusEightPlusOneVehicleTypeIds(),
        )));
        sort($ids);

        self::$controlDailyFeeListIdsCache = $ids;

        return $ids;
    }

    public function isMinibusEightPlusOneVehicleType(int $vehicleTypeId): bool
    {
        return in_array($vehicleTypeId, $this->minibusEightPlusOneVehicleTypeIds(), true);
    }

    public function isDailyFeeOnlyVehicleType(int $vehicleTypeId): bool
    {
        return in_array($vehicleTypeId, $this->dailyFeeOnlyVehicleTypeIds(), true);
    }

    public function isVehicleTypeAllowedForKind(int $vehicleTypeId, string $reservationKind): bool
    {
        if ($reservationKind === ReservationKind::DAILY_TICKET) {
            return true;
        }

        if ($reservationKind === ReservationKind::TIME_SLOTS) {
            return ! $this->isDailyFeeOnlyVehicleType($vehicleTypeId);
        }

        return true;
    }

    /**
     * @param  Collection<int, VehicleType>  $types
     * @return Collection<int, VehicleType>
     */
    public function filterVehicleTypesForKind(Collection $types, string $reservationKind): Collection
    {
        if ($reservationKind !== ReservationKind::TIME_SLOTS) {
            return $types;
        }

        $excluded = $this->dailyFeeOnlyVehicleTypeIds();
        if ($excluded === []) {
            return $types;
        }

        return $types->reject(fn (VehicleType $vt) => in_array((int) $vt->id, $excluded, true))->values();
    }

    /**
     * @param  Collection<int, Vehicle>  $vehicles
     * @return Collection<int, Vehicle>
     */
    public function filterVehiclesForKind(Collection $vehicles, string $reservationKind): Collection
    {
        if ($reservationKind !== ReservationKind::TIME_SLOTS) {
            return $vehicles;
        }

        $excluded = $this->dailyFeeOnlyVehicleTypeIds();
        if ($excluded === []) {
            return $vehicles;
        }

        return $vehicles->reject(
            fn (Vehicle $v) => in_array((int) $v->vehicle_type_id, $excluded, true)
        )->values();
    }

    /** Clear cached IDs (tests). */
    public static function clearCache(): void
    {
        self::$dailyFeeOnlyIdsCache = null;
        self::$minibusEightPlusOneIdsCache = null;
        self::$controlDailyFeeListIdsCache = null;
    }

    private function translationMatchesDailyFeeOnlyCategory(VehicleTypeTranslation $row): bool
    {
        $name = mb_strtolower(trim($row->name));
        $desc = mb_strtolower(trim((string) $row->description));
        $blob = $name.' '.$desc;

        foreach (['4+1', '5+1', '6+1', '7+1'] as $seatLabel) {
            if (str_contains($blob, $seatLabel)) {
                return true;
            }
        }

        if (str_contains($name, 'putničko vozilo') || str_contains($name, 'personal vehicle')) {
            return true;
        }

        return false;
    }

    private function translationMatchesMinibusEightPlusOne(VehicleTypeTranslation $row): bool
    {
        $name = mb_strtolower(trim($row->name));
        $desc = mb_strtolower(trim((string) $row->description));
        $blob = $name.' '.$desc;

        if (str_contains($blob, '8+1')) {
            return true;
        }

        if (str_contains($name, 'minibus') || str_contains($name, 'mini bus')) {
            return true;
        }

        return false;
    }
}
