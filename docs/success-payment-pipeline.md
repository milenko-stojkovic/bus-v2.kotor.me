# Success payment pipeline (with fiscalization fallback)

Flow nakon bank API SUCCESS callback-a: rezervacija se kreira → **ProcessReservationAfterPaymentJob** pokušava fiskalizaciju; pri uspehu – fiskalni račun i email, pri neuspehu – upis u **post_fiscalization_data** i nefiskalni račun + email. Retry fiskalizacije (cron **post-fiscalization:retry**) pri uspehu šalje kupcu novi fiskalni PDF. Rezervacija je uvek validna.

---

## Besplatne rezervacije (checkout — guest i auth)

Ne idu na banku i **ne** pokreću **ProcessReservationAfterPaymentJob** / fiskalizaciju. Backend odlučuje preko **`FreeReservationRules`**.

- **`PaymentSuccessHandler::handle(..., runFiscalAndInvoicePipeline: false)`** kreira rezervaciju sa **`reservations.status = free`**, **`invoice_amount = 0`**, šalje **`SendFreeReservationConfirmationJob`** (email + **PDF potvrda** iz šablona `pdf/free-reservation-confirmation`, **`FreeReservationPdfGenerator`**, DomPDF). Sadržaj PDF-a **samo cg**; podnožje: *„Ova potvrda je automatski generisana od strane sistema Opštine Kotor.“* (bez teksta o fiskalnom dokumentu). PDF se **ne** čuva trajno na disku: generiše se u memoriji, piše u **privremeni fajl** za `Mail::attach`, pa se briše. Logo: **`public/images/logo_kotor.png`** (grb Opštine Kotor; opciono).
- Redirect: **`guest.reserve`** ili **`panel.reservations`** sa **`checkout_banner`** (grupa **`checkout_result`**, vidi **`CheckoutResultFlash`**), ne kratkotrajni „success“ ekran na **`/payment/return`**.
- **`ProcessReservationAfterPaymentJob`** odmah izlazi ako je `status === 'free'` (odbrambeno).
- **Plaćeni tok:** kad postoji rezervacija, **`GET /payment/return`** više ne ostaje na success view-u — radi **redirect** na gore navedene rute sa flash porukom; na **`/payment/return`** ostaje samo **`pending`** (vidi **`payment/return.blade.php`**). **`PaymentResultResolver`** vraća **`fiscal_complete`** (JIR ili besplatno), **`fiscal_delayed_known`** (postoji nerešen **`post_fiscalization_data`**). **`CheckoutResultFlash`**: pun uspjeh fiskala → **`paid_success_*`**; samo „JIR još nema“ bez post sloga → **`paid_processing_*`** (queue još radi); sa nerešenim post slogom → **`fiscal_delayed_*`**.

---

## Flow (plaćeni tok — kao ranije)

1. **pending → processed** nakon **`PaymentCallbackJob`** na **SUCCESS** — bilo iz **webhooka** (`POST /api/payment/callback`), bilo iz **cron Bankart status inquiry** (isti job i isti `PaymentSuccessHandler` tok).
2. Kreira se **reservation** (bez fiscal polja).
3. Dispatch **ProcessReservationAfterPaymentJob(reservation_id)** (opciono sa **`fakeFiscalScenario`** kad fiskal driver šalje scenario iz kombinovane fake QA forme).
   - **Izuzetak:** ako su **`BANK_DRIVER=fake`** i **`FISCALIZATION_DRIVER=fake`**, job se **ne** šalje iz **`PaymentSuccessHandler`** — isti zahtev ga pokreće **`FakeBankCompleteController`** odmah poslije **`PaymentCallbackJob`**, sa scenarijem sa forme (jedan submit = bank + fiskal ishod).
   - **`dispatchSync`** za **`SendInvoiceEmailJob`** i dalje zavisi od **`FAKE_PAYMENT_E2E_SYNC`** i fake banke (v. job).

---

## ProcessReservationAfterPaymentJob

### Fiskalni depozit (Primatech) ≠ agencijski avans

Prije slanja **`fiscalReceipt`**, **`FiscalizationService`** poziva Primatech **`POST /api/efiscal/deposit`** sa **`DepositType: INITIAL`** i **`Amount: 0`**. To je **formalni tehnički korak** fiskalnog provajdera (inicijalizacija gotovinskog depozita na ENU-u za CARD/CASH račune) — **nema veze** sa:

- **agencijskim avansom** (`/panel/avans`, `agency_advance_transactions`, `payment_method=advance` na checkout-u);
- saldom koji agencija uplaćuje da bi lakše plaćala rezervacije;
- Limo legacy tokom koji je oduzimao iz avansa.

**Agencijski avans** je interni prepaid ledger Opštine prema agenciji; **top-up avansa se ne fiskalizuje** kao prodajni račun (v. **`agency-panel.md`** § Avans). **Fiskalizacija prodaje** nastaje tek kad se kreira **plaćena rezervacija** (kartica ili potrošnja avansa) — tada ide **deposit (formalan) → fiscalReceipt (stvarni račun)**.

Greška depozita **56** („INITIAL cash deposit cannot be changed…”) znači da je formalni depozit na ENU-u već postavljen; aplikacija nastavlja na račun — to **nije** problem avansnog salda agencije.

- **`failed()` (iscrpljeni pokušaji):** ako nema **`fiscal_jir`**, rezervacija nije **`free`**, i nema nerešenog **`post_fiscalization_data`**, upisuje se minimalni nerešen slog sa markerom **`job_failed_before_fiscal_completion`** u **`error`**, **`next_retry_at = now()`** (cron retry), log **`process_reservation_failed_marked_delayed`** — UX prelazi na **`fiscal_delayed_*`**.
- **Pokušaj fiskalizacije** (poziv fiskalnog API-ja).
- **Uspeh fiskalizacije:**
  - ažurira reservation sa fiscal_jir, fiscal_ikof, fiscal_qr, fiscal_operator, fiscal_date;
  - **`SendInvoiceEmailJob`**: **`PaidInvoicePdfGenerator::renderBinary`** → PDF iz šablona **`pdf/paid-invoice`** (DomPDF, izgled kao V1), iznos iz **`reservations.invoice_amount`** (snapshot pri kreiranju); sadržaj **samo cg**; QR iz **`fiscal_qr`** URL-a (**`endroid/qr-code`**), logo **`public/images/logo_kotor.png`**; u podnožju tekst o **fiskalnom dokumentu** samo kad je **`isFiscal`**; privremeni fajl za attachment;
  - šalje **invoice email** (from bus@kotor.me).
- **Neuspeh fiskalizacije:**
  - upis u **post_fiscalization_data** (reservation_id, merchant_transaction_id, error, attempts, next_retry_at);
  - isti PDF šablon sa **`isFiscal = false`**: umjesto IKOF/JIR/QR prikazuje se **`PaidInvoicePdfGenerator::NON_FISCAL_NOTE`**;
  - šalje email kupcu sa nefiskalnim PDF-om (isto: generisanje u memoriji + temp fajl);
  - **ne** rollback-uje rezervaciju ni plaćanje.

---

## Retry job-a koji je delimično uspeo

- **Scenario:** Job padne posle upisa rezervacije / fiskalizacije, ali pre slanja maila.
- **Pravilo:** Svaki korak je **idempotentan** – pri retry-u se ne duplira rad:
  - **SendInvoiceEmailJob:** ako je mail već poslat (`reservation.invoice_sent_at` set) → ne šalje ponovo; DB lock + `email_sent` da se isti mail ne pošalje duplo u slučaju paralelnih workera/retry-a.
  - **ProcessReservationAfterPaymentJob:** ako je **`fiscal_jir`** već upisan a **`invoice_sent_at`** još nije — ponovo se šalje samo **`SendInvoiceEmailJob`** (PDF se svaki put generiše na zahtev).
- U bazi: **`invoice_amount`** (decimal snapshot iznosa), **`invoice_sent_at`** (timestamp slanja). ProcessReservationAfterPaymentJob ima **tries = 3** da retry dovrši preostale korake.

Napomena (PDF + email robustnost):

- PDF se **ne** drži u **`storage/app`**; **`SendInvoiceEmailJob`** / **`SendFreeReservationConfirmationJob`** generišu binarni PDF pa ga privremeno snime za `attach`.
- **`renderBinary`** mora uspeti iz baze (DomPDF): vraća neprazan **`string`** ili **baca izuzetak** — nema tihog `null` fallback-a.
- **Email bez validnog PDF-a se ne šalje:** na grešku PDF-a / `tempnam` / slanja job postavlja **`email_sent`** na **`Reservation::EMAIL_NOT_SENT`** (0), loguje sa **`reservation_id`**, **baca izuzetak** da **Laravel queue** uradi **retry** (`tries` na jobu); nema „regeneriši u istom handle-u“. Paralelno: **`EMAIL_SENDING`** (2) + lock sprječavaju dupli mail; **`failed()`** posle istrošenih pokušaja vraća **`email_sent`** na **NOT_SENT**.

---

## Pravila

- **Rezervacija je validna** bez obzira na status fiskalizacije.
- **Kupac uvek dobija račun** odmah posle uspešnog plaćanja (fiskalni ili nefiskalni).
- **Retry fiskalizacije** vodi se preko tabele **post_fiscalization_data** (error, attempts, next_retry_at).
- Callback-i **samo preko API rute** `POST /api/payment/callback`.
- Idempotentnost po **merchant_transaction_id**; dupli callback-i ne kreiraju duple rezervacije.
- Podrška za **guest i auth** (user_id nullable).
- Mail se šalje sa **bus@kotor.me** (MAIL_FROM_ADDRESS).
- **Guest i auth:** mail ide na **reservation.email** (snapshot na rezervaciji).

---

## Provera scenarija

| Scenario | Implementacija |
|----------|----------------|
| Payment SUCCESS + fiskal OK | ProcessReservationAfterPaymentJob: callFiscalService → fiscal_jir set → dispatch fiscal PDF + email. |
| Payment SUCCESS + fiskal FAIL | ProcessReservationAfterPaymentJob: insert post_fiscalization_data, dispatch non-fiscal PDF + email. |
| Retry fiskalizacije uspe | Cron post-fiscalization:retry: čita post_fiscalization_data (next_retry_at <= now), tryFiscalize → success → applyFiscalDataAndDelete, dispatch fiscal PDF + email. |
| Dupli callback od banke | PaymentCallbackJob: temp terminal (processed) ili Reservation već postoji → return; nema duple rezervacije. ProcessReservationAfterPaymentJob: ako fiscal_jir već set → return (nema duplog PDF/email). |
| Guest korisnik | SendInvoiceEmailJob: user_id null → šalje na reservation.email (snapshot). |
| Auth korisnik | SendInvoiceEmailJob: šalje na reservation.email (snapshot; isto kao guest). |
