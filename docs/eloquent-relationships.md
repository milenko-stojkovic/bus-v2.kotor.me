# Eloquent relacije (iz migracija)

Pregled FK iz baze i preporučene relacije za modele.

**Poslednje ažuriranje:** 2026-10-06 — `DailyFeeBlockedDate`, `BlockZoneWorklist`; ispravka Admin FK napomene.

---

## 1. User

**Tabela:** `users` (id = bigInteger)

**Referencira ga:**
- `sessions.user_id`
- `temp_data.user_id` (nullable) – guest rezervacije imaju user_id = null
- `reservations.user_id` (nullable) – guest rezervacije imaju user_id = null
- `vehicles.user_id`

**Autentifikovani vs. guest:** v. `docs/auth-and-guests.md`. Relacije `reservations` i `tempData` vraćaju samo redove gde je `user_id` = ovaj user; guest rezervacije nemaju vezu sa users.

**Relacije u modelu User:**
```php
public function vehicles(): HasMany
{
    return $this->hasMany(Vehicle::class);
}

public function reservations(): HasMany
{
    return $this->hasMany(Reservation::class);
}

public function tempData(): HasMany
{
    return $this->hasMany(TempData::class);
}
```

---

## 2. Admin

**Tabela:** `admins` (id = bigInteger). Model **`Admin`** trenutno **ne definiše** Eloquent `HasMany` / `BelongsTo` metode.

**Referencira ga (FK u drugim tabelama):**
- `daily_fee_blocked_dates.created_by_admin_id` (nullable) → `DailyFeeBlockedDate::createdByAdmin()`
- `block_zone_worklist.reviewed_by_admin_id` (nullable) → `BlockZoneWorklist::reviewedByAdmin()`
- (ostali admin audit FK-ovi mogu postojati u drugim modulima — dokumentovati samo kada model/migracija imaju eksplicitnu relaciju)

---

## 3. ListOfTimeSlot (list_of_time_slots)

**Tabela:** `list_of_time_slots` (id = unsignedInteger)

**Referencira ga:**
- `daily_parking_data.time_slot_id`
- `reservations.drop_off_time_slot_id`, `reservations.pick_up_time_slot_id`
- `temp_data.drop_off_time_slot_id`, `temp_data.pick_up_time_slot_id`

**Relacije u modelu ListOfTimeSlot:**
```php
public function dailyParkingData(): HasMany
{
    return $this->hasMany(DailyParkingData::class, 'time_slot_id');
}

public function reservationsAsDropOff(): HasMany
{
    return $this->hasMany(Reservation::class, 'drop_off_time_slot_id');
}

public function reservationsAsPickUp(): HasMany
{
    return $this->hasMany(Reservation::class, 'pick_up_time_slot_id');
}

public function tempDataAsDropOff(): HasMany
{
    return $this->hasMany(TempData::class, 'drop_off_time_slot_id');
}

public function tempDataAsPickUp(): HasMany
{
    return $this->hasMany(TempData::class, 'pick_up_time_slot_id');
}
```

---

## 4. VehicleType (vehicle_types)

**Tabela:** `vehicle_types` (id = unsignedInteger)

**Referencira ga:**
- `reservations.vehicle_type_id`
- `temp_data.vehicle_type_id`
- `vehicle_type_translations.vehicle_type_id`
- `vehicles.vehicle_type_id`

**Relacije u modelu VehicleType:**
```php
public function translations(): HasMany
{
    return $this->hasMany(VehicleTypeTranslation::class, 'vehicle_type_id');
}

public function reservations(): HasMany
{
    return $this->hasMany(Reservation::class, 'vehicle_type_id');
}

public function tempData(): HasMany
{
    return $this->hasMany(TempData::class, 'vehicle_type_id');
}

public function vehicles(): HasMany
{
    return $this->hasMany(Vehicle::class, 'vehicle_type_id');
}
```

---

## 5. VehicleTypeTranslation (vehicle_type_translations)

**Tabela:** `vehicle_type_translations`  
**FK:** `vehicle_type_id` → `vehicle_types`

**Relacije:**
```php
public function vehicleType(): BelongsTo
{
    return $this->belongsTo(VehicleType::class, 'vehicle_type_id');
}
```

---

## 6. DailyParkingData (daily_parking_data)

**Tabela:** `daily_parking_data` (id = unsignedInteger)  
**FK:** `time_slot_id` → `list_of_time_slots`

**Relacije:**
```php
public function timeSlot(): BelongsTo
{
    return $this->belongsTo(ListOfTimeSlot::class, 'time_slot_id');
}
```

**Fillable (sugestija):** `date`, `time_slot_id`, `capacity`, `reserved`, `pending`, `is_blocked`  
**Casts:** `date` => `date`, `capacity/reserved/pending` => `integer`, `is_blocked` => `boolean`

---

## 6b. DailyFeeBlockedDate (daily_fee_blocked_dates)

**Tabela:** `daily_fee_blocked_dates`  
**Model:** `App\Models\DailyFeeBlockedDate`

**Kolone / FK:**
- `date` — **UNIQUE** (Y-m-d zabrane **nove** prodaje dnevne naknade)
- `created_by_admin_id` — nullable → `admins.id` (`nullOnDelete`)

**Relacije u modelu:**
```php
public function createdByAdmin(): BelongsTo
{
    return $this->belongsTo(Admin::class, 'created_by_admin_id');
}
```

**Semantika:** red smije postojati samo dok je dan potpuno slot-blokiran; v. **`docs/admin-panel.md`** §2. Nije dio `daily_parking_data`.

---

## 6c. BlockZoneWorklist (block_zone_worklist)

**Tabela:** `block_zone_worklist`  
**Model:** `App\Models\BlockZoneWorklist`

**Kolone (izbor):** `merchant_transaction_id` (unique), `status` (`pending_payment` | `ready_to_adjust` | `acknowledged_no_adjustment` | `converted_to_daily_fee`), `old_date`, `old_drop_off`, `old_pick_up`, `affected_*`, `snapshot_json`, `reservation_id` (nullable), `temp_data_id` (nullable), `reviewed_by_admin_id` (nullable), `reviewed_at`, `resolution_note`.

**FK (migracije / model):**
- `reviewed_by_admin_id` → `admins` (nullable)
- `reservation_id` / `temp_data_id` — indeksirani helperi; Eloquent `belongsTo` u modelu (DB FK constraint može biti odsustan u starijoj migraciji)

**Relacije u modelu:**
```php
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
```

**Scope:** `activeIntervention()` — samo `pending_payment` / `ready_to_adjust`. Terminalni: `acknowledged_no_adjustment`, `converted_to_daily_fee`. Poslovna pravila: **`docs/admin-panel.md`** §2.

---

## 7. Reservation (reservations)

**Tabela:** `reservations` (id = unsignedInteger)

**FK:**
- `user_id` → `users` (nullable)
- `vehicle_id` → `vehicles` (nullable)
- `drop_off_time_slot_id` → `list_of_time_slots` (nullable za `reservation_kind = daily_ticket`)
- `pick_up_time_slot_id` → `list_of_time_slots` (nullable za `daily_ticket`)
- `vehicle_type_id` → `vehicle_types`
- `free_reservation_request_id` → `free_reservation_requests` (nullable; povezani FZBR fulfill)

**Invariant `reservation_kind`:** `time_slots` (default) → oba slot ID NOT NULL; `daily_ticket` → oba slot ID NULL. Helperi: `Reservation::isTimeSlots()`, `isDailyTicket()`.

**Referencira ga:** `post_fiscalization_data.reservation_id`; `block_zone_worklist.reservation_id` (nullable helper)

**Relacije:**
```php
public function user(): BelongsTo
{
    return $this->belongsTo(User::class);
}

public function vehicle(): BelongsTo
{
    return $this->belongsTo(Vehicle::class);
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

public function freeReservationRequest(): BelongsTo
{
    return $this->belongsTo(FreeReservationRequest::class, 'free_reservation_request_id');
}
```

**Fillable:** sva polja koja se mass-assign-uju (uključujući `reservation_kind`, `payment_method`, `created_by_admin`, `invoice_amount`, …).  
**Casts:** `reservation_date` => `date`, `fiscal_date` => `datetime`, `email_sent` => `integer` — semantika: **`Reservation::EMAIL_NOT_SENT`**, **`EMAIL_SENT`**, **`EMAIL_SENDING`** (v. model).

---

## 8. PostFiscalizationData (post_fiscalization_data)

**Tabela:** `post_fiscalization_data`  
**FK:** `reservation_id` → `reservations`

**Relacije:**
```php
public function reservation(): BelongsTo
{
    return $this->belongsTo(Reservation::class, 'reservation_id');
}
```

**Fillable:** `reservation_id`, `merchant_transaction_id`

---

## 9. TempData (temp_data)

**Tabela:** `temp_data` (id = unsignedInteger)

**FK:**
- `user_id` → `users` (nullable)
- `drop_off_time_slot_id` → `list_of_time_slots` (nullable za `daily_ticket`)
- `pick_up_time_slot_id` → `list_of_time_slots` (nullable za `daily_ticket`)
- `vehicle_type_id` → `vehicle_types`

**Status ENUM (produkcija):** `pending`, `processed`, `canceled`, `expired`, `late_success`, `late_manual_review`, `late_rejected` — **nema** `failed`. Konstante: `TempData::STATUS_*`, terminalna: `TempData::TERMINAL_STATES`.

**Relacije:**
```php
public function user(): BelongsTo
{
    return $this->belongsTo(User::class);
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
```

**Fillable:** sva polja koja se setuju pri kreiranju (uključujući `reservation_kind`, `invoice_amount_snapshot`, callback/audit polja).  
**Casts:** `reservation_date` => `date`, `status` => enum/string.

---

## 11. ExternalFileArchive (external_file_archives)

**Tabela:** `external_file_archives` — MEGA arhiva privatnih fajlova (Limo, FZBR prilozi, …). V. `docs/external-file-archive.md`.

**Nema klasičnih FK** — polimorfna veza preko `source_table`, `source_id`, `source_column`.

**Status:** `pending` | `uploaded` | `failed` (status **arhivnog reda**, ne `temp_data`).

**Model:** `App\Models\ExternalFileArchive` — bez Eloquent relacija ka izvoru (lookup u servisu).

---

## 12. AgencyAdvanceTopup / AgencyAdvanceTransaction

**Tabele:** `agency_advance_topups`, `agency_advance_transactions` (avansne uplate agencija).

**FK:**
- `agency_advance_topups.agency_user_id` → `users`
- `agency_advance_transactions.agency_user_id` → `users` (ledger)

**Relacije (Topup):**
```php
public function agencyUser(): BelongsTo
{
    return $this->belongsTo(User::class, 'agency_user_id');
}
```

**Status topup-a:** `pending`, `paid`, `failed`, `expired` — ovo je **zaseban** lifecycle od `temp_data` (admin Uvid: `/admin/uvid/avans`).

---

## 13. FreeReservationRequest (FZBR / besplatne rezervacije)

**Tabela:** `free_reservation_requests` + djeca: `free_reservation_request_vehicles`, `free_reservation_request_segments`, `free_reservation_request_attachments`.

**FK:** `user_id` → `users`; slotovi → `list_of_time_slots`; fulfill postavlja `reservations.free_reservation_request_id`.

**Relacije:**
```php
public function vehicles(): HasMany
public function segments(): HasMany
public function attachments(): HasMany
public function reservations(): HasMany // reverse: Reservation::freeReservationRequest()
```

**Status:** `submitted`, `updated`, `fulfilled`, `rejected`. V. `docs/agency-panel.md`, `docs/admin-panel.md` § Besplatne rezervacije.

---

## 10. Vehicle (vehicles)

**Tabela:** `vehicles` (id = unsignedInteger)

**FK:**
- `user_id` → `users`
- `vehicle_type_id` → `vehicle_types`

**Referencira ga:** `reservations.vehicle_id`

**Relacije:**
```php
public function user(): BelongsTo
{
    return $this->belongsTo(User::class);
}

public function vehicleType(): BelongsTo
{
    return $this->belongsTo(VehicleType::class, 'vehicle_type_id');
}

public function reservations(): HasMany
{
    return $this->hasMany(Reservation::class, 'vehicle_id');
}
```

**Fillable:** `user_id`, `license_plate`, `vehicle_type_id`  
**Casts:** po potrebi.

---

## Tabele bez modela (po želji)

- **report_emails**, **system_config**, **ui_translations** — nema FK; ako ih koristiš u relacijama ili po imenu, možeš napraviti jednostavan model bez relacija. **`report_emails.purpose`:** ENUM `report` | `limo_incidents`.
- **cache**, **jobs**, **sessions**, **password_reset_tokens**, **migrations** — sistemske; obično bez Eloquent modela.

---

## Napomena o tipovima ključeva

- `users.id` = **bigInteger** → svi `user_id` su `unsignedBigInteger`.
- `list_of_time_slots.id`, `vehicle_types.id`, `reservations.id`, `vehicles.id`, `daily_parking_data.id`, `temp_data.id` = **unsignedInteger** → svi FK ka njima su `unsignedInteger` (osim `vehicles.user_id` = bigInteger).
- U modelima koristi `$keyType` i `$incrementing` samo ako ne koristiš standardne `id`; inače Laravel podrazumeva ispravno.
