<?php

namespace App\Services\Vehicle;

use App\Models\Vehicle;

final class VehiclePlateCategoryDecision
{
    public const CREATE = 'create';

    public const REACTIVATE = 'reactivate';

    public const CATEGORY_CHANGE_REQUEST = 'category_change_request';

    public const CHOOSE = 'choose';

    private function __construct(
        public readonly string $action,
        public readonly ?Vehicle $ownRemoved = null,
        public readonly ?int $recordedTypeId = null,
    ) {}

    public static function create(): self
    {
        return new self(self::CREATE);
    }

    public static function reactivate(Vehicle $ownRemoved): self
    {
        return new self(self::REACTIVATE, $ownRemoved);
    }

    public static function categoryChangeRequest(Vehicle $ownRemoved): self
    {
        return new self(self::CATEGORY_CHANGE_REQUEST, $ownRemoved);
    }

    public static function choose(int $recordedTypeId, ?Vehicle $ownRemoved = null): self
    {
        return new self(self::CHOOSE, $ownRemoved, $recordedTypeId);
    }
}
