# Production hardening (referenca)

Kratak pregled odluka za plaćanje, fiskal, email i red. Operativna checklista: **`production-runbook.md`**.

---

## 1. HTTP timeout / retry

Helper: **`App\Support\HttpOutboundConfig`** spaja root vrednosti sa opcionim per-endpoint override-ima u **`config/http-outbound.php`**.

| Izlazni poziv | Konfiguracija | Retry |
|---------------|---------------|--------|
| **Bankart** create session | `HttpOutboundConfig::bankart('create_session')` — fallback na `bankart.connect_timeout` / `bankart.timeout` | **Nema** automatskog HTTP retry-a na debit. |
| **Bankart** status inquiry | `HttpOutboundConfig::bankart('status_inquiry')` | Nema HTTP retry u servisu; cron throttle po transakciji; ishod ide na **`PaymentCallbackJob`**. |
| **Fiskal** deposit | `HttpOutboundConfig::fiscal('deposit')` | **Jedan** unutrašnji retry para deposit+receipt na grešku **58**; `Amount=0` / `INITIAL` je formalni Primatech korak (≠ agencijski avans). Greška **56** = depozit već postavljen na ENU → nastavi na receipt. |
| **Fiskal** receipt | `HttpOutboundConfig::fiscal('receipt')` | Isto. |
| **Laravel jobovi** | N/A | Eksplicitan **`backoff()`**; idempotentnost: job komentari + `ShouldBeUnique` gde postoji. |

---

## 2. Ključni jobovi (tries / timeout / backoff)

| Job | tries | timeout (s) | backoff (s) |
|-----|-------|-------------|-------------|
| `PaymentCallbackJob` | 3 | 60 | 60, 300, 900 |
| `PaymentJob` | 3 | 60 | 30, 120, 300 |
| `ProcessReservationAfterPaymentJob` | 3 | 120 | 120, 600, 1800 |
| `SendInvoiceEmailJob` | 3 | 45 | 60, 180, 600 |
| `SendFreeReservationConfirmationJob` | 3 | 45 | 60, 180, 600 |
| `SendAdminUpdatedReservationDocumentJob` | 3 | 45 | 60, 180, 600 |

`failed()` na gore navedenima loguje u `payments` kanal kada su pokušaji istrošeni. Claim lock: **`ReservationEmailSendClaimService`** (stale **`EMAIL_SENDING`** > 15 min).

---

## 3. Log događaji (`storage/logs/payments.log`)

Strukturisani ključevi (gde je moguće: **`merchant_transaction_id`** + **`reservation_id`** / **`temp_data_id`** / **`user_id`**):

- `payment_reservation_created`, `payment_fiscal_success`, `post_fiscalization_enqueued`
- `payment_pending_too_long` — `temp_data` u **pending** duže od `payment.stale_pending_warn_after_minutes` (npr. 12); **nema promene statusa**; throttle keš po `temp_data_id` (6h)
- `invoice_email_sent` / `invoice_email_send_failed` / `invoice_email_job_exhausted`
- **`paid_invoice_email_started` / `_sent` / `_failed` / `_job_exhausted`** — strukturisani logovi (kanal **`payments`**) sa `reservation_id`, `merchant_transaction_id`, `recipient_email`, `attachment_filename`, `reservation_kind`; isti obrazac za **`free_reservation_email_*`**, **`admin_panel_reservation_update_email_*`**
- `free_reservation_email_sent` / `free_reservation_email_send_failed` / `free_reservation_email_job_exhausted` *(legacy aliasi u starijim log redovima; novi kod koristi gornji `_started/_sent/_failed` obrazac)*
- `free_reservation_request_multi_email_started` / `_sent` / `_failed` / `_skipped_already_sent` — admin **fulfill** / repair; polja `free_reservation_request_id`, `reservation_ids[]`, `recipient_email`, `attachment_filenames[]`
- `advance_topup_confirmation_started` / `_sent` / `_failed`
- `reservation_email_sending_lock_stale_reclaimed` — worker crash/OOM: **`EMAIL_SENDING`** stariji od **15 min** ponovo preuzet
- `reservation_document_resend_queued` — **`mail:resend-reservation-document`** ili servis resend-a
- `payment_callback_job_exhausted`, `payment_job_exhausted`, `process_reservation_after_payment_job_exhausted`
- **Bankart init (create session):** `bankart_create_session_request`, `bankart_create_session_response` (uspjeh), `bankart_create_session_failed` (odbijen odgovor, mreža, config, itd.) — uvek sa `merchant_transaction_id` / `amount` / `currency` gde ima smisla; telo odgovora samo kao skraćeni preview.
- **`payment_init_failed`** — checkout ili inquiry zatvorio pending bez bank session-a: `merchant_transaction_id`, `temp_data_id`, `http_status`, `reason`, `stage` (npr. `checkout_after_temp_created`, `status_inquiry_transaction_not_found`).
- **`Error classified`** — opciono polje **`stage`**: `create_session` (debit init) ili `payment_callback` (job); v. **`ErrorClassifier`** i `resolution_reason` (npr. **`bank_invalid_amount`** za amount/limit poruke banke).
- `payment_success_after_canceled_ignored` — bank **SUCCESS** stigao dok je **`temp_data` već `canceled`**; status se **ne** menja u `late_success` (`merchant_transaction_id`, `temp_data_id`); zatim **admin email** istim putem kao fiskal alerti (**`AdminFiscalizationAlertService`**, subject *Contradictory bank outcome…*). Uspeh slanja se i dalje loguje kao **`Admin fiscalization email sent`** sa `alert_type` = `payment_success_after_canceled`.
- **`guest_lower_category_checkout_blocked`** — guest checkout blokiran prije plaćanja (niža kategorija od najnovije guest **`paid`** historije iste tablice); `admin_alerts` + email.
- **`guest_paid_lower_category_alert`** — safety-net poslije plaćanja ako guest **`paid`** rezervacija ipak ima nižu kategoriju od historije (`reservation_id`, `historical_reservation_id`, cijene).
- `queue_worker_booted` — jednom po PHP procesu queue workera u **production** (event `WorkerStarting`)
- `production_fake_driver_active` — fake bank/fiscal u production (throttle keš)

Callback prima i dalje: `Payment callback received` / `accepted` / itd.

---

## 4. „Stuck“ scenariji i recovery

| Scenario | Šta postoji | Napomena |
|----------|-------------|----------|
| `temp_data` dugo **pending** | `payment:check-pending-inquiry` → **`payment_pending_too_long`** + opciono **Bankart inquiry** | Upozorenje **ne menja** status. Inquiry SUCCESS/ERROR → **`PaymentCallbackJob`**. **Transaction not found** → **`payment_init_failed`** (release lock). Throttle po `merchant_transaction_id`. |
| Plaćena rezervacija bez **fiscal_jir** | `post_fiscalization_data` + `post-fiscalization:retry` + admin | Nefiskalni email iz `ProcessReservationAfterPaymentJob`. Info **`admin_alerts`** `post_fiscalization_started` odmah pri ulasku; email eskalacija **`FISCAL ALERT`** tek **>24 h**. **Produkcija (2026-06):** svi odgođeni slučajevi uspješno fiskalizovani naknadno — pipeline potvrđen u praksi. |
| Email nije poslat | `invoice_sent_at`, `email_sent`, `paid_invoice_email_*` / `free_reservation_email_*` | Worker + retry; **`failed()`** vraća **`email_sent`** na **`EMAIL_NOT_SENT`**. Zaglavljeno **`EMAIL_SENDING`** (>15 min) → **`reservation_email_sending_lock_stale_reclaimed`**. Dijagnostika: **`php artisan mail:audit-reservation-documents --date=Y-m-d --missing-only`**. Resend: **`mail:resend-reservation-document --id=`** ili admin **Resend invoice**. Fallback cron: **`reservations:send-emails`** (samo **dispatch** jobova, ne lažno `email_sent=1`). |
| FZBR **fulfilled**, agencija nema potvrdu | `free_reservation_request_multi_email_*`, `reservations.email_sent` | `php artisan free-reservation-requests:repair-fulfilled --id=`; ako su rezervacije već `email_sent=1` a mejl nije stigao — **`--resend-email`**. Fulfill **ne** vraća zahtjev u `submitted`. |
| Gomilanje **post_fiscalization_data** | Retry komanda + admin | `next_retry_at`, brisanje sloga poslije uspjeha; info alert **`done`**; >24 h email. Ako redovi ostaju — provjeri fiskal API, worker i scheduler. |
| Job pao posle delimične obrade | Idempotentni koraci | Retry; `failed()` za ručnu proveru. |

---

## 5. Config / env

- U **production**, fake bank/fiscal → **`production_fake_driver_active`** (keš ~12h).
- **`APP_DEBUG=false`**, **`APP_URL`** HTTPS.
- **`QUEUE_CONNECTION`:** ne `sync` u produkciji.

---

## 6. `PaymentStatusInquiryService`

- **`isImplemented()`:** `false` za fake bank driver; za Bankart kada je `BANKART_STATUS_INQUIRY_ENABLED` i konfiguracija (`BANKART_API_URL`, ključevi, kredencijali, shared secret ako je potpis uključen) kompletna.
- **`inquire()`:** vraća `['outcome' => 'success'|'failed'|null, 'raw' => …]`; poziva se **samo** ako je `isImplemented()` true. **null** = pending / greška API-ja / HTTP — bez promene `temp_data` u tom koraku. Cron dispatchuje **`PaymentCallbackJob`** za `success` i `failed`.

---

## 7. Ponovljeni Bankart SUCCESS callback-i

- Nakon **`temp_data.processed`** + postojeće **`reservations`** za isti MTID, webhook je **idempotentan** u job-u, ali ponavljanja (minute → sati → dnevno) zagušuju logove i Admin Uvid timeline.
- **`PaymentCallbackDuplicateTerminalAckService`** + rana grana u **`PaymentCallbackController`**: validan potpis → ako je SUCCESS i već obrađeno → **200** + **`OK`** bez **`PaymentCallbackJob`**; log **`payment_callback_duplicate_terminal_acknowledged`**. Svi validni postback-i (uključujući prvi i FAILED) takođe vraćaju **200 OK** (Bankart/NLB zahtjev).
- **Ne** utiče na kasni SUCCESS (`expired` / `canceled`). Detalji: **`payment-callback-handling.md`** §2.
