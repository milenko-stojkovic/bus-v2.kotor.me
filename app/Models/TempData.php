<?php

namespace App\Models;

use App\Support\ReservationKind;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\Log;

/**
 * Privremeni podaci pre rezervacije/plaćanja – soft lock. Payment state machine.
 *
 * States: pending | processed | late_success | late_manual_review | late_rejected | canceled | expired.
 * Only API bank callbacks (via PaymentCallbackJob) and cron (ExpirePendingReservations) transition state.
 * processed = terminal (reservation created). late_success = terminal (bank SUCCESS but lock expired/canceled).
 * canceled = terminal (bank CANCEL/ERROR). expired = terminal (cron timeout). Audit trail – never delete rows.
 */
class TempData extends Model
{
    public const KIND_TIME_SLOTS = ReservationKind::TIME_SLOTS;

    public const KIND_DAILY_TICKET = ReservationKind::DAILY_TICKET;

    public const STATUS_PENDING = 'pending';
    public const STATUS_PROCESSED = 'processed';
    public const STATUS_LATE_SUCCESS = 'late_success';
    public const STATUS_LATE_MANUAL_REVIEW = 'late_manual_review';
    public const STATUS_LATE_REJECTED = 'late_rejected';
    public const STATUS_CANCELED = 'canceled';
    public const STATUS_EXPIRED = 'expired';

    public const TERMINAL_STATES = [
        self::STATUS_PROCESSED,
        self::STATUS_LATE_SUCCESS,
        self::STATUS_LATE_REJECTED,
        self::STATUS_CANCELED,
        self::STATUS_EXPIRED,
    ];

    protected $table = 'temp_data';

    protected $fillable = [
        'merchant_transaction_id',
        'reservation_kind',
        'retry_token',
        'user_id',
        'vehicle_id',
        'drop_off_time_slot_id',
        'pick_up_time_slot_id',
        'reservation_date',
        'user_name',
        'country',
        'license_plate',
        'vehicle_type_id',
        'invoice_amount_snapshot',
        'email',
        'preferred_locale',
        'status',
        'raw_callback_payload',
        'callback_error_code',
        'callback_error_reason',
        'resolution_reason',
    ];

    protected function casts(): array
    {
        return [
            'reservation_date' => 'date',
            'raw_callback_payload' => 'array',
            'invoice_amount_snapshot' => 'decimal:2',
        ];
    }

    /** Terminal = no further transitions allowed. */
    public function isTerminal(): bool
    {
        return in_array($this->status, self::TERMINAL_STATES, true);
    }

    /** Alias for isTerminal (backward compat). */
    public function isFinalStatus(): bool
    {
        return $this->isTerminal();
    }

    /** Can only transition from pending (lock still valid). */
    public function isLockValidForProcessed(): bool
    {
        return $this->status === self::STATUS_PENDING;
    }

    /** Log every state transition (payments channel). */
    public static function logStateTransition(string $merchantTransactionId, string $from, string $to, string $reason = ''): void
    {
        Log::channel('payments')->info('Payment state transition', [
            'merchant_transaction_id' => $merchantTransactionId,
            'from' => $from,
            'to' => $to,
            'reason' => $reason,
        ]);
    }

    /** FK ka User (nullable za guest). withDefault() da pristup user ne baca za null. */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class)->withDefault();
    }

    /** Da li je unos od strane gosta (bez naloga). */
    public function isGuest(): bool
    {
        return $this->user_id === null;
    }

    public function isTimeSlots(): bool
    {
        return ($this->reservation_kind ?? self::KIND_TIME_SLOTS) === self::KIND_TIME_SLOTS;
    }

    public function isDailyTicket(): bool
    {
        return $this->reservation_kind === self::KIND_DAILY_TICKET;
    }

    /**
     * Snapshot podaci za prikaz/forme (guest ili user): user_name, country, license_plate, email.
     */
    public function getSnapshotData(): array
    {
        return [
            'user_name' => $this->user_name,
            'country' => $this->country,
            'license_plate' => $this->license_plate,
            'email' => $this->email,
        ];
    }

    /** FK ka TimeSlot (list_of_time_slots) – drop-off termin. */
    public function dropOffTimeSlot(): BelongsTo
    {
        return $this->belongsTo(ListOfTimeSlot::class, 'drop_off_time_slot_id');
    }

    /** FK ka TimeSlot (list_of_time_slots) – pick-up termin. */
    public function pickUpTimeSlot(): BelongsTo
    {
        return $this->belongsTo(ListOfTimeSlot::class, 'pick_up_time_slot_id');
    }

    public function vehicleType(): BelongsTo
    {
        return $this->belongsTo(VehicleType::class, 'vehicle_type_id');
    }

    public function vehicle(): BelongsTo
    {
        return $this->belongsTo(Vehicle::class, 'vehicle_id')->withDefault();
    }
}
