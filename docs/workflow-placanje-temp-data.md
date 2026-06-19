# Workflow plaćanja i temp_data

Logika mora ostati konzistentna: **temp_data** kao soft lock i audit, zatim upis u **reservations**; `temp_data` se **ne briše** na uspehu (status → `processed`).

**Povezano:** `docs/payment-states.md`, `docs/payment-callback-handling.md`, `docs/project-conventions.md`.

---

## Flow

### 1. Korisnik (ulogovan ili anoniman) krene plaćanje

- **Pre payment/session dela (business validacija rezervacije):** pre bilo kakvog kreiranja `temp_data` ili `createSession`, checkout proverava da li već postoji konfliktna rezervacija u tabeli **`reservations`** za:
  - isti `reservation_date`
  - ista (normalizovana) `license_plate` (ALL CAPS, bez razmaka i spec. znakova; samo `[A-Z0-9]`)
  - i **isti drop-off** (`drop_off_time_slot_id`) **ili** **isti pick-up** (`pick_up_time_slot_id`)
  - **VAŽNO:** cross-match `drop=pick` / `pick=drop` se **ne** smatra duplikatom (dozvoljeno je “ostavi jednu grupu / pokupi drugu”).
  - Provera se u ovoj iteraciji radi **samo nad `reservations`** (ne nad `temp_data`) da se izbegnu lažne zabrane zbog neuspešnih/isteklih payment pokušaja.
  - Ako je konflikt detektovan, checkout prekida tok i vraća korisnika na formu sa porukom, bez `temp_data` i bez `createSession`.

- Upis u **temp_data**:
  - `merchant_transaction_id`
  - termini: `drop_off_time_slot_id`, `pick_up_time_slot_id`, `reservation_date`
  - vozilo / tip: `vehicle_type_id`, `license_plate`
  - podaci: **`user_name`** (iz forme **`name`** za gosta, ili `users.name` za ulogovanog), `country`, `email`
  - **user_id** → `NULL` (guest) ili konkretan `id` (ulogovan)
  - `status` → `pending`
- **Soft lock:** `daily_parking_data.pending` se povećava za **oba** slota (jednom ako su slotovi isti).

### 2. Plaćanje uspe

**Backend:**

1. Čita slog iz **temp_data** (po `merchant_transaction_id`).
2. Pravi slog u **reservations** (u transakciji sa zaključavanjem).
3. Ažurira **temp_data.status** → npr. **`processed`**; red **ostaje** u bazi (audit trail, retry token kontekst).
4. Dispatch **ProcessReservationAfterPaymentJob** (fiskalizacija, PDF, email).

**Rezultat:**

- Ulogovani korisnik ima istoriju (`reservation.user_id` set).
- Guest: `user_id` null, snapshot u kolonama uključujući `user_name`.

### 3. Plaćanje ne uspe / cancel

- Ažurira se **temp_data** (`failed`, `canceled`, `expired`, itd. prema `ErrorClassifier` / pravilima job-a); **ne oslanjati se na fizičko brisanje** redova za operativni audit.
- Oslobađanje soft lock-a: **decrement `pending`** za oba slota gde je primenjeno.

### 4. Kasni odgovor banke posle terminalnog `temp_data`

- **`canceled`:** ostaje **`canceled`**. Naknadni bank **SUCCESS** u **`PaymentCallbackJob`** se **ignoriše** (log **`payment_success_after_canceled_ignored`**); **nema** prelaza u **`late_success`**.
- **`expired`** (npr. posle **`reservations:expire-pending`**): naknadni **SUCCESS** → **`late_success`** preko **`applyLateSuccess`** (bez automatskog kreiranja rezervacije). Razlika: istekao pending i oslobođen slot ≠ eksplicitni bankovski otkaz.

Ako je `late_success` za **ulogovanu agenciju** i avans je uključen (`config('features.advance_payments')`):

- sistem automatski konvertuje uplatu u **paid** avansni topup + ledger topup
- `temp_data` ostaje `late_success`, uz `resolution_reason = converted_to_advance` (da se vidi u Admin “Uvid”)
- **ne** kreira se rezervacija i **ne** dira se `daily_parking_data`
- iznos se uzima iz `temp_data.invoice_amount_snapshot` (legacy fallback loguje `late_success_advance_amount_snapshot_missing`)

---

## Cron i temp_data

| Status / scenario | Napomena |
|-------------------|----------|
| **processed** | Red u `temp_data` ostaje; rezervacija u `reservations`. |
| **canceled** | Terminalno; kasni SUCCESS ne menja status (v. §4). |
| **late_success** / **late_manual_review** | Samo nakon **`expired`** + kasni SUCCESS; admin ili cron stub (`reservations:assign-late-success`) — v. `AssignLateSuccessReservations`, `LateSuccessController`. |
| **failed** / **expired** | Red ostaje za audit (Admin “Uvid”); `temp-data:cleanup` briše samo **stare ne-pending** redove po retention pravilu (default 180 dana). |
| **pending → expired (cron)** | Komanda **`reservations:expire-pending`** (svakih **5 min**): pending stariji od **`pending_expire_minutes`** (default **5**, env `RESERVATIONS_PENDING_EXPIRE_MINUTES`) → **`expired`** + decrement `daily_parking_data.pending` (Termini). Oslobađa dupli-checkout lock ako `createSession` padne a red ostane `pending`. V. `cron-commands.md`. |

---

## Mini-checklista kolona temp_data (orientaciono)

| Kolona | Napomena |
|--------|----------|
| merchant_transaction_id | UNIQUE |
| user_id | NULL = guest |
| drop_off_time_slot_id, pick_up_time_slot_id | Oba učestvuju u `daily_parking_data` |
| user_name | Snapshot imena |
| status | ENUM uključuje pending, processed, canceled, expired, late_success, late_manual_review, … (v. `TempData::STATUS_*`) |

Dodatne kolone (callback greške, `resolution_reason`, `raw_callback_payload`, itd.) koriste se za klasifikaciju i audit — v. migracije i `ErrorClassifier`.
