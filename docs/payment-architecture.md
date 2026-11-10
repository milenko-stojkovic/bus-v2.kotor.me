# Payment arhitektura (redirect na banku, webhook + queue)

Korisnik se **odmah redirect-uje na bank payment page** nakon klika "Pay". Obrada rezultata plaćanja **nikad** u HTTP request-u – isključivo **webhook/callback** + **queue job**.

---

## Payment UX zahtev

1. User klikne "Pay" → **odmah redirect** na bank payment page.
2. **Flow u HTTP kontroleru:**
   - validira request
   - proverava dostupnost (kapacitet)
   - kreira **temp_data** (pending)
   - kreira **payment session** sa gatewayem (**sync**)
   - prima **payment_url**
   - **redirect** korisnika na **payment_url**
3. **Nema obrade statusa plaćanja** u HTTP request-u (success/fail obrada samo u webhook + job).

---

## Obrada rezultata (success / fail / late success)

- **Webhook / callback endpoint** – gateway poziva naš URL sa rezultatom → **`PaymentCallbackJob`**.
- **Cron status inquiry** (Bankart, kad je uključen) – komanda **`payment:check-pending-inquiry`** poziva **`PaymentStatusInquiryService::inquire()`**; na jasan **SUCCESS** / **ERROR** u odgovoru banke ponovo se šalje **isti `PaymentCallbackJob`** (payload kao kod webhooka, `raw` uključuje `source: status_inquiry`). V. **`docs/payment-states.md`**, **`docs/cron-commands.md`**.
- **Queue job** (`PaymentCallbackJob`) obavlja:
  - kreiranje rezervacije iz `temp_data` (success) ili otkaz (failed)
  - fiskalizacija / email preko **`ProcessReservationAfterPaymentJob`** i povezanih jobova
  - retries, idempotentnost

Nikad kreiranje rezervacije ili fiskalizacija u HTTP request-u niti u samoj Artisan komandi inquiry-ja – samo **dispatch `PaymentCallbackJob`** (ili validacija u callback kontroleru pa dispatch).

---

## Ako je gateway spor ili nedostupan

- Controller **ne kreira** rezervaciju.
- Controller vraća **503** sa **generičkom** porukom iz **`UiText`** (grupa `payment`, ključ zavisi od klasifikacije) — **nikad** sirovi tekst banke u odgovoru korisniku.
- **`PaymentInitFailureService`** (poziv iz **`CheckoutController::createSessionFailedResponse`**) odmah:
  - **`temp_data.status` → `canceled`**, **`resolution_reason` → `payment_init_failed`**
  - **`releaseSoftLock`** — decrement `pending` na slotovima (**Termini**)
  - log **`payment_init_failed`** (`merchant_transaction_id`, `temp_data_id`, `http_status`, `reason`, `stage`)
- Vrijedi za **novi** pending red, **postojeći** pending (retry createSession), i **daily_ticket** (bez slot lock-a).
- Ako postoji **postojeći pending** za iste slotove/korisnika ili **idempotentna grana** posle unique constraint-a, a `createSession` padne — isti tretman na tom `temp_data` redu, zatim **503** (v. `CheckoutController`).

### Bankart `createSession` (`RealPaymentProvider`)

- **Sync debit init** (HTTP POST); detalji u `config/http-outbound.php` (`bankart.create_session`).
- Iznos debit-a je `temp_data.invoice_amount_snapshot`. Provider ne čita `vehicle_types.price`. Snapshot koji nedostaje prekida pokušaj prije HTTP poziva.
- **Log kanal `payments` (strukturisano):**
  - `bankart_create_session_request` — prije HTTP poziva: `merchant_transaction_id`, `temp_data_id`, `amount`, `currency`, `stage` = `create_session`.
  - `bankart_create_session_response` — uspjeh: `http_status`, `outcome` = `success`, isti identifikatori + polja iz odgovora banke (npr. `uuid`, `purchaseId`) **bez** tajni.
  - `bankart_create_session_failed` — neuspjeh (config, encode, potpis, mreža, ne-JSON, odbijen odgovor): `reason`, `http_status` kad postoji, `response_body_preview` (skraćeno), `bank_code` / `bank_message_truncated` kad postoje, `resolution_reason` nakon klasifikacije.
- **`ErrorClassifier`** na odbijenom JSON odgovoru (npr. poruke tipa „Enter lesser amount“) mapira **`bank_invalid_amount`** → korisniku **`payment_processing_issue`**; ostale Bankart greške kao i u callback-u. **`Error classified`** log prima dodatni kontekst npr. `stage` = `create_session`.
- U **callback** grani, **`PaymentCallbackJob`** šalje u klasifikator kontekst **`stage` = `payment_callback`** (samo logiranje).

---

## Striktna pravila za HTTP controller (checkout)

Controller **samo**:

1. **Validira** podatke (CheckoutReservationRequest).
2. **Proverava dostupnost** (DailyParkingData za datum + termin, availableCapacity >= 1).
3. **Kreira** zapis u **temp_data** sa **status = pending**.
4. **Poziva** **PaymentService::createSession($temp)** (sync) – provider iza interfejsa.
5. Ako **payment_url** → **redirect** na payment_url.
6. Inače → **503** sa **bezbednom** porukom (`UiText` / `ErrorClassifier`), ne sirovim bankarskim tekstom.

Controller **nikad**:

- Ne obrađuje rezultat plaćanja (success/fail) – to radi webhook + job.
- Ne kreira rezervaciju ni ne poziva fiskalizaciju u request-u.

---

## Webhook endpoint

- **`POST /api/payment/callback`** – bank callback na **API ruti** (`routes/api.php` → `payment/callback`), **ne** na web.
- **Machine-to-machine only.** Nikad ne koristiti ovaj endpoint za frontend redirect ili UI flow. **Frontend NIKAD ne sme da poziva bank callback.**
- Nema web middleware (session, CSRF, redirects) – banka ne šalje cookies/CSRF; stateless.
- Controller: validacija potpisa, validacija payload-a, **dispatch PaymentCallbackJob(payload)**.
- Vraća **HTTP 200** + telo **`OK`** (`text/plain`) na validan postback, ili **400 Bad Request** JSON na grešku. Nikad redirect.
- User redirect se radi kasnije preko frontend polling-a /payment/result.
- Za test: fake bank koristi **poseban** endpoint POST /payment/fake-bank/complete (web), ne bank callback.

---

## Queue

- **Lokalno:** ili `php artisan queue:work`, ili u `.env` **`QUEUE_CONNECTION=sync`** da se jobovi izvrše odmah (preporuka za QA — v. `docs/project-conventions.md`).
- **Produkcija:** Supervisor (ili drugi process manager) drži `php artisan queue:work` uvek aktivan.

---

## Provider iza interfejsa

- **PaymentService** (interface): `createSession(TempData): PaymentSessionResult`, `pay(TempData): PaymentResult`.
- **PaymentSessionResult**: success, payment_url (za redirect), error_message, opciono `httpStatus` / `failureReason` (za init failure log).
- **FakePaymentProvider**: createSession vraća URL na fake bank stranicu (/payment/fake-bank?tx=...); pay() za test state machine.
- **RealPaymentProvider**: createSession() poziva Bankart kada je `BANK_DRIVER=bankart` i `.env` kompletan (v. `.env.example`).
- Config: `config/payment.php` → driver iz `BANK_DRIVER` (npr. `fake` \| `bankart`).

---

## Flow (kratko)

1. **POST /checkout** → validacija, dostupnost, temp_data (pending), createSession(sync) → **redirect na payment_url** ili 503.
2. Korisnik plaća na bank stranici (ili na fake bank stranici bira Success/Fail).
3. **Ulaz u state machine:** **Put A — webhook:** gateway šalje **`POST /api/payment/callback`** → validacija potpisa/payload-a → **`PaymentCallbackJob::dispatch`** (ili duplicate-terminal shortcut) → **200 OK**. **Put B — Bankart inquiry:** ako callback ne stigne, **`payment:check-pending-inquiry`** (scheduler) poziva banku; na jasan SUCCESS/ERROR → opet **`PaymentCallbackJob::dispatch`** (v. **`CheckPendingPaymentStatus`**).
4. **PaymentCallbackJob**: na success → Reservation, **temp_data.status = processed** (red se **ne briše** — audit); **`ProcessReservationAfterPaymentJob`** iz **`PaymentSuccessHandler`**: za **async** webhook uvek kada treba fiskal/mejl pipeline (uključujući **oba fake** drivera). Izuzetak: **`FakeBankCompleteController`** šalje callback preko **`QueueMode::dispatchPaymentCallbackSyncForFakeQaForm`** (`deferFakeBankFiscalPipeline: true`) — handler tada **ne** dispatchuje pipeline; odmah posle toga forma šalje **`ProcessReservationAfterPaymentJob`** preko **`QueueMode::dispatchForFakeE2e`** (sync vs queue prema **`FAKE_PAYMENT_E2E_SYNC`** i oba fake drivera). Na failed → ažuriranje `temp_data` + **ErrorClassifier**; na timeout (još uvek **pending**) → `late_success` gde je predviđeno. Ako je **`temp_data` već terminalan:** **`processed`** → no-op; **`expired`** + kasni success → **`late_success`**; **`canceled`** + success → ignorisano (**`payment_success_after_canceled_ignored`**).
5. UI može koristiti **GET /reservation-status/{merchant_transaction_id}** (polling) za status.

---

## Fake QA stranica (test — banka + fiskal u jednom koraku)

- **GET /payment/fake-bank?tx={merchant_transaction_id}** — jedna forma: **bank_scenario** (A) + **fiscal_scenario** (B, aktivno samo kad je banka success), jedan **POST /payment/fake-bank/complete**.
- **GET /fake-bank/complete?tx=...&scenario=...&fiscal_scenario=...** — backward compat (bez `fiscal_scenario` podrazumijeva fiskal **success**).
- **Frontend NIKAD ne poziva** `POST /api/payment/callback`.
- Nakon complete: redirect na **`/payment/return?merchant_transaction_id=...`**, zatim uobičajeni banner + redirect na guest/panel.

### Fake QA: sync vs queue režim (terminologija)

Pretpostavka: **`BANK_DRIVER=fake`**, **`FISCALIZATION_DRIVER=fake`**.

- **Fake QA queue režim:** **`QUEUE_CONNECTION=database`** (ili redis), **`FAKE_PAYMENT_E2E_SYNC=false`**. **`PaymentCallbackJob`** (async webhook), **`ProcessReservationAfterPaymentJob`**, **`SendInvoiceEmailJob`** (preko **`QueueMode::dispatchForFakeE2e`**) idu na **red** — potreban **`queue:work`**.
- **Fake QA sync režim:** **`FAKE_PAYMENT_E2E_SYNC=true`**. Pipeline jobovi iz **`QueueMode`** idu **`dispatch_sync`** — **worker nije bitan** za taj tok (čak i ako je `QUEUE_CONNECTION=database`, sync i dalje zaobilazi red za te dispatche).

**`FAKE_PAYMENT_E2E_SYNC`** = prekidač sync vs queue za fake QA pipeline. **`QUEUE_CONNECTION=database`** samo omogućava red; ne garantuje ga ako se koristi **`dispatch_sync`** (sync režim ili uvijek-sync **`QueueMode::dispatchPaymentCallbackSyncForFakeQaForm`** na fake-bank formi).

---

## Fajlovi

| Fajl | Namena |
|------|--------|
| `App\Contracts\PaymentService` | Interface – createSession (sync), pay (za job). |
| `App\Contracts\PaymentSessionResult` | success, payment_url, error_message. |
| `App\Contracts\PaymentResult` | success / failed / timeout (za pay()). |
| `App\Services\Payment\FakePaymentProvider` | createSession → fake bank URL; pay() simulacija. |
| `App\Services\Payment\RealPaymentProvider` | createSession → Bankart (real). |
| `App\Jobs\PaymentCallbackJob` | Idempotentan po merchant_transaction_id; rezervacija, fiskalizacija, status. |
| `App\Jobs\ProcessReservationAfterPaymentJob` | Fiskalizacija + PDF/email posle uspešnog plaćanja (v. success-payment-pipeline.md). |
| `App\Services\Payment\PaymentInitFailureService` | createSession / inquiry not found → `canceled`, `payment_init_failed`, release lock. |
| `App\Http\Controllers\CheckoutController` | Validacija, dostupnost, temp_data, createSession, redirect ili 503. |
| `App\Http\Controllers\Api\PaymentCallbackController` | API callback: validacija potpisa + payload, dispatch job (ili duplicate-terminal ACK), **200 OK** / **400**. |
| `config/payment.php` | Bankart/fake driver preko `BANK_DRIVER` i povezane env varijable. |
| `App\Http\Controllers\ReservationStatusController` | Polling: GET po merchant_transaction_id. |
| `App\Support\QueueMode` | Centralno: **`useSyncForFake()`** (oba fake + `payment.fake_e2e_sync`); **`dispatchForFakeE2e($job)`** (sync ili `dispatch`); **`dispatchPaymentCallbackSyncForFakeQaForm`** (uvijek sync u fake formi). |
| `App\Http\Controllers\FakeBankCompleteController` | Samo test: POST kombinovana forma + GET complete; callback uvijek sync preko `QueueMode`; kad su oba drivera fake i bank success → `ProcessReservationAfterPaymentJob` preko `QueueMode::dispatchForFakeE2e`. |
| `App\Console\Commands\CheckPendingPaymentStatus` | Cron: log stale pending; Bankart inquiry → `PaymentCallbackJob` (throttle po tx). |
| `App\Contracts\PaymentStatusInquiryService` | Fake: `isImplemented()` false. Bankart: `RealPaymentStatusInquiryService` — GET `getByMerchantTransactionId`. |
| `App\Support\BankartSignature` | Zajednički HMAC potpis za Bankart POST (debit) i GET (status inquiry). |

---

## Success payment pipeline (fiskalizacija → PDF → email)

V. **docs/success-payment-pipeline.md**: pending → processed → create reservation (bez fiscal polja, sa **invoice_amount** snapshot) → ProcessReservationAfterPaymentJob → na uspeh fiscal_* + SendInvoiceEmailJob (PDF on-the-fly); mail sa bus@kotor.me.

---

## Callback handling (CANCEL/ERROR, idempotency, redirect)

V. **docs/payment-callback-handling.md**: validacija potpisa (400 + log), idempotentnost (final status), CANCEL/ERROR → failed + raw payload + oslobodi soft-lock, PaymentFailed event, redirect guest/auth, GET /payment/result.

---

## Concurrency

V. **docs/payment-concurrency.md**: jedinstven merchant_transaction_id, validacija potpisa u callback-u, idempotentnost job-a, nema deljenog stanja, paralelna plaćanja.

---

## Provera (checklist)

- [x] User se redirect-uje na bank payment page odmah nakon "Pay".
- [x] Controller: validacija, provera dostupnosti, temp_data (pending), createSession (sync), redirect ili 503.
- [x] Nema obrade statusa plaćanja u HTTP request-u.
- [x] Rezultat plaćanja: webhook/callback + **PaymentCallbackJob**; opciono ista grana preko **cron status inquiry** (Bankart).
- [x] Ako gateway spor/nedostupan: 503, bez kreiranja rezervacije.
- [x] Payment provider iza interfejsa (PaymentService, Fake/Real).
- [x] Queue obavezna za PaymentCallbackJob.
