# Workflow plaćanja i temp_data

Logika mora ostati konzistentna: **temp_data** kao soft lock (samo **Termini**) i audit, zatim upis u **reservations**; `temp_data` se **ne briše** na uspehu (status → `processed`).

**Povezano:** `docs/payment-states.md`, `docs/payment-callback-handling.md`, `docs/payment-state-machine.md` (canonical), `docs/project-conventions.md`.

**Poslednje ažuriranje:** 2026-06-30 (`payment_redirect_url`; ponovni checkout na pending bez duplog `createSession`).

---

## Vrste rezervacije (`reservation_kind`)

| Vrijednost | Checkout | Slotovi u `temp_data` | `daily_parking_data` |
|------------|----------|----------------------|----------------------|
| **`time_slots`** (default) | Gost `/guest/reserve` ili agencija `/panel/reservations` | `drop_off_time_slot_id`, `pick_up_time_slot_id` — NOT NULL | **Soft lock:** `pending` +1 na oba slota pri kreiranju `temp_data`; na uspjehu `reserved`; na cancel/expire decrement `pending` |
| **`daily_ticket`** | Agencija `/panel/reservations` **ili** gost `/guest/reserve` (samo kartica) | Oba slot FK = **NULL** | **Ne dira se** — nema `pending`/`reserved` na slotovima; expire/cancel/success handler preskače soft-lock |

Detalji cijene, PDF i fiskal: `payment-state-machine.md` § snapshot; panel lifecycle: `agency-panel.md`, `auth-and-guests.md`.

---

## Flow

### 1. Korisnik (ulogovan ili anoniman) krene plaćanje

#### 1a. Termini (`time_slots`)

- **Redosled validacije u `CheckoutController`:** (1) postojeći **pending** za istog korisnika/gosta + isti datum + isti drop **i** pick → v. §1d; (2) dupli Termini konflikt u **`reservations`** + tuđi pending (`DuplicateReservationAttemptService`); (3) ostale blokade; (4) novi `temp_data` + `createSession`.

- **Pre payment/session dela (business validacija rezervacije):** prije kreiranja novog `temp_data`, checkout provjerava konflikt u tabeli **`reservations`** (i tuđi aktivni pending preko duplicate servisa) za:
  - isti `reservation_date`
  - ista (normalizovana) `license_plate` (ALL CAPS, bez razmaka i spec. znakova; samo `[A-Z0-9]`)
  - i **isti drop-off** (`drop_off_time_slot_id`) **ili** **isti pick-up** (`pick_up_time_slot_id`)
  - **VAŽNO:** cross-match `drop=pick` / `pick=drop` se **ne** smatra duplikatom (dozvoljeno je “ostavi jednu grupu / pokupi drugu”).
  - Provera se u ovoj iteraciji radi **samo nad `reservations`** (ne nad `temp_data`) da se izbegnu lažne zabrane zbog neuspešnih/isteklih payment pokušaja.
  - Ako je konflikt detektovan, checkout prekida tok i vraća korisnika na formu sa porukom, bez `temp_data` i bez `createSession`.

- Upis u **temp_data**:
  - `merchant_transaction_id`, `reservation_kind` = `time_slots`
  - termini: `drop_off_time_slot_id`, `pick_up_time_slot_id`, `reservation_date`
  - vozilo / tip: `vehicle_type_id`, `license_plate`
  - podaci: **`user_name`** (iz forme **`name`** za gosta, ili `users.name` za ulogovanog), `country`, `email`
  - **user_id** → `NULL` (guest) ili konkretan `id` (ulogovan)
  - `status` → `pending`
  - **`invoice_amount_snapshot`** — iznos poslat banci (v. state machine)
- **Soft lock:** `daily_parking_data.pending` se povećava za **oba** slota (jednom ako su slotovi isti).

#### 1c. Neuspješan `createSession` (prije redirecta na banku)

Ako **Bankart `createSession`** padne **prije** nego što korisnik stigne na payment page (npr. HTTP 503, ne-JSON, config/mreža):

- **`PaymentInitFailureService`** (iz **`CheckoutController`**) odmah:
  - postavlja **`temp_data.status` → `canceled`**
  - **`resolution_reason` → `payment_init_failed`**
  - **`releaseSoftLock`** — decrement `pending` na oba slota (**Termini**)
- Korisnik dobija **503** + generičku poruku (`payment_processing_issue` / `UiText`) — **ne** sirovi bankarski tekst.
- Log: **`payment_init_failed`** (`merchant_transaction_id`, `temp_data_id`, `http_status`, `reason`, `stage`).
- Strukturisani checkout logovi (kanal **`payments`**): `checkout_started`, `checkout_validation_failed`, `checkout_guest_lower_category_blocked`, `checkout_temp_data_created`, `checkout_bankart_create_session_*`, `checkout_redirect_created`, `checkout_failed_generic`.
- Korisnik (HTML forma) dobija **redirect nazad** na guest/panel formu sa jasnom porukom **`payment_window_unavailable`** (ne sirovi 503 na `/checkout`).
- Dijagnostika: `php artisan payment:inspect-checkout-attempt --email=... --date=Y-m-d` (ili `--plate=`, `--merchant-transaction-id=`).
- Provider i dalje loguje **`bankart_create_session_failed`** sa detaljima HTTP odgovora.
- **Retry** istog slot/tablice odmah moguć — red **nije** blocking `pending`.

Ako je **`createSession` uspješan** i korisnik je preusmjeren na banku, **`temp_data`** ostaje **`pending`** do callbacka / inquiry-ja / cron expire-a (v. § Cron). Bankart **`redirectUrl`** se čuva u **`temp_data.payment_redirect_url`** (v. §1d).

#### 1d. Ponovni klik dok je `temp_data` još `pending` (`checkout_existing_pending`)

Ako korisnik ponovo klikne **Plati** za **isti** booking (isti korisnik/gost email, datum, drop-off i pick-up) dok prethodni pokušaj još čeka banku:

1. **`CheckoutController`** pronalazi postojeći pending red (`findExistingPendingForSlot`, `whereDate` na `reservation_date`).
2. Ako postoji **`payment_redirect_url`** → **ponovni redirect** na isti Bankart URL; log **`checkout_existing_pending_reused_redirect`**; **nema** drugog `createSession` za isti `merchant_transaction_id`.
3. Ako URL nije sačuvan (legacy redovi) → jedan `createSession`; ako Bankart vrati **3004** (*transaction already exists*) ili drugi init fail u ovoj grani → **ne** `payment_init_failed` / **ne** `canceled`; korisnik vidi **`payment_existing_session_open`** (prozor je već otvoren / sesija već kreirana).
4. **`PaymentInitFailureService`** i admin alert **`checkout_payment_init_failed`** primjenjuju se samo na **prvi** pokušaj (`checkout_after_temp_created`) kad redirect **nikad** nije uspio.

Servis: **`PendingBankartRedirectService`**. Testovi: **`CheckoutExistingPendingRedirectTest`**, ažurirani **`CheckoutCreateSessionFailureTest`**.

#### 1b. Dnevna naknada (`daily_ticket`)

- Nema provjere konflikta po slotovima (nema slotova).
- Upis u **temp_data** sa `reservation_kind` = `daily_ticket`, slot FK = **NULL**, ostala polja kao gore.
- **Bez** incrementa `daily_parking_data.pending`.

### 2. Plaćanje uspe

**Backend:**

1. Čita slog iz **temp_data** (po `merchant_transaction_id`).
2. Pravi slog u **reservations** (u transakciji sa zaključavanjem).
3. Ažurira **temp_data.status** → **`processed`**; red **ostaje** u bazi (audit trail, retry token kontekst).
4. Za **Termini:** soft-lock → `reserved` na slotovima. Za **daily_ticket:** bez promjene `daily_parking_data`.
5. Dispatch **ProcessReservationAfterPaymentJob** (fiskalizacija, PDF, email).

**Rezultat:**

- Ulogovani korisnik ima istoriju (`reservation.user_id` set).
- Guest: `user_id` null, snapshot u kolonama uključujući `user_name`.

### 3. Plaćanje ne uspe / cancel

- Bankovni događaj normalizovan kao **`failed`** u job-u postaje **`temp_data.status` = `canceled`** (ENUM u bazi **nema** `failed`). UI i redirect i dalje govore o „neuspjelom plaćanju“ — v. `PaymentReturnController`, `payment-callback-handling.md`.
- **`createSession` ne uspe prije banke (prvi pokušaj)** → odmah **`canceled`** + **`resolution_reason=payment_init_failed`** (v. §1c) — **ne** ostaje `pending`.
- **Ponovni klik na isti pending bez sačuvanog URL-a** → **ne** `canceled` zbog Bankart **3004** (v. §1d).
- Ostala terminalna grana po pravilima: npr. **`expired`** (cron za **prave** pending session-e na banci), **`late_success`** (kasni SUCCESS posle `expired`).
- **Ne oslanjati se na fizičko brisanje** redova za operativni audit.
- Oslobađanje soft lock-a (**samo Termini**): **decrement `pending`** za oba slota gde je primenjeno.

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
| **canceled** | Terminalno (bankovni neuspjeh); kasni SUCCESS ne menja status (v. §4). |
| **expired** | Terminalno (cron istek pending); kasni SUCCESS → `late_success`. Red ostaje za audit; `temp-data:cleanup` briše stare ne-pending redove (default 180 dana). |
| **late_success** / **late_manual_review** | Samo nakon **`expired`** + kasni SUCCESS; **ručna** obrada preko **`/staff/late-success`** (`LateSuccessController`: force/reject). Komanda **`reservations:assign-late-success`** je **no-op stub** — nema automatske dodjele (v. `payment-state-machine.md` §4b). |
| **pending → expired (cron)** | Komanda **`reservations:expire-pending`** (svakih **5 min**): pending stariji od **`pending_expire_minutes`** (env `RESERVATIONS_PENDING_EXPIRE_MINUTES`; **preporuka produkcija: 15–30 min** za session-e gdje je korisnik **stvarno** na banci) → **`expired`** + decrement `daily_parking_data.pending` (**samo Termini**). Ne zamjenjuje §1c — init failure se zatvara odmah. V. `cron-commands.md`. |
| **inquiry „Transaction not found“** | Cron **`payment:check-pending-inquiry`**: ako banka vrati da transakcija ne postoji → **kratak grace period** (da se izbjegne prerano otkazivanje nakon uspješnog createSession), zatim isti tretman kao §1c (`payment_init_failed`, release lock). |

**Napomena:** `reservations:process-pending` je **no-op stub** — ne mijenja `temp_data` (v. `cron-commands.md` §1).

---

## Mini-checklista kolona temp_data (orientaciono)

| Kolona | Napomena |
|--------|----------|
| merchant_transaction_id | UNIQUE |
| payment_redirect_url | Bankart `redirectUrl` nakon uspješnog prvog `createSession`; reuse na `checkout_existing_pending` |
| user_id | NULL = guest |
| reservation_kind | `time_slots` (default) \| `daily_ticket` |
| drop_off_time_slot_id, pick_up_time_slot_id | NOT NULL za Termini; **NULL** za daily_ticket |
| user_name | Snapshot imena |
| invoice_amount_snapshot | Iznos checkout-a (source of truth za downstream) |
| status | ENUM: `pending`, `processed`, `canceled`, `expired`, `late_success`, … — **nema** `failed` |
| resolution_reason | Npr. `payment_init_failed` (createSession / inquiry not found), callback greške preko **`ErrorClassifier`**, `converted_to_advance`, … |

Dodatne kolone (`callback_error_code`, `raw_callback_payload`, itd.) koriste se za klasifikaciju i audit — v. migracije i `ErrorClassifier`.
