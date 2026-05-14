# Limo service

**Poslednje ažuriranje:** 2026-05-14

**Povezano:** [project-todo.md](./project-todo.md) (preostali Limo TODO), [project-done.md](./project-done.md) (urađeno), [agency-panel.md](./agency-panel.md) (agencijski `/panel/limo`).

Ovaj dokument opisuje **trenutno implementirano stanje** u kodu i **preostale planirane korake**. Nije više isključivo „pred-implementaciona“ specifikacija.

---

## Implementation status (Bus Kotor V2)

### Implementirano

- **Baza:** tabele `limo_qr_tokens`, `limo_pickup_events`, `limo_pickup_photos`, `limo_plate_uploads` (privremeni upload tablice; vidi sekciju [Implemented tables](#implemented-tables)), **`limo_incidents`** (incidenti — evidencija bez finansijskog efekta; vidi [Incident flow (implementirano)](#incident-flow-implementirano)).
- **Granica autentifikacije / autorizacije (Limo evidenter):**
  - kolona `admins.limo_access`
  - middleware `limo.access`
  - rute `/limo/*` zaštićene `auth:panel_admin` + `limo.access` (ne glavni `admin.panel`)
  - `GET /limo` — mobilni Blade UI za evidentiranje pickup-a (`limo.entry`), uključujući nakon prijave za nalog „samo Limo“; QR: **`getUserMedia`** stream na vidljiv `<video>` (isti obrazac kao DEBUG test kamere) + dekodiranje kadra; na **mobilnom** **jsQR** (Vite bundle `limo-jsqr.js`); na **desktopu** opciono **`BarcodeDetector`** na istom canvas kadru ako je API dostupan, inače **jsQR**; ručni token uvijek dostupan
  - `GET /limo/health` — isti guard kao ostatak `/limo/*`; JSON `{ status, scope }` za health/smoke
- **Agencijski panel — QR:**
  - `GET /panel/limo` — lista aktivnih QR za **današnji** dan (`Europe/Podgorica`)
  - `POST /panel/limo/qr/generate` — generisanje tokena (raw se jednom prikaže / flash; u bazi `token_hash` + `encrypted_token`); poruke grešaka (limit, nedovoljan avans) preko **`UiText`** grupe `panel` (`limo_generate_error_*`), prema **`users.lang`**
  - `GET /panel/limo/qr/{limoQrToken}` — prikaz QR slike iz dekriptovanog `encrypted_token`
  - `GET /panel/limo/qr/{limoQrToken}/pdf` — **PDF export** za štampu (QR + agencija + datum); token mora biti **današnji** (Podgorica) i vlasništvo agencije, inače 404; nema finansijskog efekta; tekst u PDF-u iz **`ui_translations`** (`panel`, `limo_qr_pdf_*`) prema jeziku korisnika (`LimoQrPdfGenerator` / `users.lang`)
  - navigacija i ostali stringovi Limo QR stranica: **`ui_translations`**, grupa `panel`, ključevi `nav_limo`, `limo_*` (seed u `UiTranslationsSeeder`)
  - feature gate: **`limo.feature`** (kombinacija flagova — vidi niže); ako je Limo nedostupan → **404** na `/panel/limo*` (UI stavka može biti vidljiva, ali disabled)
- **Pickup QR (operativa):**
  - `POST /limo/pickup/qr` — validacija tokena (hash + dan), kreiranje `limo_pickup_events`, oduzimanje avansa (`agency_advance_transactions`, tip `usage`), brisanje iskorišćenog reda iz `limo_qr_tokens`
- **Pickup tablica (fallback, bez QR):**
  - `POST /limo/pickup/plate/ocr` — validacija slike (do 5 MB, jpeg/png/webp), snimanje **cijele** fotografije u **private** `local` disk (dokaz ostaje original); opciono multipart polja **`plate_crop_left`**, **`plate_crop_top`**, **`plate_crop_width`**, **`plate_crop_height`** — **bazisne tačke 0–10000** u odnosu na širinu/visinu učitane slike (isti koordinatni sistem kao na klijentu); validan pravougaonik se čuva u **`limo_plate_uploads.plate_crop_*_bp`**; nevalidan izrez → **422** `validation_error`.
  - **UI (`GET /limo`):** nakon „Nema QR? Slikaj tablicu” korisnik vidi **pregled** slike i može povući **pravougaonik oko tablice** (ili poslati **cijelu sliku** bez izreza); OCR koristi izrez **samo kao pomoć** — original se ne mijenja.
  - **OCR pipeline:** ako postoji valjan korisnički izrez, prvo se na privremenom PNG izreza (`LimoPlateCropExtractor`) grade GD varijante sa prefiksom **`uc_*`**, zatim na **punoj** slici varijante bez prefiksa; na svakoj varijanti Tesseract **`eng`**, u produkciji tipično **`--psm` 7 i 8** + **whitelist** A–Z0–9; kada je **`APP_DEBUG=true`** i `LIMO_OCR_DEBUG_EXTENDED_ATTEMPTS=true`, širi se matrica PSM (npr. 6, 7, 8, 11, 13) i opciono whitelist off — za QA; **rani izlaz** kada je kandidat visokog povjerenja (strogi format tablice ili skor ≥ `LIMO_OCR_EARLY_EXIT_MIN_SCORE`); **prioritet kandidata sa izreza:** dodatak na skor za varijante `uc_*` da se smanje lažni pozitivi sa cijele slike; **konzervativno pravilo:** kandidat dobijen samo sa **`original`** varijante pune slike uz **PSM 11 ili 13** ne prihvata se dok isti kandidat nije „potkrijepljen” iz drugog izvora (druga GD varijanta, drugi PSM na `original`, ili korisnički izrez / `uc_*`).
  - **Regional-aware bodovanje (OCR):** kandidati se normalizuju u ASCII (velika slova, samo `A–Z0–9`; `Ž→Z`, `Š→S`, `Č/Ć→C`, `Đ→D`). Bodovanje je svjesno regionalnih prefiksa:
    - **ME (najveći bonus)**: prefiksi registarskih oblasti Crne Gore (ASCII-normalizovano: npr. `PZ` = PŽ, `SN` = ŠN, `ZB` = ŽB).
    - **HR/RS (srednji bonus)**: česti prefiksi za Hrvatsku i Srbiju (ASCII-normalizovano: npr. `ČK→CK`, `ĐJ→DJ`, `ŽU→ZU`).
    - Kandidati bez poznatog regionalnog prefiksa traže ponavljanje (više prolaza) da bi bili prihvaćeni kao OCR sugestija.
    
    Personalizovane tablice su podržane (npr. `PGABCDE`), i dalje je obavezna ručna potvrda — OCR je samo sugestija (nije validacija unosa).
  - **Ograničenje vremena:** `LIMO_OCR_MAX_TOTAL_SECONDS` (podrazumijevano **15**), po prolazu `LIMO_OCR_PER_PASS_TIMEOUT` (podrazumijevano **3** s); **privremene GD varijante** u `storage/framework/cache/limo_ocr/*` brišu se u **`finally`** nakon OCR-a (original **`limo_plate_uploads/…`** ostaje netaknut za incident / potvrdu / audit). **Debug kopije varijanti** (`storage/app/private/limo_ocr_debug/{sufiks}/…`) ostaju **samo** ako je **`APP_DEBUG=true`** i **`LIMO_OCR_DEBUG_SAVE_IMAGES=true`**; inače se folder tog OCR prolaska briše na kraju obrade. **TTL:** `LIMO_OCR_DEBUG_IMAGE_TTL_MINUTES` — istekli podfolderi se brišu nakon svakog OCR-a (purge) i u Artisan komandi **`limo:cleanup-temporary-data`**.
  - `POST /limo/pickup/plate/confirm` — potvrda **ručno** unesene / ispravljene tablice; normalizacija kao u ostatku projekta (`DuplicateReservationAttemptService::normalizeLicensePlate`); traži se **aktivno** vozilo (`vehicles.status=active`, `user_id` postoji) po tablici; neuspjeh: `plate_not_registered` ili `insufficient_advance`; uspjeh: `source=plate`, `limo_pickup_photos` tip `plate`, ista fiskalna staza kao QR
  - **OCR nije odlučujući** — evidenter mora potvrditi/ispraviti tablicu prije `confirm`
  - Nema incident zapisa u ovom toku (incidenti su odvojeni — vidi [Incident flow](#incident-flow-implementirano))
- **Fiskal / PDF / email (poslije pickup-a):**
  - `ProcessLimoAfterPaymentJob` — fiskalizacija preko postojećeg `FiscalizationService::tryFiscalizeInvoiceLike` + adapter
  - `LimoInvoiceAdapter` — mapiranje `LimoPickupEvent` → oblik kompatibilan sa fiscal/PDF tokom
  - `SendLimoInvoiceEmailJob` — email agenciji sa PDF prilogom; **predmet i tijelo uvijek na crnogorskom (`cg`)** u `UiText` grupi `emails` (`limo_invoice_email_subject`, `limo_invoice_email_body`, linija JIR preko `paid_invoice_email_jir_line`) — **ne prati `users.lang`** (agencijski panel i štampani QR PDF i dalje mogu biti en/cg; fiskalni račun i ovaj email nisu).
  - `PaidInvoicePdfGenerator::renderLimoBinary` — isti Blade `pdf/paid-invoice`, grana sa `isLimoService` (limo detalji umesto slotova rezervacije); generisanje uz **`app()->setLocale('cg')`** (zvanični izlaz računa).
  - ponašanje PDF-a za **obične rezervacije** (`renderBinary(Reservation, …)`) ostaje nepromijenjeno
- **Cleanup privremenih Limo podataka:** Artisan **`limo:cleanup-temporary-data`** — dnevno **00:10** (`Europe/Podgorica`, `routes/console.php`): briše **`limo_qr_tokens`** gdje je **`valid_on` &lt; danas** (Podgorica) i istekle nekonzumirane **`limo_plate_uploads`** (`expires_at` &lt; sada, `consumed_at` NULL) uz brisanje privremenih fajlova; **TTL** podfoldere ispod **`limo_ocr_debug/`** (konfiguracija `LIMO_OCR_DEBUG_IMAGE_TTL_MINUTES`); **ne** briše `limo_pickup_events`, `limo_pickup_photos`, **`limo_pickup_evidence/`**, **`limo_incidents`** (dugotrajna evidencija); logovi `limo_qr_tokens_cleaned`, `limo_plate_uploads_cleaned`, `limo_ocr_debug_ttl_purge_ran`
- **MEGA arhiva privatnih fajlova (server-side):** nakon što su tablice u stabilnom stanju (npr. `limo_plate_uploads` sa `consumed_at`, `limo_pickup_photos` kad je na događaju poslat račun emailom), mogu se arhivirati na MEGA i obrisati lokalno — vidi **[external-file-archive.md](./external-file-archive.md)** i komandu **`files:archive-private --source=limo`**. **`limo_incidents`** u prvoj iteraciji komande nisu obuhvaćeni.
- **Incident flow (minimalno):** `POST /limo/incident` (`limo.incident.store`) — samo **`limo_access`** evidententeri; **obavezna fotografija tablice** (bez nje nema incidenta); opciona fotografija brendinga; tipovi u bazi (`qr_insufficient_funds`, `plate_insufficient_funds`, `unregistered_vehicle_with_branding`, `invalid_qr_token`, `driver_non_cooperative`); snimanje na **private `local`** (`limo_incidents/{uuid}/…`); kolona **`report_emails.purpose`** je ENUM **`report` \| `limo_incidents`**; email sa servera: primaoci iz **`report_emails`** gdje je **`purpose = limo_incidents`** (sve navedene adrese); ako je ta lista **prazna**, podrazumijevani primalac ostaje **`komunalna.policija@kotor.me`** (log na kanalu **`payments`**: `limo_incident_communal_email_using_fallback_recipient`). **`ReportEmailsSeeder`** idempotentno dodaje **`komunalna.policija@kotor.me`** sa **`purpose=limo_incidents`**. Isti mailable **`LimoCommunalPoliceIncidentMail`** (prilozi: tablica ± brending). **`admin_alerts`** tip `limo_incident`; log kanal **`payments`**: `limo_incident_created`, `limo_incident_communal_email_recipients_from_settings` (kad su adrese iz baze), `limo_incident_communal_email_sent` / `_failed`, `limo_incident_admin_alert_created`. **Sistem ne izriče sankcije**, ne dira avans, fiskal, rezervacije niti kreira `limo_pickup_events`. Ako email ne pošalje, red u `limo_incidents` ostaje, `communal_email_sent_at` ostaje `NULL`, alert i dalje nastaje. UI na **`GET /limo`**: sekcija „Prijavi incident”. **Ne prijavljivati** incident za neregistrovano vozilo bez vidljivog brendinga agencije (uputstvo u UI). Nakon obavještenja, odluka je **ručna** (admin / Komunalna policija). Konfiguracija primalaca incidenta: **`GET /admin/podesavanja`** → sekcija **Incident – email adrese**. Testovi: `tests/Feature/Limo/LimoIncidentFlowTest.php`.

### Još nije / TODO

- (OCR je **advisory** prijedlog; manualna potvrda ostaje obavezna. Dalje TODO: dodatni jezici, eventualno OpenCV pretprocesiranje ako bude potrebno.)
- **Incident workflow** — šire od minimalnog: statusi (reported/closed), administrativno rešavanje, integracije; trenutno je samo evidencija + obavještenje
- ~~**Admin analitika** — uključivanje Limo prihoda~~ → **urađeno:** Admin **Analitika** (`/admin/analitika`) ima poseban blok **Limo servis** i KPI za prihod (rezervacije vs Limo vs ukupno); detaljan read-only pregled događaja ostaje **`GET /admin/limo`** (`admin.limo.index`).
- **Offline sync**
- **PWA polish** (instalabilni shell, napredniji UX) — po potrebi nakon terenskog testa
- **Native Android** — odluka nakon terenskog testa PWA-e

---

## Auth boundary (API / backend — Limo evidenter)

Rute `/limo/*` **nisu** dio glavnog Admin panela (`EnsureAdminPanelAccess` / `admin_access`).
Takođe su iza feature gate-a **`limo.feature`** (404 kada je Limo servis isključen).

- **Autentifikacija:** guard `panel_admin` (forma `/admin/login`). Nalog u `admins`, ne `control_access`-only (isti skup kao za panel login).
- **Autorizacija:** `admins.limo_access` + middleware **`limo.access`**. Npr. `POST /limo/pickup/qr` samo uz `limo_access === true`.
- **`admin_access`** otvara `/admin` dashboard; **ne** daje automatski Limo — potrebno je **`limo_access`**.
- **Limo-only nalozi** (`limo_access`, bez `admin_access`): nakon prijave redirect na **`GET /limo`** (`limo.entry`), ne na `/admin`.
- **`GET /limo`:** mobilni web UI (QR sken: vidi prioritet iznad — sirovi video preview + **jsQR** na mobilnom; na desktopu **`BarcodeDetector`** kada postoji, inače jsQR; status poruke za kameru bez logovanja tokena; ako dekoder nije učitan — **ručni unos tokena**), slanje na **`POST /limo/pickup/qr`** sa GPS (best-effort) i `device_info`; jezik cg.

---

## Scope (poslovna pravila)

Limo zavisi od avansnog modela, ali avans **ne zavisi** od Limo:

- **Feature flags:** `config('features.advance_payments')` (ENV `ADVANCE_PAYMENTS_ENABLED`) i `config('features.limo_service')` (ENV `LIMO_SERVICE_ENABLED`)
- **Effective rule:** Limo je dostupan samo ako je **advance_payments ON** **i** **limo_service ON**
- Kada je Limo nedostupan: `/panel/limo*` i `/limo*` vraćaju **404**; u agencijskom UI stavka “Limo” može ostati vidljiva ali disabled.

- **Gosti** ne koriste Limo tokove.
- Koristi se postojeći **agency advance ledger**; **negativan saldo nije dozvoljen**.
- Limo **nije** rezervacija: bez `temp_data`, `reservations`, `daily_parking_data`, parking slotova, besplatnih termina po istom modelu kao Bus rezervacija.

---

## Vehicle scope (proizvod)

- Jedna usluga u implementaciji: snapshot **`service_name_snapshot`**, iznos **`LimoPickupService::AMOUNT_EUR`** (trenutno `15.00` EUR) na pickup događaju.

---

## Financial model

- Topup avansa se **ne** fiskalizuje kao račun prodaje u istom smislu kao pickup.
- Pri realizovanom Limo pickup-u: događaj + **usage** na ledgeru + pipeline **`ProcessLimoAfterPaymentJob`** (fiskal kada uspije, PDF, email).

---

## QR model (implementacija)

- Token u QR-u je **raw** vrijednost; u bazi je **`token_hash`** (npr. SHA-256) i **`encrypted_token`** (Laravel encrypt) za ponovni prikaz QR-a na agencijskoj stranici.
- **`valid_on`** = kalendarski dan (**Europe/Podgorica**).
- Jednokratna upotreba: red u `limo_qr_tokens` se **briše** nakon uspješnog pickup-a; istorija ostaje u **`limo_pickup_events`**.
- **Dnevni limit generisanja:** maks. **20 „slotova”** po agenciji po danu (aktivni tokeni + već realizovani QR pickup-i za taj dan) — vidi `LimoQrService`.
- Istekli nekorišćeni QR tokeni u bazi: brišu se **`limo:cleanup-temporary-data`** (dnevno, v. `docs/cron-commands.md`).

---

## Pickup flow – QR (implementirano)

Validacija: aktivni token, danas, dovoljno avansa, limit. Na uspjeh: `limo_pickup_event`, ledger usage, brisanje tokena, dispatch pipeline job-a.

**`merchant_transaction_id` (Limo):** nastaje u trenutku kreiranja `limo_pickup_event`. To je jedinstveni identifikator računa / korelacije za Limo fiskalni račun i ima istu ulogu identifikacije računa kao `merchant_transaction_id` na rezervacijama. **Ne** znači da Limo koristi `temp_data`, `reservations` niti payment state machine.

---

## Pickup flow – license plate fallback (implementirano)

1. Evidententer šalje fotografiju na **`POST /limo/pickup/plate/ocr`** (multipart); u tijelu mogu biti opciona polja **`plate_crop_*`** (bazisne tačke izreza); dobija **`upload_token`** (isti evidenter, istek ~1h, jednokratna potvrda).
2. Opcioni **`suggested_plate`** iz server-side OCR-a (Tesseract nakon **GD pretprocesiranja**; prvo **korisnički izrez** ako postoji, zatim cijela slika; više PSM prolaza ovisno o konfiguraciji; **whitelist** samo A–Z0–9 u uobičajenom režimu) — **samo savjet**; OCR tekst se za parsiranje svodi na **velika slova i samo A–Z / 0–9**, zatim se traže „plate-like” kandidati i **boduje** najbolji (uz konzervativno pravilo za PSM 11/13 na varijanti `original` pune slike); ručna potvrda ostaje obavezna. U **`limo_plate_uploads.ocr_text`** čuva se **samo tekstualni OCR isječak** (ograničena dužina), ne binarna slika.
3. **`GET /limo` (UI):** polja za tablicu (fallback + opciona tablica u incidentu) imaju **`inputmode="text"`**, **`autocapitalize="characters"`**, **`autocomplete="off"`**, **`spellcheck="false"`** i klijentski **auto-uppercase / samo A–Z0–9** radi lakšeg unosa na telefonu; **izvor istine za potvrdu i dalje je backend** (`DuplicateReservationAttemptService::normalizeLicensePlate` na `confirm` / incidentu).
4. Kada je **`APP_DEBUG=true`**, JSON odgovor na **`POST /limo/pickup/plate/ocr`** može uključivati opcioni objekat **`debug`**: `ocr_enabled`, `ocr_available`, `raw_preview`, `normalized_preview`, `reason`, **`variants_tried`**, **`variant_attempts`** (svaki red: `variant`, `psm`, **`raw_preview`** / **`normalized_preview`** kao prazan string ako nema teksta, **`candidate`**, **`error`**), **`selected_variant`**, **`selected_psm`**, **`selected_candidate`**, **`early_exit`**, te **`ocr_used_user_crop`** (boolean — da li je OCR koristio korisnički izrez), **`ocr_crop_width_px`** / **`ocr_crop_height_px`** (dimenzije izreza u pikselima kada je izrez korišćen) — bez binarne slike. Na **`GET /limo`** u bloku „DEBUG — tablica (OCR upload)” isti podaci se ispisuju čitljivo liniju po liniju (uključujući liniju o izrezu). Kada je **`APP_DEBUG=false`**, odgovor sadrži samo `status`, `upload_token`, `suggested_plate`.
5. **`POST /limo/pickup/plate/confirm`** sa `upload_token` + **`license_plate`**: traži se aktivno vozilo agencije; avans ≥ 15 EUR; kreira se događaj `source=plate`, foto `limo_pickup_photos` (`type=plate`), ledger usage, `ProcessLimoAfterPaymentJob`.
6. Ako tablica nije u voznom parku agencije → **`plate_not_registered`** (bez incident zapisa). Ako avans nedovoljan → **`insufficient_advance`**.
7. **JSON odgovor (contract) na confirm:** uspjeh `{ "status": "ok", "merchant_transaction_id": "…", "remaining_balance": "…" }` (`remaining_balance` opciono). Greška (npr. 422) `{ "status": "error", "code": "…", "message": "…" }` (`code`: `plate_not_registered`, `insufficient_advance`, `invalid_upload`, `validation_error`, …).

**Logovi (kanal `payments`, OCR):** `limo_plate_ocr_attempted` (prije varijanti), `limo_plate_ocr_variant_attempted` / `limo_plate_ocr_variant_succeeded` (po varijanti i `psm`, uključujući `normalized_length`, opciono `candidate`), `limo_plate_ocr_succeeded` (ukupno kad je izabran kandidat), `limo_plate_ocr_no_candidate` (nema prihvatljivog kandidata; `variant_passes`), `limo_plate_ocr_failed` (izuzetak), `limo_plate_ocr_unavailable` (OCR isključen ili runner nedostupan). **Ne** loguje se binarna slika.

---

## Incident flow (implementirano)

Operativni incidenti (sumnja na Limo pickup bez plaćanja) evidentiraju se **odvojeno** od uspješnog pickup-a:

- Endpoint: **`POST /limo/incident`** (`limo.incident.store`), isti guard kao ostatak `/limo/*` (`auth:panel_admin` + **`limo.access`**).
- Endpoint (iz postojeće OCR fotografije tablice): **`POST /limo/incident/from-plate-upload`** (`limo.incident.from_plate_upload`) — koristi već postojeći `limo_plate_uploads` red i njegovu fotografiju tablice kao obavezni dokaz (ne šalje se novi `plate_photo`).
- **Obavezna** je fotografija tablice (`plate_photo`); opciono `branding_photo`.
- Tipovi: `qr_insufficient_funds`, `plate_insufficient_funds`, `unregistered_vehicle_with_branding`, `invalid_qr_token`, `driver_non_cooperative`.
- **Nema** `limo_pickup_event`, **nema** `agency_advance_transactions` usage, **nema** fiskalnog računa za incident.
- Email na **`komunalna.policija@kotor.me`** (HTML + tekst, prilozi sa diska); **`admin_alerts`** sa kontekstom (tip, tablica, UUID, vrijeme, agencija ako je poznata).
- Poslovno: **ne kreirati** incident ako nema jasnog Limo konteksta (npr. neregistrovano vozilo **bez** vidljivog imena agencije na vozilu) — vodič u UI.

Širi workflow (rezolucija, statusi, kazne) **nije** u kodu — odluka ostaje kod admina / Komunalne policije.

### Incident iz postojećeg plate OCR uploada (implementirano)

Kada vozač nema QR, evidenter već fotografiše tablicu kroz tok **`POST /limo/pickup/plate/ocr`** i dobija `upload_token`. Ako plaćanje ne može biti završeno (npr. vozač odlazi), incident se može prijaviti **bez ponovnog fotografisanja**:

- UI na **`GET /limo`** nakon uspješnog plate OCR uploada prikazuje opciju **„Prijavi incident za ovu tablicu”**.
- Backend: **`POST /limo/incident/from-plate-upload`** prima `upload_token` + incident polja i koristi već sačuvanu fotografiju tablice iz `limo_plate_uploads.path`.
- Za incident se koristi i **GPS / device_info** zabilježeni u trenutku fotografisanja tablice (iz `limo_plate_uploads.gps_lat`, `gps_lng`, `device_info`).
- Upload se **ne** konzumira (`consumed_at` se ne postavlja) — incident je odvojen od uspješnog pickup-a.
- Direktni tok **`POST /limo/incident`** sa novom fotografijom tablice ostaje dostupan kada je potrebno.

---

## Evidence / audit

Za plate fallback, fotografije su na **`local`** disku (privatno), putanja u `limo_pickup_photos.path`. Za incidente, fotografije su u **`limo_incidents/{incident_uuid}/`** na istom disku (`plate_photo_path`, opciono `branding_photo_path`).

---

## Implemented tables

### `limo_qr_tokens`

Privremena tabela **aktivnih** tokena za generisane QR-ove. Iskorišćeni redovi se **brišu**; istorija je u `limo_pickup_events`.

| Polje | Napomena |
|-------|----------|
| `id` | |
| `agency_user_id` | |
| `token_hash` | lookup pri skeniranju |
| `encrypted_token` | šifrat raw vrijednosti za prikaz QR-a agenciji |
| `valid_on` | datum važenja (dan u Podgorici) |
| `created_at`, `updated_at` | |

### `limo_pickup_events`

Izvor istine za realizovane Limo prodaje (i buduće incidente).

| Polje | Napomena |
|-------|----------|
| `id` | |
| `merchant_transaction_id` | unique, korelacija fiskal/PDF/email |
| `agency_user_id` | nullable u teoriji (incidenti) |
| `agency_name_snapshot`, `agency_email_snapshot`, `agency_country_snapshot` | audit snapshot |
| `source` | `qr` / `plate` / `incident` |
| `qr_token_hash`, `qr_valid_on` | za QR tok |
| `vehicle_id` | opciono |
| `license_plate_snapshot` | |
| `amount_snapshot`, `service_name_snapshot` | |
| `occurred_at` | |
| `gps_lat`, `gps_lng` | |
| `recorded_by_limo_admin_id` | |
| `device_info` | |
| `status` | npr. `pending_fiscal`, `fiscalized`, `fiscal_failed`, … |
| `fiscal_jir`, `fiscal_ikof`, `fiscal_qr`, `fiscal_operator`, `fiscal_date` | |
| `email_sent`, `invoice_email_sent_at` | idempotencija emaila računa |
| `created_at`, `updated_at` | |

**Snapshots** se ne izračunavaju naknadno iz `users` — istorija ostaje onakva kakva je upisana pri događaju.

### `limo_pickup_photos`

| Polje | Napomena |
|-------|----------|
| `id` | |
| `limo_pickup_event_id` | |
| `path` | relativno na private disk |
| `type` | npr. plate / context |
| `created_at`, `updated_at` | |

### `limo_plate_uploads`

Privremeni upload prije potvrde tablice.

| Polje | Napomena |
|-------|----------|
| `id` | |
| `upload_token` | unique, string za `confirm` |
| `path` | relativno na private disk |
| `ocr_text` | nullable; skraćeni **sirovi OCR tekst** (nije binarna slika) |
| `plate_crop_left_bp`, `plate_crop_top_bp`, `plate_crop_width_bp`, `plate_crop_height_bp` | nullable; **bazisne tačke (0–10000)** pravougaonika tablice na originalnoj slici ako je evidenter označio izrez; inače `NULL` |
| `gps_lat`, `gps_lng`, `device_info` | opciono od uploada |
| `uploaded_by_limo_admin_id` | FK `admins`; samo taj evidenter može potvrditi |
| `expires_at` | nakon čega `confirm` vraća `invalid_upload` |
| `consumed_at` | postavlja se nakon uspješnog `confirm` |
| `created_at`, `updated_at` | |

### `limo_incidents`

Evidencija incidenta (bez finansijskog efekta, bez pickup događaja).

| Polje | Napomena |
|-------|----------|
| `id` | |
| `incident_uuid` | unique |
| `type` | enum tipova incidenta (vidi [Incident flow](#incident-flow-implementirano)) |
| `license_plate_snapshot` | nullable, normalizovano ako je uneseno |
| `agency_user_id` | nullable → `users` |
| `agency_name_snapshot`, `agency_email_snapshot` | snapshot ako je agencija poznata |
| `visible_agency_name` | nullable, unos evidentra |
| `plate_photo_path` | obavezno, private disk |
| `branding_photo_path` | nullable |
| `note` | nullable |
| `occurred_at` | indeksirano |
| `gps_lat`, `gps_lng` | nullable |
| `recorded_by_limo_admin_id` | FK `admins` |
| `device_info` | nullable |
| `communal_email_sent_at` | nullable — postavlja se samo ako je email uspio |
| `admin_alert_id` | nullable → `admin_alerts` |
| `created_at`, `updated_at` | |

---

## Agency panel (`/panel/limo`) — implementirano

- Vidljivo kada je **`advance_payments` ON**; inače **404** na `/panel/limo*`.
- Lista **aktivnih** QR za **tekući dan**; iskorišćeni nestaju jer se red briše iz `limo_qr_tokens`.
- Dugme generisanja, link na stranicu QR-a i **PDF export**: `GET /panel/limo/qr/{limoQrToken}/pdf` (PDF se generiše on-demand, ne čuva se; važi samo za današnje tokene).

---

## Limo evidenter (`/limo/*`)

- Odvojena autorizacija: **`limo.access`**, **`limo_access`**.
- **`GET /limo`** (`limo.entry`) — mobilni ekran: QR sken / ručni token (**preview:** `getUserMedia` → `#scanVideo` autoplay/playsinline/muted; **dekodiranje:** `requestAnimationFrame` petlja, `canvas` sa smanjenom rezolucijom (performanse), na mobilnom **jsQR**; na desktopu **`BarcodeDetector.detect`** kada postoji u pregledaču, inače jsQR); sekcija **„Bez QR koda”**: foto tablice (kamera ili galerija) → **pregled + opcioni pravougaonik oko tablice** → upload na `/plate/ocr` (original + opcione BP koordinate izreza), prikaz prijedloga + **obavezna** ručna potvrda tablice na `/plate/confirm`; **`navigator.geolocation`** je opcion; **`device_info`** kao kod QR.
- **Privremeno (samo `APP_DEBUG=true`):** na vrhu stranice blok **„DEBUG — test kamere”** — čist `getUserMedia` + `<video>` (bez QR biblioteka), isključivo za dijagnostiku da li se sirovi preview prikazuje u pregledaču; ne šalje podatke na backend. U istom bloku **„DEBUG — Potvrdi dolazak (fetch)”** — tekstualni trag koraka submit-a (bez tokena).
- **„Potvrdi dolazak”:** `fetch` ide na **relativni** URL **`/limo/pickup/qr`** (isti origin); CSRF iz `<meta name="csrf-token">` (čitanje pri slanju); GPS korak ima spoljašnji limit da UI ne ostane zaglavljen ako `getCurrentPosition` ne odgovori.
- **Pregledač / HTTPS:** `getUserMedia`, geolokacija i (na desktopu opciono) **`BarcodeDetector`** / **jsQR** zahtijevaju **[secure context](https://developer.mozilla.org/en-US/docs/Web/Security/Secure_Contexts)** — u praksi **HTTPS** na domeni; izuzetak je tipično **`localhost` / `127.0.0.1`**. Na „čistom” HTTP bez secure context-a kamera može biti nedostupna — ručni unos tokena i dalje radi. Tok kamere se zaustavlja nakon skeniranja, pri **„Potvrdi dolazak”**, zaustavljanja ili napuštanja stranice (`pagehide` / `beforeunload`).
- **`POST /limo/pickup/qr`** — isti endpoint za UI (`fetch` JSON + CSRF, rel. putanja `/limo/pickup/qr`); odgovori ostaju JSON (`status` / `code`).
- **`POST /limo/pickup/plate/ocr`**, **`POST /limo/pickup/plate/confirm`** — na `/limo` UI: **multipart** (`FormData`) na relativni **`/limo/pickup/plate/ocr`** (polja slike + opciono `plate_crop_left|top|width|height` u BP), JSON na **`/limo/pickup/plate/confirm`**; faze statusa (izbor → opcioni izrez na previewu → GPS best-effort → slanje); greške `plate_not_registered`, `insufficient_advance`, `invalid_upload` + HTTP 401/403/404/419/5xx poruke.
- **`POST /limo/incident`** — multipart; JSON uspjeh `{ status: ok, incident_uuid, communal_email_sent }`; greška validacije `422` sa `code: validation_error`.
- **`GET /limo/health`** — JSON smoke pod istim middleware-om.
- Dalje **TODO**: poboljšanje OCR parsiranja/pre-processinga, širi incident/admin workflow, offline sinhronizacija, native Android. (Retencija dugotrajnih foto dokaza — posebna politika ako bude potrebna.)

---

## Admin panel / analytics

- Uključivanje Limo prihoda u admin izvještaje + read-only pregled događaja — **urađeno** (v. `docs/admin-panel.md`, `/admin/analitika` i `/admin/limo`).

---

## Explicit non-goals / još uvijek out of scope

- Native Android (odluka nakon PWA)
- Automatske **sankcije** ili blokiranje agencija na osnovu incidenta (samo evidencija + obavještenje)
- Offline-first sync
- Limo za goste kroz isti model kao panel agencije

---

## Historijska napomena

Ranija verzija ovog fajla bila je „inicijalna specifikacija prije implementacije”. Od **2026-05-04** dokument prati **implementaciju + preostale TODO** kao iznad.
