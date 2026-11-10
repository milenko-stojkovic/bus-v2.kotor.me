<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Append-only attribution history for one reservation.
 * Agency and admin ids are stored without foreign keys so deleting an account
 * does not remove the audit row.
 */
class ReservationAttribution extends Model
{
    protected $fillable = [
        'reservation_id',
        'active_reservation_id',
        'agency_user_id',
        'previous_user_id',
        'evidence',
        'attributed_at',
        'attributed_by_admin_id',
        'reversed_at',
        'reversed_by_admin_id',
        'reversal_evidence',
    ];

    protected function casts(): array
    {
        return [
            'attributed_at' => 'datetime',
            'reversed_at' => 'datetime',
            'reservation_id' => 'integer',
            'active_reservation_id' => 'integer',
            'agency_user_id' => 'integer',
            'previous_user_id' => 'integer',
            'attributed_by_admin_id' => 'integer',
            'reversed_by_admin_id' => 'integer',
        ];
    }

    public function reservation(): BelongsTo
    {
        return $this->belongsTo(Reservation::class);
    }

    public function isActive(): bool
    {
        return $this->reversed_at === null;
    }
}
