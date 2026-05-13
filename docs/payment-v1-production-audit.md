# Payment V1 – provjera za produkciju (bez izmjena u V1 logici)

Datum provjere: prema checklisti. Cilj: potvrditi da je implementacija stabilna, konzistentna i spremna za produkciju.

---

## 1️⃣ Generisanje merchant_transaction_id

| Zahtjev | Status | Napomena |
|--------|--------|----------|
| Generiše se na backendu u trenutku klika "Plati / Rezerviši" | ✅ | `CheckoutController::store()` – `Str::uuid()` prije transakcije i kreiranja temp_data |
| Generiše se prije slanja job-a u queue | ✅ | Nema job-a za init u V1 (v. točku 2); ID se generiše prije createSession i redirecta |
| Svaki pokušaj plaćanja ima novi ID | ✅ | Novi UUID po svakom novom temp_data (dupli klik za isti slot koristi postojeći pending, ne novi ID) |
| ID se čuva u kontekstu (guest: temp_data; auth: user) | ✅ | temp_data.merchant_transaction_id; auth koristi isti temp_data sa user_id |
| Callback od banke mapira isključivo preko merchant_transaction_id | ✅ | `PaymentCallbackController` validira payload, `PaymentCallbackJob` traži `TempData::where('merchant_transaction_id', $txId)` |

**Zaključak:** Konzistentno. Backend je jedini izvor merchant_transaction_id.

---

## 2️⃣ Queue & async plaćanje (bez sync poziva gatewaya)

| Zahtjev | Status | Napomena |
|--------|--------|----------|
| Nema direktnog HTTP poziva ka gateway-u iz web request-a | ⚠️ **V1 odstupanje** | U V1 se **createSession() poziva sync** u `CheckoutController` – to je poziv prema gateway-u (dobijanje payment_url). Redirect na banku se dešava u istom requestu. |
| Gateway poziv isključivo kroz Queue Job | ❌ | Init flow: web request → createSession(sync) → redirect. **PaymentJob** postoji u kodu ali **nikad se ne dispatch-uje** iz checkouta. |
| Job idempotentan | ✅ | **PaymentCallbackJob** (callback od banke): provjera `Reservation::exists(merchant_transaction_id)`, `temp->isTerminal()`, lock; nema duplih rezervacija. |
| Queue worker dokumentovan | ✅ | `docs/payment-architecture.md`: lokalno `php artisan queue:work`, produkcija Supervisor. |

**Zaključak:** V1 logika je **sync createSession u web requestu**. To je **namjerno** (v. sekciju „Namjerna odstupanja“). Za potpuno async flow trebalo bi uvesti dispatch PaymentJob-a koji poziva createSession i vraća payment_url (npr. preko pollinga). **Nema promjena u V1.**

---

## 3️⃣ API callback (ne web ruta)

| Zahtjev | Status | Napomena |
|--------|--------|----------|
| Callback rute isključivo u routes/api.php | ✅ | `POST /api/payment/callback` u `routes/api.php` |
| Bez session, cookie, CSRF | ✅ | API rute ne koriste web middleware (Laravel default za api.php) |
| Validira potpis banke | ✅ | **RealCallbackSignatureValidator** (Bankart HMAC) koristi `services.bankart.shared_secret` (env `BANKART_SHARED_SECRET`). Za test: **FakeCallbackSignatureValidator** vraća `true`. |
| Čita merchant_transaction_id, mapira na payment attempt | ✅ | Validacija `merchant_transaction_id` (required, max:64); job pronalazi temp_data po njemu |

**Zaključak:** Ruta i mapiranje su ispravni. Prije produkcije proveriti da je `BANKART_SHARED_SECRET` ispravno podešen i da banka šalje očekivane header-e (`X-Signature`, `Date`/`X-Date`, `Content-Type`).

---

## 4️⃣ Flow grane – pending → processed / failed / late_success

| Zahtjev | Status | Napomena |
|--------|--------|----------|
| cancel/error: oslobađa rezervisani slot (lock) | ✅ | **releaseSoftLock** dekrementira `daily_parking_data.pending`; red u temp_data **ostaje** (audit). Nema brisanja redova. |
| Oslobađaju se slotovi / privremeni lock-ovi | ✅ | `PaymentSuccessHandler::releaseSoftLock($temp, false)` u `handleCanceled` |
| Guest vraćen na formu; auth na panel | ✅ | `PaymentReturnController`: redirect na **`guest.reserve`** (ili `?retry_token=`), auth → **`panel.reservations`**; flash **`checkout_banner`** (`CheckoutResultFlash` / `checkout_result`). Ruta **`/reservations`** i dalje redirectuje na guest reserve sa query stringom. |
| Poruka lokalizovana (cg/en) | ✅ | `__()` u view-u i resolveru; SetLocale za web |
| failed: oznaka failed, nema upisa u reservations, dozvoljen retry | ✅ | status canceled/expired; retry_token i GET /api/reservations/retry/{token}; novi pokušaj = novi merchant_transaction_id |
| late_success: sistem prepoznaje kasni odgovor banke | ✅ | `PaymentCallbackJob` → `applyLateSuccess` **samo** ako je `temp_data` bio **`expired`** (ne i `canceled`); temp_data → late_success |
| late_success: ako nije validna – incident za manual review | ⚠️ | **AssignLateSuccessReservations** je namjerno **stub**: late_success redovi ostaju u temp_data za admin pregled; automatsko kreiranje rezervacije nije u V1 (v. „Namjerna odstupanja“). |
| late_success: ako validna – normalan success flow | ⚠️ | Isto: cron ne kreira rezervaciju u V1; planirano za kasniju fazu ili ručnu obradu. |
| UI: late_success ne prikazuje se kao "pending" | ✅ | `PaymentResultResolver` vraća `status: 'late_success'`; `PaymentReturnController` radi **redirect** sa **`checkout_banner`** (info), ne meša se u pending ekran. |

**Zaključak:** Cancel/error i failed flow su konzistentni. late_success je prepoznat i prikazan; obrada (kreiranje rezervacije / incident) je u planu (cron stub).

---

## 5️⃣ Success flow

| Zahtjev | Status | Napomena |
|--------|--------|----------|
| pending → success → reservations → fiskalizacija → račun → email | ✅ | `PaymentSuccessHandler::handle` → `createReservationFromTempData` → `ProcessReservationAfterPaymentJob` → fiskalizacija → PDF + email |
| Upis u reservations odmah nakon potvrde banke | ✅ | U istoj transakciji kao temp_data → processed |
| Račun se generiše i šalje bez obzira na fiskalizaciju | ✅ | `ProcessReservationAfterPaymentJob`: on failure fiskalizacije → `PostFiscalizationData`, zatim `dispatchPdfAndEmail($reservationId, false)` |
| Ako fiskalizacija padne: post_fiscalization_data, nefiskalizovani račun, napomena | ✅ | `PostFiscalizationData::create`; PDF + email s `isFiscal = false` |
| Naknadna fiskalizacija → fiskalizovani račun na mail | ✅ | `post-fiscalization:retry` cron + pipeline za ponovni mail |
| Email s bus@kotor.me | ✅ | `config/mail.php`: `MAIL_FROM_ADDRESS` default `bus@kotor.me` |
| Jezik maila: auth = users.lang; guest = preferred_locale (browser) | ✅ | `SendInvoiceEmailJob`: auth → `user->lang`, guest → `reservation->preferred_locale` (postavljeno pri checkoutu) |

**Zaključak:** Success flow i fiskalizacija/email su u skladu s zahtjevima.

---

## 6️⃣ Snapshot podaci u reservations

| Polje | Status | Napomena |
|-------|--------|----------|
| user_name | ✅ | `PaymentSuccessHandler::createReservationFromTempData` + fillable |
| email | ✅ | Isto |
| license_plate | ✅ | Isto |
| vehicle_type_id | ✅ | Isto |
| country | ✅ | Isto |
| Ne oslanja se na relacije nakon plaćanja | ✅ | Sva polja kopiraju se iz temp_data; reservation ima snapshot bez obzira na user/vehicle kasnije promjene |

**Zaključak:** Rezervacija se gradi isključivo iz snapshot polja temp_data.

---

## 7️⃣ UX – guest forma nakon failed plaćanja

| Zahtjev | Status | Napomena |
|--------|--------|----------|
| Forma se repopuliše iz temp_data | ✅ | GET /api/reservations/retry/{retry_token} vraća polja za formu; frontend treba pozvati i popuniti polja kad je retry_token u URL-u |
| Guest ne mora ponovo unositi podatke | ✅ | Omogućeno putem retry_token i API-ja za retry |
| temp_data se "briše" tek kada se završi grana | ✅ | Redovi se **ne brišu** (audit); status prelazi u processed/canceled/expired/late_success. Retry API vraća podatke samo za status canceled/expired i unutar retry_token_valid_minutes. |

**Zaključak:** Konzistentno s pravilom "ne brišemo temp_data, ažuriramo status".

---

## 8️⃣ Logovanje i audit

| Faza | Status | Gdje |
|------|--------|------|
| init | ✅ | **Dodano:** `CheckoutController` nakon kreiranja temp_data: `Log::channel('payments')->info('Payment init', ['merchant_transaction_id', 'retry_token'])` |
| job dispatch | N/A | Init flow nema dispatch job-a u V1; callback: job se dispatch-uje u controlleru (nije posebno logirano osim "callback accepted") |
| callback received | ✅ | `PaymentCallbackController`: "Payment callback accepted" + merchant_transaction_id |
| success / failed / late_success | ✅ | `TempData::logStateTransition` u PaymentSuccessHandler i PaymentCallbackJob; svi prijelazi u payments channel |
| merchant_transaction_id u svakom logu | ✅ | U init, callback accepted i logStateTransition |
| Basic audit trail | ✅ | `storage/logs/payments.log`; channel `payments` u `config/logging.php` |

**Zaključak:** Audit trail je pokriven; init log je dodan.

---

## 9️⃣ Sigurnost

| Zahtjev | Status | Napomena |
|--------|--------|----------|
| Callback rute whitelisted | ✅ | Samo POST /api/payment/callback; API bez web middleware |
| Provjera potpisa banke (HMAC/cert/secret) | ✅ | **RealCallbackSignatureValidator** (Bankart HMAC) koristi `services.bankart.shared_secret` (env `BANKART_SHARED_SECRET`). Fake validator za test vraća `true`. |
| Callback ne ovisi o cookie/session | ✅ | api.php, stateless |
| Success endpoint se ne može ručno pozvati bez validnog potpisa | ✅ | Jedini "success" je obrada u PaymentCallbackJob nakon validacije u controlleru; bez validnog potpisa controller vraća 400 i job se ne dispatch-uje |

**Zaključak:** Arhitektura je ispravna. Za produkciju proveriti da je `BANKART_SHARED_SECRET` ispravno podešen i da gateway šalje očekivane header-e.

---

## Sažetak

- **Stabilno i konzistentno za V1:** generisanje merchant_transaction_id, callback na API ruti, state machine (pending → processed/failed/late_success), oslobađanje lock-ova, snapshot u reservations, success flow (fiskalizacija, PDF, email), retry za gosta, logovanje (uključujući init), queue worker dokumentovan u `docs/payment-architecture.md`.
- **Prije produkcije s pravim gatewayom:** potvrditi da je `BANKART_SHARED_SECRET` set i da callback dolazi sa očekivanim header-ima (validator je već implementiran).
- **Opciono:** implementirati **AssignLateSuccessReservations** (kreiranje rezervacije ili incident); dodati prijevode za late_success poruku (cg/en) ako treba.

---

## Namjerna odstupanja (i razlozi)

Sljedeća odstupanja od „idealnog“ checklist ponašanja su **namjerna** u V1 i ne mijenjaju se bez odluke projekta.

| Odstupanje | Razlog |
|------------|--------|
| **createSession poziv sync u web requestu** (točka 2) | V1 zahtjev: korisnik odmah dobija redirect na banku. Async job za createSession zahtijevao bi drugi mehanizam (npr. polling za payment_url), što mijenja UX. Zadržano sync u CheckoutController prema `docs/payment-architecture.md`. |
| **Gateway poziv nije isključivo kroz Queue Job** | Isti razlog: init flow je sync; samo **obrada callbacka** ide kroz PaymentCallbackJob. |
| **AssignLateSuccessReservations stub** (točka 4) | late_success redovi ostaju u temp_data; admin može pregledati. Automatsko kreiranje rezervacije ili „incident“ za manual review nije u opsegu V1; cron je placeholder za kasniju implementaciju. |
| **temp_data redovi se ne brišu (na success)** (točka 7) | Namjerno: audit trail. „Briše se“ u smislu „završava grana“ = status prelazi u processed/canceled/expired/late_success. Fizičko brisanje je ograničeno na retention cleanup (brišu se samo stari ne-pending redovi). |

**Usklađenost (2026-05):** Raniji red u ovoj tabeli koji je tvrdio da je **`RealCallbackSignatureValidator` „uvijek false“** bio je **zastario** i u kontradikciji sa §3 i §9 u ovom fajlu. Provjera Bankart HMAC potpisa na callbacku je **implementirana** (`RealCallbackSignatureValidator`, `BANKART_SHARED_SECRET`); za test se koristi fake validator. Kanonski opis: **`docs/payment-callback-handling.md`**, **`docs/payment-state-machine.md`**, te stavke u **`docs/project-done.md`** (Bankart inquiry / HMAC).
