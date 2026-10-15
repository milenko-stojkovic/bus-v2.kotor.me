<?php

namespace App\Services\Vehicle;

use App\Support\UiText;
use RuntimeException;

final class VehiclePlateCategoryConsistencyException extends RuntimeException
{
    public const CONFLICT = 'vehicle_plate_category_conflict_blocked';

    public const MISMATCH = 'vehicle_plate_category_mismatch_blocked';

    public function __construct(public readonly string $messageKey)
    {
        parent::__construct($messageKey);
    }

    public function userMessage(string $locale): string
    {
        $cg = $locale !== 'en';

        $fallback = match ($this->messageKey) {
            self::CONFLICT => $cg
                ? 'Ova registarska tablica već ima neusaglašene kategorije vozila. Novo vozilo sa ovom tablicom trenutno nije moguće dodati. Pišite na bus@kotor.me.'
                : 'This registration plate already has conflicting vehicle categories. A new vehicle with this plate cannot be added right now. Write to bus@kotor.me.',
            default => $cg
                ? 'Ova registarska tablica je već evidentirana u drugoj kategoriji vozila. Nije je moguće dodati u drugoj kategoriji. Pišite na bus@kotor.me.'
                : 'This registration plate is already recorded in a different vehicle category. It cannot be added under another category. Write to bus@kotor.me.',
        };

        return UiText::t('panel', $this->messageKey, $fallback, $cg ? 'cg' : 'en');
    }
}
