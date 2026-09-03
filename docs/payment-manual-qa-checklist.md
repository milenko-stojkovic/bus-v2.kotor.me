# Payment Manual QA Checklist / Test Matrix

Poslednje ažuriranje: 2026-04-02  
Namena: operativni vodič za ručno testiranje payment + fiskalizacija + retry + late manual review flow-a.

**Indeks statusa / TODO / konvencije:** `docs/project-status-next-steps.md`, `docs/project-conventions.md`.

---

## 1) Prerequisites / Setup

## 1.1 Environment flags

Za lokalni QA (preporučeno):

```env
APP_ENV=local
APP_DEBUG=true
BANK_DRIVER=fake
FISCALIZATION_DRIVER=fake
BANK_DRIVER=fake
MAIL_MAILER=log
# Za QA bez workera koristiti sync (v. docs/project-conventions.md):
QUEUE_CONNECTION=sync
# Alternativa: QUEUE_CONNECTION=database + php artisan queue:work
```

Za staging QA:

- Ako testiraš funkcionalni flow bez realnih eksternih servisa: isto kao lokalno (`BANK_DRIVER=fake`, `FISCALIZATION_DRIVER=fake`).
- Ako testiraš realnu integraciju: `BANK_DRIVER=bankart`, `FISCALIZATION_DRIVER=real`, validni `BANKART_SHARED_SECRET`, `FISCAL_API_URL`, `FISCAL_API_TOKEN`.

## 1.2 Obavezni runtime procesi

- Queue worker mora biti aktivan:
  - `php artisan queue:work`
- (Opcionalno za scenarije sa cron ponašanjem) pokreni scheduler:
  - `php artisan schedule:work`

## 1.3 DB priprema

- Pokreni:
  - `php artisan migrate`
  - `php artisan db:seed`
- Proveri da postoji admin korisnik (role `admin` ili email prisutan u `admins` tabeli) za test `admin/late-success` ruta.

## 1.4 Ključne rute za QA

- Checkout: `POST /checkout`
- Fake QA (banka + fiskal): `GET /payment/fake-bank?tx=...` → `POST /payment/fake-bank/complete`; ili `GET /fake-bank/complete?tx=...&scenario=...&fiscal_scenario=...`
- Payment result: `GET /payment/result?merchant_transaction_id=...`
- Retry API: `GET /api/reservations/retry/{retry_token}`
- Fake fiscal API:
  - `POST /api/fake-fiscalization`
  - `POST /api/efiscal/deposit`
  - `POST /api/efiscal/fiscalReceipt`
- Admin late manual review (staff — `User` + `AdminMiddleware`):
  - `GET /staff/late-success`
  - `GET /staff/late-success/{id}`
  - `POST /staff/late-success/{id}/force`
  - `POST /staff/late-success/{id}/reject`

---

## 2) Test Matrix

Svaki scenario validirati kroz:
- DB: `temp_data`, `reservations`, `post_fiscalization_data`
- UI rezultat
- logove (`storage/logs/payments.log`, `laravel.log`)
- side effects (PDF, email, fiscal polja, `retry_token`)

---

### A) Regular success

| Polje | Vrednost |
|---|---|
| Scenario | Guest checkout -> fake QA forma (bank success + fiskal success) -> success pipeline |
| Preconditions | `BANK_DRIVER=fake`, `FISCALIZATION_DRIVER=fake`; za sync obradu bez workera `FAKE_PAYMENT_E2E_SYNC=true` i `QUEUE_CONNECTION=sync` (ili `queue:work`) |
| Steps | 1) Submit checkout 2) Sačuvaj `merchant_transaction_id` 3) Otvori `/payment/fake-bank?tx={tx}` 4) Izaberi bank **success** + fiskal **success**, **Submit** (ili GET complete sa `fiscal_scenario`) |
| Expected DB state | `temp_data.status=processed`; postoji `reservations` red sa istim `merchant_transaction_id`; snapshot polja popunjena |
| Expected UI | Nakon obrade: **redirect** na `guest.reserve` ili `panel.reservations` sa **success/info** `checkout_banner`; na `/payment/return` ostaje samo **pending** sa polling-om dok callback ne završi |
| Expected logs | "Payment callback accepted", "Payment state transition ... -> processed" |
| Side effects | `invoice_amount` snapshot set; `invoice_sent_at` set; email poslat (ili zabeležen preko `MAIL_MAILER=log`); PDF nije u `storage/` |

---

### B) Payment fail / cancel

| Polje | Vrednost |
|---|---|
| Scenario | Bank cancel/error callback |
| Preconditions | Fake bank aktivna; worker aktivan |
| Steps | Na formi izaberi bank scenario **cancel** / **expired** / … (ne success), **Submit**; ili `GET /fake-bank/complete?tx={tx}&scenario=cancel` |
| Expected DB state | `temp_data.status=canceled`; nema novog reda u `reservations`; `callback_error_code/reason` popunjeni ako payload sadrži |
| Expected UI | **Redirect** na booking stranicu sa **error** `checkout_banner` (mapiran `resolution_reason`); gost sa `retry_token` na `/guest/reserve?retry_token=...` |
| Expected logs | "Payment callback accepted", state transition `pending -> canceled` |
| Side effects | `retry_token` ostaje važeći unutar TTL; slot soft-lock oslobođen |

---

### C) Guest retry flow

| Polje | Vrednost |
|---|---|
| Scenario | Guest posle failed plaćanja dobija retry bez ponovnog unosa |
| Preconditions | Prethodno izveden fail scenario; `temp_data` ima `retry_token` |
| Steps | 1) Otvori `/reservations?retry_token={token}` 2) Frontend poziva `/api/reservations/retry/{token}` |
| Expected DB state | Stari `temp_data` ostaje audit zapis; novi checkout pokušaj dobija novi `merchant_transaction_id` |
| Expected UI | Forma repopulisana starim podacima; korisnik može ponovo da plati |
| Expected logs | Novi "Payment init" za novi pokušaj |
| Side effects | Novi payment attempt je nezavisan od prethodnog |

---

### D) Fiscalization success

| Polje | Vrednost |
|---|---|
| Scenario | Fiskalizacija uspešna u `ProcessReservationAfterPaymentJob` |
| Preconditions | Success payment scenario; fake fiskal vraća success |
| Steps | Izvedi regular success |
| Expected DB state | `reservations.fiscal_jir` i `fiscal_ikof` popunjeni; nema nerešenog `post_fiscalization_data` |
| Expected UI | Success flow bez greške |
| Expected logs | Logovi fiskalizacije + success pipeline |
| Side effects | Fiscal PDF + email |

---

### E) Fiscalization fail

| Polje | Vrednost |
|---|---|
| Scenario | Fiskalizacija padne, rezervacija ostaje validna |
| Preconditions | Simuliraj fail (`forceFail=true` ili `X-Fake-Fail: 1`) |
| Steps | Trigger success payment, ali fiskal endpoint vrati error |
| Expected DB state | Rezervacija postoji; insert u `post_fiscalization_data`; `fiscal_jir` ostaje null |
| Expected UI | Korisnik vidi success rezervacije (plaćanje je prošlo) |
| Expected logs | Fiskal failure + eventualni retry trag |
| Side effects | Non-fiscal PDF + email poslati; rezervacija se ne rollback-uje |

---

### F) Late success auto flow

| Polje | Vrednost |
|---|---|
| Scenario | Callback `timeout` / SUCCESS posle zatvaranja prozora |
| Preconditions | Pripremljen `temp_data` koji ne može u regular `processed` (istek/late slučaj) |
| Steps | Pošalji callback sa statusom `timeout` ili zakašneli `success` |
| Expected DB state | Trenutni sistem koristi `late_success`/`late_manual_review` putanju (zavisno od migracija i workflow-a) |
| Expected UI | `late_success` poruka umesto `pending` |
| Expected logs | State transition ka late statusu |
| Side effects | Nema automatske rezervacije dok se ne odradi manual review ili dok auto-resolution nije aktivno implementiran |

Napomena: za QA obavezno evidentirati da li u trenutnoj grani koda postoji automatsko razrešenje ili ide direktno u admin review.

---

### G) Late manual review admin flow

| Polje | Vrednost |
|---|---|
| Scenario | Admin force create |
| Preconditions | `temp_data.status=late_manual_review`; admin pristup aktivan |
| Steps | 1) `GET /staff/late-success` 2) Otvori detalje 3) `POST /staff/late-success/{id}/force` |
| Expected DB state | Ako reservation ne postoji: kreira se `reservations` red; `temp_data.status=processed`; `resolution_reason=admin_forced` |
| Expected UI | Success flash poruka |
| Expected logs | State transition log sa razlogom admin force |
| Side effects | Dispatch `ProcessReservationAfterPaymentJob` samo za novokreiranu rezervaciju |

| Polje | Vrednost |
|---|---|
| Scenario | Admin reject |
| Preconditions | `temp_data.status=late_manual_review` |
| Steps | `POST /staff/late-success/{id}/reject` |
| Expected DB state | `temp_data.status=late_rejected`; `resolution_reason=admin_rejected`; nema reda u `reservations` |
| Expected UI | Success/info poruka o reject-u |
| Expected logs | State transition ka `late_rejected` |
| Side effects | Nema fiskalizacije, nema PDF/email |

---

### H) Authenticated user flow

| Polje | Vrednost |
|---|---|
| Scenario | Auth korisnik, failed payment |
| Preconditions | Ulogovan korisnik |
| Steps | Pokreni checkout pa `status=error` |
| Expected DB state | `temp_data.status=canceled` |
| Expected UI | Redirect na `profile.reservations` (u ovom projektu dalje vodi na dashboard sa porukom) |
| Expected logs | Callback accepted + canceled transition |
| Side effects | Bez kreiranja rezervacije |

---

### I) Duplicate / idempotency cases

| Polje | Vrednost |
|---|---|
| Scenario | Duplicate callback za isti `merchant_transaction_id` |
| Preconditions | Već obrađen success callback |
| Steps | Ponovo pošalji isti callback payload |
| Expected DB state | Nema duple rezervacije; i dalje jedan `reservations` red za `merchant_transaction_id` |
| Expected UI | Bez promene |
| Expected logs | Može postojati log prijema callback-a, ali bez novog transition-a ka novoj rezervaciji |
| Side effects | Nema duplog PDF/email iz callback obrade |

| Polje | Vrednost |
|---|---|
| Scenario | Repeat admin forceCreate klik |
| Preconditions | Za isti `merchant_transaction_id` već postoji reservation |
| Steps | Ponovo klikni force na istom admin detail ekranu |
| Expected DB state | Bez izmene reservation reda; bez duplog reservation; `temp_data` bez neželjenih promena |
| Expected UI | "Reservation already exists, no action taken." |
| Expected logs | Bez novog dispatch-a posle existing branch |
| Side effects | Nema duplog `ProcessReservationAfterPaymentJob`; nema duplog `post_fiscalization_data` iz ovog puta |

---

## 3) Failure Modes (obavezna verifikacija)

## 3.1 Duplicate callback

- Očekivanje: ne sme da napravi duplu rezervaciju.
- Provera:
  - `select count(*) from reservations where merchant_transaction_id = '{tx}'` mora biti `1`.

## 3.2 Double click / repeat forceCreate

- Očekivanje: bez duple rezervacije i bez duplog dispatch-a.
- Provera:
  - ponoviti `forceCreate` na istom zapisu;
  - dobiti poruku: `Reservation already exists, no action taken.`

## 3.3 Failed payment (guest vs auth)

- Guest: mora dobiti retry flow (`retry_token`, repopulacija forme).
- Auth: povrat ka profilu (`profile.reservations` -> dashboard poruka).

## 3.4 Fiscalization failure

- Očekivanje:
  - reservation ostaje validna,
  - postoji `post_fiscalization_data`,
  - šalje se non-fiscal račun.

## 3.5 Late success

- Ako auto-resolution postoji i uspe: treba da završi kao normalan success pipeline.
- Ako ne uspe / nije aktivan: mora ići u `late_manual_review` putanju za admin obradu.

## 3.6 Admin reject

- Očekivanje:
  - nema reservation reda,
  - `temp_data.status=late_rejected`,
  - `resolution_reason=admin_rejected`.

## 3.7 Queue down

- Simptomi:
  - callback accepted, ali status ostaje `pending` duže od očekivanog,
  - nema fiskalizacije / PDF / email.
- Provera:
  - da li `php artisan queue:work` radi,
  - da li postoje jobovi u `jobs` tabeli (`database` queue),
  - `failed_jobs` tabela.

---

## 4) Common Failure Diagnostics

## 4.1 Callback ne radi

- Proveri da callback ide na `POST /api/payment/callback`.
- Proveri potpis (real gateway): `BANKART_SHARED_SECRET`, `PAYMENT_CALLBACK_PATH`.
- Proveri `payments.log` za "Payment callback signature invalid".
- Proveri da payload ima `merchant_transaction_id` i validan `status`.

## 4.2 Queue ne obrađuje

- Proveri aktivan worker proces.
- Proveri `QUEUE_CONNECTION` i da li je queue backend ispravan.
- Proveri `failed_jobs` i exception stack trace.

## 4.3 Fiskalizacija ne prolazi

- Proveri `FISCALIZATION_DRIVER`.
- Za real: proveri `FISCAL_API_URL`, `FISCAL_API_TOKEN`, mrežnu dostupnost.
- Za fake: proveri da li je simuliran fail (`forceFail`, `X-Fake-Fail`).
- Proveri `post_fiscalization_data` (`next_retry_at`, `attempts`, `resolved_at`, `error`).
- **Scheduled retry:** `php artisan post-fiscalization:retry` (samo redovi sa `next_retry_at <= now()`).
- **Ciljani ručni retry** nakon što je spoljašnji problem riješen (npr. `next_retry_at IS NULL`):
  - `php artisan post-fiscalization:retry --reservation=<reservation_id> --force`
  - ili `--id=<post_fiscalization_data.id> --force`
  - **Ne** bulk `--force` bez cilja.
- **Staff UI:** `/staff/reservations` → **Retry fiskalizaciju** / **Označi rešeno** / **Pošalji račun ponovo**.
- **Recovery sweep:** nakon uspješne nove fiskalizacije, async `PostFiscalizationRecoveryJob` pokušava batch nerešenih sa `next_retry_at IS NULL` (cooldown 15 min). Log: `post_fiscalization_recovery_*` u `payments`. Env: `POST_FISCALIZATION_RECOVERY_*`.
- Detalji: **`docs/cron-commands.md`** §1b, **`docs/production-runbook.md`**, **`docs/success-payment-pipeline.md`**.

## 4.4 PDF nije generisan

- Proveri da je **`SendInvoiceEmailJob`** (ili panel **`PaidInvoicePdfGenerator`**) izvršen bez greške u logu (npr. **`SendInvoiceEmailJob failed`** / izuzetak iz DomPDF-a).
- Proveri `reservations.invoice_amount` (mora biti postavljen za plaćene).
- PDF se ne čuva trajno; privremeni fajl za mail koristi sistemski temp dir (`sys_get_temp_dir()`).

## 4.5 Email nije poslat

- Proveri da **`queue:work`** radi (`QUEUE_CONNECTION` ≠ `sync`).
- `php artisan mail:audit-reservation-documents --date=YYYY-MM-DD --missing-only`
- U **`storage/logs/payments.log`**: `paid_invoice_email_started` / `_sent` / `_failed` (ili `free_reservation_email_*`) po `merchant_transaction_id` / `reservation_id`.
- Resend: `php artisan mail:resend-reservation-document --id=<reservation_id>` ili staff **Pošalji račun ponovo** (`/staff/reservations`).
- Proveri `MAIL_MAILER`, `MAIL_FROM_ADDRESS`; `reservations.invoice_sent_at`, `email_sent`.
- Napomena: jobovi **ne šalju** mail bez uspešnog PDF-a; **`renderBinary`** baca ili job fail-uje → **queue retry**; **`invoice_sent_at`** samo poslije uspješnog `Mail::send`.

## 4.6 Late success ne ulazi u admin review

- Proveri stvarni status u `temp_data` (`late_success`, `late_manual_review`, `late_rejected`).
- Proveri da su migracije za late manual review pokrenute.
- Proveri da admin korisnik ima pristup `admin` middleware-u.

---

## 5) Quick SQL checks (copy/paste)

```sql
-- Temp data po tx
select id, merchant_transaction_id, status, resolution_reason, retry_token, created_at, updated_at
from temp_data
where merchant_transaction_id = :tx;

-- Reservation po tx
select id, merchant_transaction_id, status, fiscal_jir, invoice_amount, invoice_sent_at
from reservations
where merchant_transaction_id = :tx;

-- Post fiscalization fallback
select id, reservation_id, merchant_transaction_id, error, attempts, next_retry_at, resolved_at
from post_fiscalization_data
where merchant_transaction_id = :tx
order by id desc;
```

---

## 6) Exit criteria za QA cycle

- Svi scenario blokovi A-I izvršeni najmanje jednom.
- Svi obavezni failure modes verifikovani.
- Nema duplih rezervacija za isti `merchant_transaction_id`.
- Guest retry i admin late manual review potvrđeni.
- Fiskal fail fallback potvrđen (`post_fiscalization_data` + non-fiscal invoice).
- Ručni `--force` retry i/ili recovery sweep ponašanje poznato operativnom timu (v. §4.3).
