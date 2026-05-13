# Payment states

Stanja plaćanja i fiskalizacije. Rezervacija se **uvek** kreira na **success**; neuspeh fiskalizacije **ne blokira** generisanje računa.

**Povezano:** `docs/workflow-placanje-temp-data.md`, `docs/project-conventions.md`. Greške banke često prolaze kroz **ErrorClassifier** (`resolution_reason`, `temp_data`).

---

## Payment states (flow)

- **pending** – plaćanje u toku (temp_data.status = pending).
- **success** – banka vratila SUCCESS → rezervacija se **kreira** (reservation), temp_data → processed. Fiskalizacija se pokreće u pozadini (ProcessReservationAfterPaymentJob).
- **canceled** (odbijeno / otkazano plaćanje) – banka vratila CANCEL/ERROR (ili ekvivalent) → **temp_data.status = `canceled`**. **Nema** rezervacije. *(U bazi se ne koristi posebna vrednost `failed` za ovu granu.)*

---

## Flow: pending → success → canceled

- Bank callback **SUCCESS** → rezervacija se kreira, temp_data = processed.
- Bank callback **CANCEL/ERROR** → temp_data = canceled, rezervacija se **ne** kreira.

---

## Flow: pending → success → fiscalization_failed → post_fiscalization_data

- Bank callback **SUCCESS** → rezervacija se kreira (čak i ako će fiskalizacija da padne).
- ProcessReservationAfterPaymentJob pokušava fiskalizaciju.
- **Fiskalizacija uspe** → reservation.fiscal_* set, fiskalni PDF + email.
- **Fiskalizacija ne uspe** → **fiscalization_failed**:
  - upis u **post_fiscalization_data** (reservation_id, error, attempts, next_retry_at);
  - **račun se i dalje generiše** – nefiskalni PDF sa napomenom + email (ne blokira se invoice).
- Retry fiskalizacije (cron post-fiscalization:retry) pri uspehu ažurira reservation i šalje fiskalni PDF.

---

## Timeout callback (status inquiry)

- Ako **callback od banke nikad ne stigne**, cron **payment:check-pending-inquiry** loguje **`payment_pending_too_long`** za pending starije od praga (**bez automatske promene statusa**). Kada **`PaymentStatusInquiryService::isImplemented()`** true (Bankart), komanda poziva **HTTP status inquiry**; **SUCCESS** / **ERROR** (Bankart `transactionStatus`) → **`PaymentCallbackJob`** (isti state machine kao webhook).
- Ako banka kaže **SUCCESS** → job poziva **`PaymentSuccessHandler::handle`** (rezervacija, temp_data → processed, `ProcessReservationAfterPaymentJob` kada je red). **ERROR** → job tretira kao neuspeh plaćanja (canceled + `PaymentFailed`), kao CANCEL/ERROR callback.
- Konfiguracija: `payment.pending_inquiry_after_minutes`, `payment.status_inquiry_throttle_minutes`, `payment.bankart_status_inquiry_enabled`. **Fake** driver: `isImplemented()` = false (samo log stale pending). **Real** (`RealPaymentStatusInquiryService`): GET Bankart `getByMerchantTransactionId`; logovi `payment_status_inquiry_*` u `payments`.

---

## Kasni SUCCESS (`late_success`)

- **`canceled`** je **terminalno**: banka je već vratila neuspeh / otkaz; **`temp_data` ostaje `canceled`**. Ako banka naknadno pošalje **SUCCESS** (webhook ili inquiry → **`PaymentCallbackJob`**), sistem **ne** prelazi u **`late_success`** — log **`payment_success_after_canceled_ignored`** (`payments`), bez promene statusa, i **email administratoru** (**`AdminFiscalizationAlertService::notifyPaymentSuccessAfterCanceled`**, `payment.operations_alert_email`).
- **`expired`**: cron je oslobodio lock (`reservations:expire-pending`). Kasni **SUCCESS** → **`applyLateSuccess`** → **`late_success`** (bez kreiranja rezervacije; audit + UX „kontaktirajte podršku“). V. **`PaymentCallbackJob`**.

### Agency late_success → avans (feature-flag)

Ako je `temp_data.status = late_success`, `temp_data.user_id` je set (agencija) i `config('features.advance_payments')` je ON:

- uplata se automatski konvertuje u **paid** avansni topup (`agency_advance_topups`) + ledger topup (`agency_advance_transactions`)
- `temp_data` ostaje `late_success`, uz `resolution_reason = converted_to_advance`
- **ne** kreira se rezervacija i **ne** dira se `daily_parking_data`
- iznos konverzije se uzima iz `temp_data.invoice_amount_snapshot` (legacy fallback loguje `late_success_advance_amount_snapshot_missing`)

---

## Pravila

- **Rezervacije se kreiraju na success** – čak i ako fiskalizacija kasnije ne uspe. Kreiranje rezervacije ne zavisi od fiskalnog API-ja.
- **Neuspeh fiskalizacije ne sme da blokira generisanje računa** – kupac uvek dobija račun (fiskalni ili nefiskalni) odmah posle uspešnog plaćanja.
