# Payment callback handling

**Bank callback mora biti na API ruti (`POST /api/payment/callback`), ne na web.** (U kodu: `routes/api.php` → `payment/callback`.) Ne oslanjati se na cookies, session ili browser language. Identifikacija samo po merchant_transaction_id. Račun (PDF) je uvek na crnogorskom (cg) – v. docs/language-and-invoice-rules.md. Razlozi: banka ne šalje cookies/CSRF; web middleware (session, redirects) može odbiti ili pokvariti callback (dokazano u produkciji V1). Callback je stateless; na **validan** postback vraća **HTTP 200** + telo **`OK`** (`text/plain`); na grešku **400** JSON. Redirect korisnika kasnije preko **GET /payment/return** i/ili **GET /payment/result** (JSON).

**Bankart ACK (NLB podrška):** postback mora biti potvrđen sa **HTTP 200** i telom **`OK`** — time Bankart zna da je callback prihvaćen. V1 je tako radio; V2 je greškom vraćao **202** + JSON (`{"accepted":true}`), što vjerovatno uzrokuje ponovljene SUCCESS callback-e. Isti ACK važi za prvi validan callback, duplicate terminal processed, i validan FAILED/CANCEL/ERROR.

**Nikad ne koristiti bank callback za frontend redirect ili UI flow. Bank callback je isključivo machine-to-machine.** Frontend NIKAD ne sme da poziva `POST /api/payment/callback`. Za test (fake bank + fake fiskal u jednom koraku) koristi se **`POST /payment/fake-bank/complete`** (`bank_scenario`, `fiscal_scenario` kad je bank success) ili **`GET /fake-bank/complete`** sa query parametrima (lokalni QA). Ne koristiti `POST /api/payment/callback` iz browsera.

Pravila za validaciju callback-a, idempotentnost, CANCEL/ERROR, notifikacije i redirect.

**Isti pipeline:** **`PaymentCallbackJob`** se može dispatchovati i kada **HTTP callback ne stigne** — cron **`payment:check-pending-inquiry`** (Bankart status inquiry) šalje job sa ekvivalentnim **`status`** poljem i `raw` iz odgovora banke (`source: status_inquiry`). Idempotentnost i CANCEL/ERROR pravila u jobu važe i za taj ulaz. V. **`payment-architecture.md`**, **`payment-states.md`**, **`cron-commands.md`**.

**Kasni SUCCESS:** ako je **`temp_data.status === expired`**, job poziva **`late_success`** (bez nove rezervacije). Ako je **`canceled`**, **`late_success` se ne primenjuje** — log **`payment_success_after_canceled_ignored`**, status ostaje **`canceled`**, i šalje se **email administratoru** (**`AdminFiscalizationAlertService::notifyPaymentSuccessAfterCanceled`**) sa snapshot-om `temp_data` i ulaznim payload-om; rešavanje je **van aplikacije**. Primalac: **`payment.operations_alert_email`** (env **`PAYMENT_OPERATIONS_ALERT_EMAIL`**, podrazumevano `bus@kotor.me`).

---

## 1. Validacija callback-a (uvek prvo)

- **Validirati potpis banke** (signature / hash) pre bilo kakve obrade.
- Ako validacija padne → **logovati** (payments channel) i **prekinuti obradu** → **HTTP 400**.

---

## 2. Idempotentnost (callback može stići više puta)

- Ako je **temp_data.status** već **canceled** ili **processed** (ili drugi terminalni status) → prekinuti obradu (return) — v. `TempData::TERMINAL_STATES` u kodu.
- **Ponovljeni SUCCESS** kad je **`temp_data.status = processed`** i **rezervacija već postoji** za isti `merchant_transaction_id`: **`PaymentCallbackController`** (poslije validacije potpisa i payload-a) odmah vraća **HTTP 200** + **`OK`** **bez** dispatch-a **`PaymentCallbackJob`**. Log: **`payment_callback_duplicate_terminal_acknowledged`** (jedan red u `payments` logu). Ne šalje se admin alert; stanje se ne mijenja.
- **Ne** primjenjuje se na: **`pending`** (prvi SUCCESS), **`expired`** / **`canceled`** / **`late_success`** (kasni SUCCESS i admin obavještenja idu i dalje kroz job), niti na **`processed` bez rezervacije** (job se i dalje dispatch-uje).
- **Nikad ne brisati temp_data fizički** (audit trail); samo menjati status.
- Cleanup `temp-data:cleanup` briše samo **stare ne-pending** redove po retention pravilu (default 180 dana) i **nikad** ne briše `pending`.

---

## 3. CANCEL / ERROR logika (čišćenje)

Ako banka šalje **CANCEL** ili **ERROR**, ili se payload normalizuje na neuspeh (**`failed`** kao *callback* status u jobu, ne kao vrednost kolone u bazi):

- **temp_data.status** = **`canceled`** (u bazi nema odvojenog `failed` za ovu granu)
- Sačuvati:
  - **raw_callback_payload** (JSON) u temp_data
  - **callback_error_code** i **callback_error_reason** (ako banka šalje)
- **NE** kreirati reservation
- **Osloboditi termin** (soft-lock): decrement `daily_parking_data.pending` za **oba** slota — `(reservation_date, drop_off_time_slot_id)` i `(reservation_date, pick_up_time_slot_id)` (jednom ako su ID-jevi isti)
- Emitovati **PaymentFailed** event (log + opciono email)

TempData ostaje u bazi radi audit trail-a; više se ne može koristiti za retry bez nove inicijacije plaćanja.

---

## 4. Obaveštavanje korisnika

- **Unified notifier:** poruka za failed: *"Plaćanje je otkazano ili nije uspelo. Rezervacija nije sačuvana."*
- **Web:** frontend dobija poruku preko **GET /payment/result?merchant_transaction_id=...** (JSON `message`) i prikazuje je (flash / UI).
- **Email (opciono):** listener **NotifyUserPaymentFailed** – kada bude Mailable, slati email korisniku.

---

## 5. Redirect logika (guest vs auth)

- **Guest:** redirect → **route('reservations.create')** (forma za novu rezervaciju); u session može biti error message i opciono prethodno uneseni podaci.
- **Auth:** redirect → **route('profile.reservations')** sa notifikacijom „Plaćanje nije uspelo“.
- **Nikada** ne vraćati korisnika na gateway URL; uvek u aplikaciju sa jasnim statusom.

Frontend: ping **GET /payment/result?merchant_transaction_id=...** → backend vraća JSON: `{ status, user_type, message?, redirect_guest, redirect_auth }`. Na osnovu toga frontend radi redirect na odgovarajuću stranicu i prikazuje poruku.

---

## 6. Queue job – SUCCESS vs CANCEL/ERROR grana

- **SUCCESS** → kreiraj reservation, temp_data.status = processed, oslobodi soft-lock (pending→reserved), PostFiscalizationJob, email (cron).
- **CANCEL/ERROR** (i normalizovani neuspeh) → **temp_data.status = `canceled`**, sačuvaj raw payload + error, oslobodi soft-lock (decrement pending), **emit PaymentFailed event** (log + notify).

Job **emituje PaymentFailed** event koji frontend ne prima direktno – frontend otkriva status preko **/payment/result**. Event služi za backend (log u payments channel, email).

---

## 7. Frontend flow

- Callback endpoint je **API** (`POST /api/payment/callback`); **ne radi redirect**, nema session/cookies. Nevažeći potpis / loš JSON / validacija → **HTTP 400**; po mogućstvu audit u `temp_data` (`auditTempDataCallback400`) ako je poznat `merchant_transaction_id`.
- Callback setuje status u bazi (preko job-a); na uspjeh vraća **200 OK**; na grešku **400**.
- **Success/cancel URL** (stranica na koju banka vrati korisnika): **GET /payment/return?merchant_transaction_id=...**
  - Stranica **uvek čita status iz baze** (**`PaymentResultResolver`**) – UI nije izvor istine.
  - Ako je status **`pending`** → prikaže se stranica sa porukom „u obradi“ i **polling** na **`GET /payment/result`**. Okvir: **`x-guest-layout`** (gost) ili **`x-app-layout`** (ulogovan korisnik).
  - Ako je **`success` / neuspjeh plaćanja** (u UI resolveru često označeno kao `failed`; u **`temp_data`** je to **`canceled`**) **/ `late_success`** → **HTTP redirect** na **`guest.reserve`** ili **`panel.reservations`** (prema tipu korisnika) sa **`checkout_banner`** flash porukom (**`CheckoutResultFlash`**, **`checkout_result`** u **`ui_translations`**); nema posebnog „result“ template-a za te statuse.
  - API za polling: **GET /payment/result?merchant_transaction_id=...** → JSON (npr. `status`, `user_type`, `message`, `redirect_guest`, `redirect_auth`, `resolution_reason`, `fiscal_complete`, … prema resolveru).

## 8. User zatvori browser tokom redirecta

- **Scenario:** Korisnik plati → zatvori tab pre nego što vidi redirect → callback dođe normalno.
- **Pravilo:** Status se **uvek** čita iz baze. Kad se korisnik vrati (isti URL sa `merchant_transaction_id`):
  - **GET /payment/return?merchant_transaction_id=...** ponovo pita bazu.
  - Ako postoji **reservation** → **redirect** na booking stranicu sa **success/info** `checkout_banner` (plaćeno + fiskal OK ili odložen fiskal).
  - Ako je još **pending** → ista return stranica + polling dok callback ne završi.
  - Ako je **neuspjeh plaćanja** (UI status, npr. `failed`) **/ `late_success`** → redirect sa odgovarajućom flash porukom.

---

## Sigurnosni minimum (callback)

- **Rate-limit** callback endpointa: **60 zahteva/min** (middleware `throttle:60,1` na `POST /api/payment/callback`).
- **Logovanje payload-a:** uvek se u **payments** channel upisuje minimalni zapis (merchant_transaction_id, status, ip); u **debug režimu** (APP_DEBUG=true) dodatno se loguje ceo payload.

---

## Bonus (production safety)

- **Log** svaki CANCEL/ERROR u **payments** channel (storage/logs/payments.log).
- **Metric:** broj neuspešnih plaćanja – log u payments channel sa merchant_transaction_id; kasnije može se agregirati za monitoring.
