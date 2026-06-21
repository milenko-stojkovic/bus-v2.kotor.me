<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Collection;

final class VehicleCategoryChangeRequest extends Model
{
    public const STATUS_PENDING = 'pending';
    public const STATUS_APPROVED = 'approved';
    public const STATUS_REJECTED = 'rejected';

    protected $table = 'vehicle_category_change_requests';

    protected $fillable = [
        'user_id',
        'old_vehicle_id',
        'license_plate',
        'old_vehicle_type_id',
        'requested_vehicle_type_id',
        'status',
        'document_original_name',
        'document_path',
        'document_mime_type',
        'document_size_bytes',
        'locale',
        'reviewed_by_admin_id',
        'reviewed_at',
        'rejection_reason',
        'approved_notification_sent_at',
        'rejected_notification_sent_at',
    ];

    protected function casts(): array
    {
        return [
            'user_id' => 'integer',
            'old_vehicle_id' => 'integer',
            'old_vehicle_type_id' => 'integer',
            'requested_vehicle_type_id' => 'integer',
            'document_size_bytes' => 'integer',
            'reviewed_by_admin_id' => 'integer',
            'reviewed_at' => 'datetime',
            'approved_notification_sent_at' => 'datetime',
            'rejected_notification_sent_at' => 'datetime',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    public function oldVehicle(): BelongsTo
    {
        return $this->belongsTo(Vehicle::class, 'old_vehicle_id');
    }

    public function oldVehicleType(): BelongsTo
    {
        return $this->belongsTo(VehicleType::class, 'old_vehicle_type_id');
    }

    public function requestedVehicleType(): BelongsTo
    {
        return $this->belongsTo(VehicleType::class, 'requested_vehicle_type_id');
    }

    public function attachments(): HasMany
    {
        return $this->hasMany(VehicleCategoryChangeRequestAttachment::class, 'vehicle_category_change_request_id')
            ->orderBy('id');
    }

    /**
     * Attachments for admin preview; falls back to legacy document_path when no rows exist.
     *
     * @return Collection<int, VehicleCategoryChangeRequestAttachment|object>
     */
    public function previewAttachments(): Collection
    {
        $attachments = $this->relationLoaded('attachments')
            ? $this->attachments
            : $this->attachments()->get();

        if ($attachments->isNotEmpty()) {
            return $attachments;
        }

        $path = (string) $this->document_path;
        if ($path === '' || $path === 'tmp') {
            return collect();
        }

        return collect([(object) [
            'id' => null,
            'original_name' => $this->document_original_name ?: 'document',
            'legacy' => true,
        ]]);
    }

    public function hasLegacyOnlyDocument(): bool
    {
        return $this->attachments()->count() === 0
            && ($path = (string) $this->document_path) !== ''
            && $path !== 'tmp';
    }
}

