<?php

namespace App\Support;

use App\Models\Reservation;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Builder;

/**
 * Imported V1 cohort versus native V2.
 *
 * Origin is the stored reservations.created_at, compared to the production
 * boundary verified by the product owner. It does not change when user_id is set.
 * user_id is current attribution, not evidence of a V2 authenticated session.
 */
final class ReservationOrigin
{
    /**
     * Stored datetime literal. Imported V1 rows satisfy created_at < this value.
     * V2 guest reservations begin at this instant. The systems were not active together.
     */
    public const IMPORTED_BEFORE = '2026-06-18 00:00:00';

    public static function isImportedV1(mixed $createdAt): bool
    {
        $stored = self::storedDateTime($createdAt);

        return $stored !== null && $stored < self::IMPORTED_BEFORE;
    }

    public static function adminLabel(Reservation $reservation): string
    {
        $imported = self::isImportedV1($reservation->created_at);
        $attributed = $reservation->user_id !== null;

        return match (true) {
            $imported && $attributed => 'V1 istorijska, pripisana agenciji',
            $imported => 'V1 istorijska, nije pripisana',
            $attributed => 'V2 agencijska',
            default => 'V2 gost',
        };
    }

    /**
     * @param  Builder<Reservation>  $query
     */
    public static function scopeImportedV1(Builder $query): void
    {
        $query->where($query->getModel()->getTable().'.created_at', '<', self::IMPORTED_BEFORE);
    }

    /**
     * @param  Builder<Reservation>  $query
     */
    public static function scopeNativeV2(Builder $query): void
    {
        $table = $query->getModel()->getTable();
        $query->where(function (Builder $inner) use ($table): void {
            $inner->where($table.'.created_at', '>=', self::IMPORTED_BEFORE)
                ->orWhereNull($table.'.created_at');
        });
    }

    private static function storedDateTime(mixed $createdAt): ?string
    {
        if ($createdAt === null || $createdAt === '') {
            return null;
        }

        if ($createdAt instanceof CarbonInterface) {
            return $createdAt->format('Y-m-d H:i:s');
        }

        return (string) $createdAt;
    }
}
