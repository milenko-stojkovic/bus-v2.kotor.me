# Payment state machine (canonical guardrail)

Ovaj dokument ima prednost nad ostalim tematskim dokumentima u slučaju razlike u interpretaciji.

## 1. Purpose / scope

**Namena:** Jedan kratak **canonical guardrail**: prelazi `temp_data`, terminalna stanja, invarijante, zabranjene pretpostavke. **Ne** duplira deploy/runbook niti punu PDF/email specifikaciju.

**Scope:** rezolucija plaćanja (webhook / inquiry → `PaymentCallbackJob`), kreiranje rezervacije, fiskalni follow-up, granica **`late_success`** (samo od **`expired`**, ne od **`canceled`**).

---

## 2. Source of truth (tabele)

| Tabela | Šta je izvor istine |
|--------|---------------------|
| **`temp_data`** | Stanje plaćanja / lock-a pre i posle banke: `status`, `merchant_transaction_id` (unique), snapshot checkout polja, `raw_callback_payload`, greške banke, `resolution_reason`, `retry_token`. Red se **ne briše** na uspehu (audit). |
| **`reservations`** | Potvrđena rezervacija posle uspešnog plaćanja: `merchant_transaction_id` (jedinstven poslovno), `status` (`paid` / `free` / …), **`invoice_amount`**, fiskalna polja (`fiscal_jir`, …), email stanja. |
| **`post_fiscalization_data`** | Red za **retry fiskalizacije** kada prvi pokušaj posle plaćanja ne uspe; veže se za `reservation_id`, ne za „poništi“ rezervaciju. |

---

## Payment amount (canonical source of truth)

**Pravilo:** iznos koji se koristi za:

- kreiranje rezervacije
- fiskalizaciju
- `late_success` → avans konverziju

mora biti **isti iznos koji je poslat banci u trenutku checkout-a**.

### Snapshot (temp_data)

- **`reservation_kind`:** `time_slots` (default) ili `daily_ticket` (samo agency checkout sa `auth_panel_booking`). Za `daily_ticket` slot FK su `NULL` i **ne** dira se `daily_parking_data` (nema `pending`/`reserved` na slotovima; expire/cancel/success handler preskače soft-lock). Fiskalni PDF (`pdf/paid-invoice`) i email potvrda prikazuju vrstu/datum važenja/lokacije umjesto termina dolaska/odlaska.
- Taj iznos se čuva kao snapshot u `temp_data` (npr. `invoice_amount_snapshot`).
- Nakon kreiranja `temp_data` taj iznos se **više nikada ne računa ponovo iz cjenovnika**.
- Promjene cijena **ne utiču** na već započete ili završene payment pokušaje.

### Payment callback / payload

- Payment callback **ne smije mijenjati** amount osim ako payload **eksplicitno sadrži potvrđen iznos**.
- Ako payment provider u budućnosti vraća stvarni naplaćeni iznos: taj iznos može postati novi source-of-truth, ali **samo ako je konzistentan sa request payload-om**.

### Fallback (legacy)

- Ako snapshot ne postoji (legacy slučaj), može se koristiti helper za izračun cijene, ali uz log warning (`late_success_advance_amount_snapshot_missing`).

**Napomena:** Ovo pravilo je kritično za finansijsku konzistentnost i mora važiti za sve payment tokove.

### Downstream usage rule

`invoice_amount_snapshot` je jedini dozvoljeni izvor za sve downstream procese, osim ako payment provider eksplicitno vraća potvrđen amount koji je konzistentan sa originalnim request-om.

- Downstream procesi uključuju: kreiranje reservation (`invoice_amount`), fiskalizaciju, `late_success` → advance konverziju
- Cijena se nikada ne smije ponovo računati iz `vehicle_types` ili drugih runtime izvora nakon što je `temp_data` kreiran
- Ako provider vraća potvrđen amount, primjenjuju se pravila iz sekcije “Payment callback / payload”

---

## 3. Terminalna stanja (`temp_data`)

Eksplicitno kao u kodu — **`TempData::TERMINAL_STATES`**:

- `processed`
- `late_success`
- `late_rejected`
- `canceled`
- `expired`

**`isTerminal()`** vraća tačno ova stanja. Ostala ENUM vrednost **`late_manual_review`** postoji u bazi i modelu, ali **nije** u `TERMINAL_STATES` (drugačija grana / admin — v. `LateSuccessController`).

Glavni tok checkout-a: **`pending` → …** (prelazi ispod).

---

## 4. Tabela prelaza (centralna)

**Događaj** = normalizovan ulaz u **`PaymentCallbackJob`** (`success` / `failed` / `timeout`) ili cron, osim gde je drugačije naznačeno. **Inquiry** šalje isti job — ista granica obrade kao webhook.

| Trenutno stanje | Događaj | Sledeće stanje | Napomena / sporedni efekti |
|-----------------|---------|----------------|----------------------------|
| `pending` | `success` (webhook ili inquiry) | `processed` | Kreira se **`reservations`** (jednom po `merchant_transaction_id`), `temp_data` → processed, soft-lock → reserved; **`ProcessReservationAfterPaymentJob`** (fiskal + mejl) po pravilima handlera. |
| `pending` | `failed` (CANCEL/ERROR/normalizovano) | `canceled` | **`handleCanceled`**: `releaseSoftLock` bez povećanja reserved; **`PaymentFailed`**; nema rezervacije. |
| `pending` | `timeout` | `late_success` | **`applyLateSuccess(..., releaseLock: true)`** — nema kreiranja rezervacije u ovom koraku. |
| `pending` | istek (cron `reservations:expire-pending`) | `expired` | Samo cron; smanjenje **`daily_parking_data.pending`** samo za `time_slots`; nema `PaymentCallbackJob`. |
| `pending` | `success`, ali rezervacija već postoji | *(nema promene statusa u ovom koraku)* | Job prekida ranije: log „duplicate“, nema duplog kreiranja. |
| `processed` | bilo koji ponovni callback/inquiry | `processed` | No-op (raniji return); idempotentnost. |
| `expired` | `success` | `late_success` | **`applyLateSuccess(..., releaseLock: false)`** (lock već pušten pri expire); **nema** automatskog kreiranja rezervacije. |
| `late_success` (agency + advance ON) | internal | `late_success` | Konverzija u avans: kreira se **paid** `agency_advance_topups` + ledger topup; `temp_data` ostaje `late_success`, `resolution_reason=converted_to_advance`; **ne** kreira se rezervacija i **ne** dira se `daily_parking_data`. Iznos: `temp_data.invoice_amount_snapshot` (legacy fallback loguje `late_success_advance_amount_snapshot_missing`). |
| `canceled` | `success` | `canceled` | **Bez promene**; log **`payment_success_after_canceled_ignored`**; email administratoru (**`AdminFiscalizationAlertService::notifyPaymentSuccessAfterCanceled`**, primalac `config('payment.operations_alert_email')`). |
| `late_success`, `late_rejected`, `expired`, `canceled` (ostali događaji) | `failed` / `timeout` / … | bez promene | Terminalna grana: return (bez novog prelaza u ovoj tabeli). |

---

## 5. Invarijante

- HTTP checkout **ne** odlučuje konačan ishod plaćanja — samo kreira `pending` + session + redirect.
- **Jedna rezervacija** po **`merchant_transaction_id`** (job proverava pre kreiranja).
- **`expired` + kasni `success` → `late_success`** ne sme kreirati rezervaciju kroz **`PaymentSuccessHandler::handle`** (samo **`applyLateSuccess`**).
- **`canceled` je terminalan** za kasni uspeh banke — nema prelaza u **`late_success`**; šalje se **operativni email** (isti kanal kao fiskal alerti) za ručnu obradu van aplikacije.
- **Neuspeh fiskalizacije** posle plaćanja **ne poništava** validnu rezervaciju (nefiskalni PDF + `post_fiscalization_data` / retry).
- **Webhook i Bankart inquiry** oba ulaze u **`PaymentCallbackJob`** — ista poslovna obrada (nema paralelnog „drugog“ success pipeline-a u komandi).

---

## 6. Zabranjene pretpostavke

- **`processed`** na `temp_data` **ne znači** da je fiskalizacija završena — fiskal može ići asinhrono / retry.
- **Nema `fiscal_jir`** ne znači da **rezervacija ne postoji** — često znači „fiskal u toku ili odložen“.
- Callback / inquiry su **bez locale konteksta** — jezik korisnika ne dolazi iz bank payload-a (v. snapshot / UI).
- **Fake QA** (`FAKE_PAYMENT_E2E_SYNC`, sync dispatch) menja **strategiju izvršavanja jobova**, ne pravila prelaza stanja.

---

## 7. Reference (dublje + kod)

- [payment-states.md](./payment-states.md)
- [workflow-placanje-temp-data.md](./workflow-placanje-temp-data.md)
- [payment-architecture.md](./payment-architecture.md)
- [payment-callback-handling.md](./payment-callback-handling.md)
- [success-payment-pipeline.md](./success-payment-pipeline.md) (fiskal / PDF / email)
- `app/Models/TempData.php`
- `app/Jobs/PaymentCallbackJob.php`
- `app/Services/Payment/PaymentSuccessHandler.php`
- `app/Services/AdminFiscalizationAlertService.php` (payment + fiskal alerti, `Mail::raw`)
- `app/Console/Commands/ExpirePendingReservations.php`
- `app/Console/Commands/CheckPendingPaymentStatus.php` (samo dispatch joba)

**Poslednje usklađivanje sa kodom:** dokument uveden kao canonical guardrail; pri promeni pravila u kodu **ažuriraj ovaj fajl** ili ispravi kod.
