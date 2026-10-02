<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class BlockZoneWorklist extends Model
{
    public const STATUS_PENDING_PAYMENT = 'pending_payment';

    public const STATUS_READY_TO_ADJUST = 'ready_to_adjust';

    /** Terminal: admin confirmed reservation was honored; no slot adjustment needed. */
    public const STATUS_ACKNOWLEDGED_NO_ADJUSTMENT = 'acknowledged_no_adjustment';

    protected $table = 'block_zone_worklist';

    protected $fillable = [
        'merchant_transaction_id',
        'status',
        'old_date',
        'old_drop_off',
        'old_pick_up',
        'affected_drop_off',
        'affected_pick_up',
        'snapshot_json',
        'reservation_id',
        'temp_data_id',
        'reviewed_by_admin_id',
        'reviewed_at',
        'resolution_note',
    ];

    protected function casts(): array
    {
        return [
            'old_date' => 'date',
            'affected_drop_off' => 'boolean',
            'affected_pick_up' => 'boolean',
            'snapshot_json' => 'array',
            'reviewed_at' => 'datetime',
        ];
    }

    /**
     * Active intervention queue only (not terminal acknowledgments).
     *
     * @param  Builder<static>  $query
     * @return Builder<static>
     */
    public function scopeActiveIntervention(Builder $query): Builder
    {
        return $query->whereIn('status', [
            self::STATUS_PENDING_PAYMENT,
            self::STATUS_READY_TO_ADJUST,
        ]);
    }

    public function isAcknowledged(): bool
    {
        return $this->status === self::STATUS_ACKNOWLEDGED_NO_ADJUSTMENT;
    }

    /**
     * True when the reservation still occupies the date/slots recorded at acknowledgment time.
     */
    public function matchesAcknowledgedFingerprint(Reservation $reservation): bool
    {
        if (! $this->isAcknowledged()) {
            return false;
        }

        $date = $reservation->reservation_date instanceof \Carbon\CarbonInterface
            ? $reservation->reservation_date->toDateString()
            : (string) $reservation->reservation_date;

        return $date === $this->old_date->toDateString()
            && (int) $reservation->drop_off_time_slot_id === (int) $this->old_drop_off
            && (int) $reservation->pick_up_time_slot_id === (int) $this->old_pick_up;
    }

    public function reservation(): BelongsTo
    {
        return $this->belongsTo(Reservation::class);
    }

    public function tempData(): BelongsTo
    {
        return $this->belongsTo(TempData::class, 'temp_data_id');
    }

    public function reviewedByAdmin(): BelongsTo
    {
        return $this->belongsTo(Admin::class, 'reviewed_by_admin_id');
    }
}
