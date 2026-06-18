<?php

namespace App\Models;

use App\Support\ReservationKind;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;

/**
 * Rezervacija može biti autentifikovana (user_id set) ili guest (user_id null).
 *
 * Snapshot polja (podaci korisnika/vozila u trenutku rezervacije; ostaju i ako se kasnije promene):
 * user_name, country, license_plate, vehicle_type_id, email, invoice_amount (iznos računa u trenutku kupovine).
 *
 * email vs users.email: Ostaje duplirano. Guest nema user pa mora imati email ovde; user može kasnije promeniti
 * email u profilu – rezervacija mora imati email kakav je bio u trenutku kupovine. Nemoj uklanjati.
 *
 * vehicle_type_id vs vehicle_id: Oba polja su obavezna. Nemoj brisati vehicle_type_id čak ni kad postoji vehicle_id.
 * vehicle_type_id = snapshot tipa vozila u trenutku rezervacije (istorijski tačno ako user kasnije promeni vozilo).
 * vehicle_id = opcioni link na Vehicle; dupliranje sa vehicle_type_id je namerno.
 *
 * FK: user_id (nullable), vehicle_id (nullable). Relacije: belongsTo(User), belongsTo(Vehicle) sa withDefault();
 * vehicleType() preko snapshot vehicle_type_id.
 */
class Reservation extends Model
{
    public const KIND_TIME_SLOTS = ReservationKind::TIME_SLOTS;

    public const KIND_DAILY_TICKET = ReservationKind::DAILY_TICKET;

    /**
     * Stanje slanja email potvrde / računa (queue idempotency + stariji cron ReportEmail).
     * Ne uvoditi novu kolonu — samo ova tri stanja.
     */
    public const EMAIL_NOT_SENT = 0;

    public const EMAIL_SENT = 1;

    /** Privremeno: worker drži lock da paralelni job ne pošalje dupli mail. */
    public const EMAIL_SENDING = 2;

    /** Fillable: FK, termini, datum, snapshot polja, fiscal, status (za masovno punjenje). */
    protected $fillable = [
        'user_id',
        'free_reservation_request_id',
        'vehicle_id',
        'merchant_transaction_id',
        'reservation_kind',
        'payment_method',
        'drop_off_time_slot_id',
        'pick_up_time_slot_id',
        'reservation_date',
        'user_name',
        'country',
        'license_plate',
        'vehicle_type_id',
        'email',
        'preferred_locale',
        'fiscal_jir',
        'fiscal_ikof',
        'fiscal_qr',
        'fiscal_operator',
        'fiscal_date',
        'invoice_amount',
        'invoice_sent_at',
        'status',
        'email_sent',
        'created_by_admin',
    ];

    protected function casts(): array
    {
        return [
            'reservation_date' => 'date',
            'fiscal_date' => 'datetime',
            'invoice_sent_at' => 'datetime',
            'invoice_amount' => 'decimal:2',
            'email_sent' => 'integer', // vidi EMAIL_NOT_SENT, EMAIL_SENT, EMAIL_SENDING
            'created_by_admin' => 'boolean',
        ];
    }

    /** Označi da je potvrda rezervacije (invoice email) poslata. Idempotentno: ako je invoice_sent_at već set, ne menja se. */
    public function markConfirmationEmailSent(): bool
    {
        if ($this->invoice_sent_at !== null) {
            return true;
        }

        return $this->update([
            'email_sent' => self::EMAIL_SENT,
            'invoice_sent_at' => now(),
        ]);
    }

    /**
     * Attachment / download filename for paid invoice PDF (V1: invoice-{id}-{reservation_date}.pdf).
     */
    public function invoicePdfFilename(): string
    {
        return sprintf('invoice-%d-%s.pdf', (int) $this->id, $this->pdfFilenameDateSegment());
    }

    /**
     * Attachment / download filename for free reservation confirmation PDF.
     */
    public function freeConfirmationPdfFilename(): string
    {
        return sprintf('free-confirmation-%d-%s.pdf', (int) $this->id, $this->pdfFilenameDateSegment());
    }

    private function pdfFilenameDateSegment(): string
    {
        return $this->reservation_date?->format('Y-m-d')
            ?? $this->created_at?->format('Y-m-d')
            ?? now()->format('Y-m-d');
    }

    /**
     * Status fiskalizacije: 'completed' | 'failed' | 'pending'.
     * completed = fiscal_jir set; failed = post_fiscalization_data postoji (retry pipeline); pending = još nije fiskalizovano.
     */
    public function fiscalizationStatus(): string
    {
        if ($this->status === 'free') {
            return 'not_applicable';
        }
        if ($this->fiscal_jir !== null) {
            return 'completed';
        }
        if ($this->postFiscalizationData()->exists()) {
            return 'failed';
        }

        return 'pending';
    }

    /**
     * Scope: drop-off u istom prozoru vidljivosti kao control panel (od 3h prije početka do kraja termina).
     */
    public function scopeNextThreeHours(Builder $query): void
    {
        $tz = (string) config('reservations.operations_timezone', 'Europe/Podgorica');
        $now = Carbon::now($tz);
        $today = $now->copy()->startOfDay();
        $tomorrow = $today->copy()->addDay();
        $previewHours = 3;

        $slots = ListOfTimeSlot::orderBy('id')->get();
        $todaySlotIds = [];
        $tomorrowSlotIds = [];

        foreach ($slots as $slot) {
            if ($slot->isInArrivalControlWindow($now, $today, $previewHours)) {
                $todaySlotIds[] = $slot->id;
            }
            if ($slot->isInArrivalControlWindow($now, $tomorrow, $previewHours)) {
                $tomorrowSlotIds[] = $slot->id;
            }
        }

        if (empty($todaySlotIds) && empty($tomorrowSlotIds)) {
            $query->whereRaw('1 = 0');

            return;
        }

        $query->where(function (Builder $q) use ($todaySlotIds, $tomorrowSlotIds, $today, $tomorrow) {
            if (! empty($todaySlotIds)) {
                $q->orWhere(fn (Builder $b) => $b->whereDate('reservation_date', $today)->whereIn('drop_off_time_slot_id', $todaySlotIds));
            }
            if (! empty($tomorrowSlotIds)) {
                $q->orWhere(fn (Builder $b) => $b->whereDate('reservation_date', $tomorrow)->whereIn('drop_off_time_slot_id', $tomorrowSlotIds));
            }
        });
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class)->withDefault();
    }

    /** Da li je rezervacija od strane gosta (bez naloga). */
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

    public function vehicle(): BelongsTo
    {
        return $this->belongsTo(Vehicle::class)->withDefault();
    }

    public function freeReservationRequest(): BelongsTo
    {
        return $this->belongsTo(FreeReservationRequest::class, 'free_reservation_request_id');
    }

    /**
     * Snapshot podaci za prikaz/forme (guest ili user): user_name, country, license_plate, email.
     * Za guest uvek iz ovih polja; za autentifikovanog takođe sačuvano u rezervaciji.
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

    public function dropOffTimeSlot(): BelongsTo
    {
        return $this->belongsTo(ListOfTimeSlot::class, 'drop_off_time_slot_id');
    }

    public function pickUpTimeSlot(): BelongsTo
    {
        return $this->belongsTo(ListOfTimeSlot::class, 'pick_up_time_slot_id');
    }

    public function vehicleType(): BelongsTo
    {
        return $this->belongsTo(VehicleType::class, 'vehicle_type_id');
    }

    public function postFiscalizationData(): HasOne
    {
        return $this->hasOne(PostFiscalizationData::class, 'reservation_id');
    }

    /** Nerešeni zapis za retry fiskalizacije (resolved_at null). Za admin "retry" / "mark resolved". */
    public function postFiscalizationDataUnresolved(): HasOne
    {
        return $this->hasOne(PostFiscalizationData::class, 'reservation_id')->whereNull('resolved_at');
    }
}
