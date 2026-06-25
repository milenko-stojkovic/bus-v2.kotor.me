# Provera modela – checklist

Pregled u skladu sa specifikacijom. Model **TimeSlot** u kodu = **ListOfTimeSlot** (tabela `list_of_time_slots`).

**Poslednje ažuriranje:** 2026-06-19

---

## Users

| Zahtev | Status |
|--------|--------|
| Polja: id, name, email, email_verified_at, password, remember_token, created_at, updated_at | ✅ (Laravel default + migracija country) |
| User → hasMany(Vehicle), hasMany(Reservation) | ✅ |
| User → belongsToMany(Role) preko role_user | ✅ |
| Guest: nullable FK u Vehicle i Reservation (user_id) | ✅ |
| Fillable: name, email, password | ✅ (+ country, email_verified_at, lang) |
| Casts: email_verified_at → datetime | ✅ |

---

## Vehicle

| Zahtev | Status |
|--------|--------|
| Polja: id, user_id (nullable), license_plate, vehicle_type_id, created_at, updated_at | ✅ (nullable user_id preko migracije) |
| Vehicle → belongsTo(User, withDefault), belongsTo(VehicleType) | ✅ |
| User → hasMany(Vehicle), VehicleType → hasMany(Vehicle) | ✅ |
| Fillable: user_id, license_plate, vehicle_type_id | ✅ |
| FK: user_id → users.id, vehicle_type_id → vehicle_types.id | ✅ |

---

## VehicleType

| Zahtev | Status |
|--------|--------|
| Polja: id, price; created_at/updated_at (tabela nema u bazi) | ✅ model $timestamps = false |
| VehicleType → hasMany(Vehicle), hasMany(VehicleTypeTranslation) | ✅ |
| Price decimal(10,2), fillable: price | ✅ |

---

## VehicleTypeTranslation

| Zahtev | Status |
|--------|--------|
| Polja: id, vehicle_type_id, locale, name, description, created_at, updated_at | ✅ |
| belongsTo(VehicleType), unique (vehicle_type_id, locale) | ✅ |
| Fillable: vehicle_type_id, locale, name, description | ✅ |

---

## Reservation

| Zahtev | Status |
|--------|--------|
| Polja: id, user_id (nullable), vehicle_id (nullable), merchant_transaction_id, drop_off/pick_up_time_slot_id (nullable za `daily_ticket`), reservation_date, snapshot (user_name, country, license_plate, vehicle_type_id, email), fiscal_*, status, email_sent, **`reservation_kind`** (`time_slots` \| `daily_ticket`), **`payment_method`**, **`created_by_admin`**, **`free_reservation_request_id`**, **`invoice_amount`**, created_at, updated_at | ✅ |
| belongsTo(User, withDefault), belongsTo(Vehicle, withDefault), belongsTo(VehicleType), belongsTo(TimeSlot) drop_off/pick_up, belongsTo(FreeReservationRequest) | ✅ (TimeSlot = ListOfTimeSlot) |
| Invariant: `time_slots` → oba slot ID NOT NULL; `daily_ticket` → oba NULL | ✅ `isTimeSlots()` / `isDailyTicket()` |
| Fillable: sve snapshot + FK + merchant_transaction_id + status itd. | ✅ |
| Casts: reservation_date → date, fiscal_date → datetime | ✅ |

---

## TempData

| Zahtev | Status |
|--------|--------|
| Polja: id, merchant_transaction_id, user_id (nullable), drop_off/pick_up_time_slot_id (nullable za `daily_ticket`), reservation_date, user_name, country, license_plate, vehicle_type_id, email, **`reservation_kind`**, **`invoice_amount_snapshot`**, status ENUM, callback/audit polja, created_at, updated_at | ✅ |
| belongsTo(User, withDefault), belongsTo(VehicleType), belongsTo(TimeSlot) drop_off/pick_up | ✅ |
| Status ENUM (produkcija): `pending`, `processed`, `canceled`, `expired`, `late_success`, `late_manual_review`, `late_rejected` — **nema** `failed` | ✅ `TempData::STATUS_*`, `TERMINAL_STATES` |
| Fillable: sva polja osim id/created_at/updated_at | ✅ |

---

## DailyParkingData

| Zahtev | Status |
|--------|--------|
| Polja: id, date, time_slot_id, capacity, reserved, pending, created_at, updated_at | ✅ |
| belongsTo(TimeSlot), TimeSlot → hasMany(DailyParkingData) | ✅ (timeSlot() → ListOfTimeSlot) |
| Fillable: date, time_slot_id, capacity, reserved, pending | ✅ |

---

## TimeSlot (ListOfTimeSlot)

| Zahtev | Status |
|--------|--------|
| Polja: id, time_slot (tabela nema created_at/updated_at) | ✅ $timestamps = false |
| hasMany(DailyParkingData), hasMany(Reservation drop_off/pick_up) | ✅ (reservationsAsDropOff, reservationsAsPickUp) |
| Fillable: time_slot | ✅ |

---

## PostFiscalizationData

| Zahtev | Status |
|--------|--------|
| Polja: id, reservation_id, merchant_transaction_id, created_at, updated_at | ✅ |
| belongsTo(Reservation), Reservation → hasOne(PostFiscalizationData) | ✅ |
| Fillable: reservation_id, merchant_transaction_id | ✅ |

---

## SystemConfig

| Zahtev | Status |
|--------|--------|
| Polja: id, name, value, updated_at (nema created_at) | ✅ CREATED_AT = null |
| Fillable: name, value | ✅ |

---

## ReportEmails (ReportEmail)

| Zahtev | Status |
|--------|--------|
| Polja: id, email, **purpose** (ENUM u bazi: **`report`**, **`limo_incidents`**; podrazumijevano `report`), created_at (nema updated_at) | ✅ UPDATED_AT = null |
| Fillable: email, purpose (u kodu modela) | ✅ |
| Namena po **purpose**: `report` — primaoci zakazanih admin PDF izvještaja; `limo_incidents` — primaoci emaila za Limo incidente (v. `docs/admin-panel.md`, `docs/limo-service.md`) | ✅ |

---

## ExternalFileArchive (2026)

| Zahtev | Status |
|--------|--------|
| Tabela `external_file_archives`; status `pending` \| `uploaded` \| `failed` | ✅ |
| Polimorfna veza: `source_table`, `source_id`, `source_column`; MEGA polja; preview restore kolone | ✅ v. `docs/external-file-archive.md` |

---

## AgencyAdvanceTopup / AgencyAdvanceTransaction (2026)

| Zahtev | Status |
|--------|--------|
| Topup: `agency_user_id`, `merchant_transaction_id`, `amount`, status (`pending`/`paid`/`failed`/`expired`), `bank_payload`, … | ✅ |
| Ledger: `agency_advance_transactions` po agenciji | ✅ |
| Relacija topup → `User` (`agencyUser`) | ✅ |
| Zaseban lifecycle od `temp_data`; admin Uvid `/admin/uvid/avans` | ✅ |

---

## FreeReservationRequest (2026, FZBR)

| Zahtev | Status |
|--------|--------|
| Glavna tabela + vehicles, segments, attachments | ✅ |
| Status: `submitted`, `updated`, `fulfilled`, `rejected` | ✅ |
| Fulfill → `reservations.free_reservation_request_id` | ✅ migracija + model relacija |

---

## Roles (Role)

| Zahtev | Status |
|--------|--------|
| Polja: id, name, guard_name, created_at, updated_at | ✅ tabela roles |
| Role → belongsToMany(User), User → belongsToMany(Role) | ✅ preko role_user pivot |
| Pivot: role_user (user_id, role_id) | ✅ migracija 2026_02_25_100001_create_role_user_table |

Napomena: Spatie Laravel Permission koristi model_has_roles; ako ga instaliraš, možeš zameniti ovu pivot tabelu ili koristiti Role model sa ovom strukturom.

---

## Opšte napomene

- Nullable FK → **withDefault()** na: Reservation (user, vehicle), TempData (user), Vehicle (user).
- Snapshot u Reservation → cast: reservation_date (date), fiscal_date (datetime).
- Fillable → snapshot + FK + status polja gde je relevantno.
- Indeksi / unique: FK indeksi u migracijama; unique (vehicle_type_id, locale) u vehicle_type_translations; unique merchant_transaction_id gde je definisano.

---

## License plate – indeksi (bez refaktor sukoba)

| Tabela | Šta ima | Namera |
|--------|--------|--------|
| **reservations** | INDEX (license_plate, reservation_date) – `idx_res_plate_date` | Samo pretraga po tablici i datumu. **Nije UNIQUE** – više rezervacija (različiti useri/guestovi, datumi) može imati istu tablicu. |
| **vehicles** | **UNIQUE(user_id, license_plate, status)** – `uq_user_plate_status` | Jedan user ne sme imati dva **active** vozila sa istom tablicom. `removed` vozila čuvaju istoriju i mogu koegzistirati sa `active` za istu tablicu. |

Ne menjati: reservations ne treba UNIQUE na license_plate; vehicles mora ostati UNIQUE(user_id, license_plate, status) da `removed` istorija radi ispravno.

---

## vehicle_type_id u reservations vs vehicles (namerno dupliranje)

U **reservations** postoje i **vehicle_type_id** i **vehicle_id**. Ovo je namerno i ne sme se ukloniti.

| Polje | Namera |
|--------|--------|
| **vehicle_type_id** | Snapshot tipa vozila u trenutku rezervacije (plaćena kategorija). Ostaje za istoriju čak i ako user kasnije promeni vozilo ili obriše Vehicle. **Promjena tablice** (`PATCH /panel/reservations/{id}/vehicle`, uklanjanje vozila) **ne** ažurira ovo polje — kandidati za zamjenu porede se sa ovim snapshot-om (`VehicleReplacementCandidateService::paidCategoryMaxPrice`). Za **dnevnu naknadu** promjena tablice u agencijskom panelu dozvoljena je samo za **buduće** datume (`allowsPlateChange`; isti dan blokiran). |
| **vehicle_id** | Opcioni link na konkretno vozilo (ako je autentifikovan i izabrao vozilo). |

**Nemoj brisati vehicle_type_id iz reservations** kada dodaš ili koristiš vehicle_id. Istorijski podaci moraju ostati tačni.

---

## email u reservations vs users.email (namerno dupliranje)

U **reservations** polje **email** je snapshot i ostaje duplirano u odnosu na users.email.

| Razlog | Objašnjenje |
|--------|-------------|
| Guest | Guest nema nalog (user_id null) – email postoji samo u rezervaciji. |
| Izmena emaila | User može kasnije promeniti email u profilu; rezervacija mora ostati sa emailom kakav je bio u trenutku kupovine. |

**Nemoj uklanjati email iz reservations** ni zamenjivati ga referencom na user->email. Uvek koristi reservation->email za potvrde i istoriju.
