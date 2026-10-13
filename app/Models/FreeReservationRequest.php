<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class FreeReservationRequest extends Model
{
    public const STATUS_SUBMITTED = 'submitted';

    public const STATUS_UPDATED = 'updated';

    public const STATUS_FULFILLED = 'fulfilled';

    public const STATUS_REJECTED = 'rejected';

    protected $fillable = [
        'user_id',
        'locale',
        'institution_name',
        'institution_email',
        'institution_phone',
        'reservation_date',
        'drop_off_time_slot_id',
        'pick_up_time_slot_id',
        'country',
        'status',
        'rejection_reason',
        'rejected_by_admin_id',
        'rejected_at',
        'rejection_notification_sent_at',
    ];

    protected function casts(): array
    {
        return [
            'reservation_date' => 'date',
            'rejected_by_admin_id' => 'integer',
            'rejected_at' => 'datetime',
            'rejection_notification_sent_at' => 'datetime',
        ];
    }

    public function canBeRejected(): bool
    {
        return in_array($this->status, [
            self::STATUS_SUBMITTED,
            self::STATUS_UPDATED,
        ], true);
    }

    public function rejectedByAdmin(): BelongsTo
    {
        return $this->belongsTo(Admin::class, 'rejected_by_admin_id');
    }

    public function vehicles(): HasMany
    {
        return $this->hasMany(FreeReservationRequestVehicle::class, 'request_id');
    }

    public function segments(): HasMany
    {
        return $this->hasMany(FreeReservationRequestSegment::class, 'request_id')->orderBy('position');
    }

    public function attachments(): HasMany
    {
        return $this->hasMany(FreeReservationRequestAttachment::class, 'request_id');
    }

    public function dropOffTimeSlot(): BelongsTo
    {
        return $this->belongsTo(ListOfTimeSlot::class, 'drop_off_time_slot_id');
    }

    public function pickUpTimeSlot(): BelongsTo
    {
        return $this->belongsTo(ListOfTimeSlot::class, 'pick_up_time_slot_id');
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    public function reservations(): HasMany
    {
        return $this->hasMany(Reservation::class, 'free_reservation_request_id');
    }
}
