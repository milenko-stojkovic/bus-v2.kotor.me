<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PriceChangeScheduleLine extends Model
{
    protected $fillable = [
        'price_change_schedule_id',
        'vehicle_type_id',
        'price_before',
        'price_after',
    ];

    protected function casts(): array
    {
        return [
            'price_before' => 'decimal:2',
            'price_after' => 'decimal:2',
        ];
    }

    public function schedule(): BelongsTo
    {
        return $this->belongsTo(PriceChangeSchedule::class, 'price_change_schedule_id');
    }

    public function vehicleType(): BelongsTo
    {
        return $this->belongsTo(VehicleType::class, 'vehicle_type_id');
    }
}
