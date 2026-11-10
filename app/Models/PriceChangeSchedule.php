<?php

namespace App\Models;

use Carbon\Carbon;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * One announced tariff change. At most one row may be scheduled.
 * Effective rows are the price history and are not edited.
 */
class PriceChangeSchedule extends Model
{
    public const STATUS_SCHEDULED = 'scheduled';

    public const STATUS_EFFECTIVE = 'effective';

    public const STATUS_CANCELLED = 'cancelled';

    public const PENDING_SLOT = 'pending';

    protected $fillable = [
        'effective_on',
        'status',
        'pending_slot',
        'applied_at',
        'cancelled_at',
        'created_by_admin_id',
        'updated_by_admin_id',
    ];

    protected function casts(): array
    {
        return [
            'effective_on' => 'date',
            'applied_at' => 'datetime',
            'cancelled_at' => 'datetime',
        ];
    }

    public function lines(): HasMany
    {
        return $this->hasMany(PriceChangeScheduleLine::class, 'price_change_schedule_id');
    }

    /**
     * 00:00 on effective_on in Europe/Podgorica, regardless of APP_TIMEZONE.
     */
    public function effectiveAt(): Carbon
    {
        $raw = (string) ($this->getRawOriginal('effective_on') ?? '');
        $date = substr($raw, 0, 10);
        $timezone = (string) config('pricing.timezone', 'Europe/Podgorica');

        return Carbon::parse($date.' 00:00:00', $timezone);
    }

    public function isScheduled(): bool
    {
        return $this->status === self::STATUS_SCHEDULED;
    }
}
