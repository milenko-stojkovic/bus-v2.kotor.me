# Konvencije projekta (bus.kotor.me)

**Poslednje ažuriranje:** 2026-07-01  

Za AI i ljude: držati se ovoga pri novim izmenama da ostane konzistentno.

**Otvoreni zadaci:** [project-todo.md](./project-todo.md) · **Urađeno:** [project-done.md](./project-done.md) · **Indeks docs:** [README.md](./README.md)

---

## 0. Princip: dokumentacija = izvor istine, ne zabune

- **Cilj:** ono što piše u `docs/` treba da bude **proverljivo** i **usklađeno** sa stvarnim ponašanjem aplikacije. Dokument koji kontradiktuje kod ili drugi doc je **greška** dok se ne ispravi.
- **Hijerarhija:** za tačan tehnički detalj (ruta, status u bazi, redosled jobova) **kod u repou je presudan**. Tematski fajlovi u `docs/` su „izvor istine“ za ljude i AI **samo ako redovno prate taj kod**. Kad promeniš kod — u istom PR-u ili odmah posle ažuriraj pogođeni `.md` (ili eksplicitno napiši u `project-todo.md` da doc kasni, ako mora).
- **Kontradikcija doc ↔ kod:** ne ostavljati oba stanja; **ili** ispravi dokumentaciju **ili** vrati/popravi kod. Izbegavati **nejasno mešanje** starog i novog u istoj rečenici bez oznake šta još važi.
- **Sumnja:** ako nisi siguran šta važi, proveri kod (`routes/`, kontroler, model). Ne nagađaj u doc-u. Ako nešto nije implementirano, u doc-u jasno napiši **„nije implementirano / stub“** i na šta se odnosi (npr. ime klase).
- **Meta-dokumenti** (`README.md`, `handoff-new-chat.md`, `project-todo.md`, `project-done.md`, ovaj fajl, `project-status-next-steps.md`) opisuju proces i konvencije; **ne dupliraju** dugačke tehničke specifikacije — za to služe tematski fajlovi iz indeksa u `project-status-next-steps.md`.

### 0.1 Evolucija u dokumentu („bilo → sada“) — dozvoljena notacija

Ponekad je korisno u **istom** tematskom `.md` fajlu zabeležiti i **istoriju odluke**, ne samo trenutno stanje. To **nije** zabuna ako je struktura jasna.

Preporučeni oblik (naslovi ili bold oznake moraju biti eksplicitni):

1. **Rešenje je bilo ovako (zastarelo / pre promene):** … kratko šta je važilo ranije (ruta, klasa, pravilo).
2. **Nakon** (pravila, zahteva, PR-a, datuma — šta je pokrenulo promenu): … jedna rečenica konteksta.
3. **Rešenje sada izgleda ovako (važeće):** … šta **trenutno** važi i mora da se slaže sa kodom.

**Pravila za ovu notaciju:**

- Blok **„sada / važeće“** je ono što AI i novi saradnik tretiraju kao **operativnu istinu**; mora da odgovara kodu.
- Blok **„bilo / zastarelo“** služi **samo** za uvid u razvoj i odbacivanje loših koncepata — ne implementirati po njemu.
- Kad zastareli opis više niko ne koristi, može se **skratiti** (npr. jedna rečenica + „v. git istoriju“) da doc ne raste bez kontrole.
- U `project-done.md` često je dovoljna **jedna** rečenica po promeni; duboki „pre/posle“ zapis ostaje u tematskom fajlu gde ima smisla.

### 0.2 Okruženja i URL-ovi (2026-06-19)

| Okruženje | Javni URL | Folder / baza | Napomena |
|-----------|-----------|---------------|----------|
| **V2 produkcija** | `https://bus.kotor.me` | Plesk docroot → `bus-v2.kotor.me/public`; app folder **`bus-v2.kotor.me`**; MySQL **`bus`** | Aktivna produkcija; pravi Bankart + fiskal |
| **V1 rezerva** | `https://bus-v1.kotor.me` | Stari folder **`bus.kotor.me`**; MySQL **`opstinakotor_busnova`** | Rollback / arhiva — ne dirati bez plana |
| **V2 staging** | `https://bus-v2.kotor.me` | Odvojena staging instanca/baza | E2E validacija završena; ranije simulacija |
| **Lokalno** | npr. `https://bus.kotor.me.test` | Laragon | Razvoj, PHPUnit, fake driver |

- **`APP_URL=https://bus.kotor.me`** na produkciji (bez `www`; `www.bus.kotor.me` nije u upotrebi).
- Cut-over, migracija rezervacija, `.env`, queue: **`docs/production-runbook.md`** § Cut-over V1 → V2.
- Završeni zadaci: **`docs/project-done.md`**; otvoreno: **`docs/project-todo.md`**.

---

## 1. Jezik i tekstovi

- **`ui_translations`** (grupa + ključ + `locale` + `text`): za **kratke** UI stringove (naslovi, labele, dugmad, kratke poruke, auth kratki tekstovi).
- **Blade partiali / markdown u `docs/`**: za **dugačke** tekstove (uslovi korišćenja, politika privatnosti, duga uputstva). Ne stavljati ceo pravni tekst u `ui_translations`.
- Pristup u Blade-u: **`App\Support\UiText::t('group', 'key', $fallback)`**; novi ključevi kroz **`UiTranslationsSeeder`** sa `upsert` (bez dupliranja redova).
- **Korisnik / mail locale:** za auth mailove koristiti **`$user->lang`** (`cg` / `en`); verify-email ekran treba da prati isti princip gde je korisnik ulogovan.
- **Agencijski unos datuma (FZBR, Statistika):** hibrid preko **`iso-date-input`**: vidljivo **`dd/mm/yyyy`**, submit **`Y-m-d`** (skriveno `name`), kalendar preko skrivenog `input[type=date]` + dugmeta (native picker, bez vidljivog mm/dd/yyyy u polju). **`isoDateInput.js`** sinhronizuje tipkanje, picker i canonical. **Rezervacije** koriste mesečni grid **`partials/reservation-date-calendar`** (isto `Y-m-d`).
- **Admin / staff filteri i forme (osim guest/agency rezervacija):** **`<x-iso-date-input>`** — npr. Admin Rezervacije, Besplatne rezervacije (FZBR pregled), Izvještaji, Analitika, Uvid, blokiranje. **Izuzetak — Control Termini (`/control`, pretraga):** vidljivi native **`input type="date"`** (`name="date"`) zbog pouzdanosti na **Safari iOS** (hibridni `iso-date-input` na terenu nije pouzdan).
- **Pre-release provjera (Control, Admin):** sve izmjene koje utiču na **unos datuma** ili **JavaScript interakcije** na operativnim ekranima (**Control**, **Admin**) moraju biti **ručno provjerene na Safari iOS** prije produkcionog puštanja — uključujući otvaranje kalendara, submit forme i da backend dobije ispravan **`Y-m-d`**. Desktop/Android nisu dovoljni.

### 1.1 Registarska tablica — unos i normalizacija

Svako polje za **registarsku tablicu** (booking, panel, admin pretraga, Control, Limo) mora da prati **ista** pravila — ne uvoditi posebna pravila po stranici.

| Sloj | Pravilo |
|------|---------|
| **Klijent** | Automatski **uppercase**; dozvoljeno samo **A–Z** i **0–9**; **bez** razmaka i simbola (`-`, `_`, `.`, `/`, …). Tipično: `oninput="this.value=this.value.toUpperCase().replace(/[^A-Z0-9]+/g,'')"` ili Blade komponenta **`<x-license-plate-input>`** (`resources/views/components/license-plate-input.blade.php`) — atributi `autocapitalize="characters"`, `autocomplete="off"`, `spellcheck="false"`, `inputmode="latin"`, `pattern="[A-Z0-9]+"`. |
| **Server** | Ista semantika prije validacije/spremanja/pretrage: **`App\Support\MontenegroLicensePlate::normalizeAscii()`** (uppercase, ukloni razmake/simbolе, transliteracija **Ž→Z**, **Š→S**, **Č/Ć→C**, **Đ→D**). U rezervacionom domenu često se poziva i **`DuplicateReservationAttemptService::normalizeLicensePlate()`** — ista logika za nullable unos. |
| **Validacija** | Poslije normalizacije: regex **`^[A-Z0-9]*$`** (prazan string dozvoljen gdje je polje opciono). |

- **Nova polja u Blade-u:** preferirati **`<x-license-plate-input>`** umjesto kopiranja `oninput` po fajlovima. Stariji ekrani (guest reserve, agency vozila, FZBR, admin Insight) još mogu imati inline JS — pri izmjeni prebaciti na komponentu.
- **Admin pretraga rezervacija:** `AdminReservationSearchRequest::applyInputNormalization()` (poziv u `ReservationController::index` **prije** provjere kriterijuma i validacije) + `<x-license-plate-input>` u formi. V. **`docs/admin-panel.md`** § Rezervacije.
- **Control — Termini pretraga i dnevna naknada:** `<x-license-plate-input>` u formama; server `ControlReservationSearchRequest::prepareForValidation()` + `MontenegroLicensePlate::normalizeAscii()`; dnevna naknada već normalizuje u `DailyFeeControlCheckRequest`. V. **`control-panel.md`**.
- **Admin pretraga rezervacija — filter agencije:** izbor agencije u dropdown-u filtrira **isključivo** po `reservations.user_id` (`AdminReservationSearchService::buildFiltersFromValidated()`). Auto-popunjeno ime, email i država **ne** ulaze u SQL osim ako korisnik ručno mijenja ta polja nakon izbora agencije (tada se postavlja `narrow_by_contact=1`). Razlog: `reservations.user_name` često se razlikuje od `users.name`. V. **`admin-panel.md`** §1.2.
- **Guest plaćena rezervacija — kategorija vozila:** guest checkout (bez naloga) **ne smije** platiti **nižu** kategoriju od najnovije starije **guest** **`paid`** rezervacije iste tablice; blokada u **`CheckoutController`** prije plaćanja. Agencije (`user_id` not null) i upravljanje vozilima/kategorijama — kroz agency panel; ovo pravilo se na njih ne primjenjuje. V. **`auth-and-guests.md`**, **`admin-panel.md`**.

### 1.2 Država platne kartice (Bankart `billingCountry`)

- Semantika, labeli, pomoćni tekst i lista država: **`auth-and-guests.md`** § *Država platne kartice*.
- **Dropdown:** uvek **`BankartBillingCountry::selectableCountries($locale)`** — guest, registracija, profil, admin Rezervacije; **`config/countries.php`** samo kao kanonski ISO izvor (bez ručnog reorder-a u config-u).
- **Validacija / Bankart:** `selectableCountryCodes()`, `resolveForPayload()` — bez fallback-a; **`OTHER`** zabranjen.

---

## 2. Mail

- **Default SMTP** (`MAIL_*`): **`bus@kotor.me`** — korisnička komunikacija, računi, admin alerti koji nisu „no reply“.
- **Mailer `noreply`** (`MAIL_NOREPLY_*`, `MAIL_FROM_NOREPLY_*`): verifikacija emaila, reset lozinke — vidi `config/mail.php`.

### PDF računi i potvrde (izdavač: Opština Kotor)

- **Logo u PDF-u:** grb Opštine Kotor — **`public/images/logo_kotor.png`**, učitava **`KotorPdfAssets::logoDataUri()`** (plaćeni račun, besplatna potvrda, admin analitika). **Ne** koristiti frontend rebrand (`buslogofull.svg` / `buslogowhite.svg`); web layout i zvanični PDF su odvojeni.
- **Iznos na plaćenom računu** u PDF-u dolazi isključivo iz **`reservations.invoice_amount`** (snapshot pri kreiranju rezervacije), ne iz trenutne **`vehicle_types.price`**. PDF se generiše na zahtev (email ili panel); nema trajnog čuvanja u **`storage/app/invoices`**.
- **Queue jobovi za mejl** (`SendInvoiceEmailJob`, `SendFreeReservationConfirmationJob`, **`SendAdminUpdatedReservationDocumentJob`**): PDF isključivo **`renderBinary`** iz baze; greška → **ne šalji** mejl, **`email_sent`** na **`Reservation::EMAIL_NOT_SENT`**, job **baca izuzetak** (retry preko reda; v. `success-payment-pipeline.md`). **`email_sent`:** `EMAIL_NOT_SENT` (0), `EMAIL_SENT` (1), `EMAIL_SENDING` (2) — konstante u modelu. **`invoice_sent_at` / `email_sent=1` samo poslije uspješnog `Mail::send`**. Logovi na kanalu **`payments`**: `{event}_started|_sent|_failed` sa `reservation_id`, `merchant_transaction_id`, `recipient_email`, `attachment_filename`, `reservation_kind`. Zaglavljeno **`EMAIL_SENDING`** (>15 min) se automatski reclaim-uje. Dijagnostika: **`mail:audit-reservation-documents`**, resend: **`mail:resend-reservation-document --id=`** ili admin resend.
- **Agency promjena tablice — besplatna rezervacija:** poslije uspješnog **`PATCH /panel/reservations/{id}/vehicle`** za `status=free` dispatch **`SendAdminUpdatedReservationDocumentJob`** (ažurirana potvrda, ne fiskalni račun). **Plaćena** i dalje koristi **`SendInvoiceEmailJob`**. Pad email joba **ne** rollback-uje promjenu tablice. V. **`docs/agency-panel.md`** § Promjena tablica.
- **FZBR fulfill (admin odobrenje zahtjeva):** `FreeReservationRequestFulfilledMail` — **sinhrono** u `FreeReservationRequestFulfillmentService` (nije queue); jedan mejl agenciji sa više PDF priloga; `email_sent` po rezervaciji kao gore. V. **`docs/agency-panel.md`** (tabela email faza) i **`docs/cron-commands.md`** §12 (repair/resend).
- Tekst u PDF šablonima (**fiskalni račun**, **nefiskalni račun**, **besplatna potvrda**) je **isključivo na crnogorskom (cg, latinica)** — **nema en varijante** u samom dokumentu; smisao je zvaničnog izdavača u Crnoj Gori.
- **Fiskalni račun** (`pdf/paid-invoice`, `isFiscal`): donji pravni red *„Ovaj račun je generisan automatski i važi kao fiskalni dokument.“*
- **Nefiskalni račun** (isti šablon, `isFiscal = false`): *„Ova potvrda je automatski generisana od strane sistema Opštine Kotor.“* (nije fiskalni dokument u tom smislu).
- **Besplatna potvrda** (`pdf/free-reservation-confirmation`): isti potvrdni tekst u podnožju (bez rečenice o fiskalnom dokumentu).
- **Imena PDF priloga / download (V1):** centralizovano u **`App\Support\ReservationPdfFilename`** (i delegirano sa **`Reservation::invoicePdfFilename()`** / **`freeConfirmationPdfFilename()`**) — plaćeni račun **`invoice-{id}-{Y-m-d}.pdf`**; besplatna potvrda **`free-confirmation-{id}-{Y-m-d}.pdf`**. Datum iz **`reservation_date`**, fallback **`created_at`**. **`sanitizeDateSegment()`** uklanja nedozvoljene znakove. **`forReservation()`** bira po **`status === 'free'`**. Isti format na: email jobovima, admin **`GET /admin/rezervacije/{id}/pdf`**, agency **Promjena tablica** / **Realizovane** (`invoice/view`, `invoice`), FZBR fulfill. Testovi: **`ReservationPdfFilenamePathsTest`**, **`ReservationDocumentEmailHardeningTest`**, **`PlateChangePageTest`**, **`FreeReservationPlateChangeEmailTest`**.
- **Control panel — label tipa vozila:** `VehicleType::formatControlLabel($locale)` — samo naziv/opis, **bez** cijene; kada `vehicle_type_translations.description` već sadrži puni label (npr. poslije migracije opisa), ne duplira se naziv. Ostali user-facing prikazi: **`formatLabel($locale, 'EUR')`** (`Naziv (Opis) - Cena`, sa istom zaštitom od duplog naziva). V. **`docs/control-panel.md`**.

### 2.1 Feature flags (konvencija)

- Feature flags su u `config/features.php` i u `.env` kao `*_ENABLED`.
- Pravilo: feature koji je isključen treba da bude “nevidljiv kao surface” (tipično **404** na rute), ali UI može prikazati disabled stavku ako UX to traži.

### 2.2 MEGA arhiva privatnih fajlova

- Konfiguracija: `config/services.php` (`services.mega`), tajne samo u **`.env`** (`MEGA_EMAIL`, `MEGA_PASSWORD`, opciono `MEGA_BASE_FOLDER`, `MEGA_NODE_BINARY`, `MEGA_USER_AGENT` default `BusKotorArchive/1.0`, `EXTERNAL_ARCHIVE_PREVIEW_TTL_MINUTES`). **Ne** slati kredencijale u frontend ili logove sa sadržajem fajla.
- **Deploy:** Node skripta `scripts/mega-archive.js` zahtijeva npm paket **`megajs`** (`package.json` → `dependencies`). Na serveru poslije `git pull` pokrenuti **`npm ci`** (ili `npm install`) u root-u aplikacije, ne samo `npm run build`.
- Operativni opis: **[external-file-archive.md](./external-file-archive.md)** (tabela `external_file_archives`, komande `files:archive-private`, `files:restore-private`, **`files:mega-diagnose`**, **`files:cleanup-preview-cache`**, Node `scripts/mega-archive.js`; admin **neuspjeli redovi** `/admin/sistemska-arhiva/neuspjeli`). Limo **plate upload** arhiva koristi JPEG derivat (`LimoPlateArchiveDerivativeBuilder`, GD).
- **Limo servis:** `features.limo_service` (ENV `LIMO_SERVICE_ENABLED`) i **mora** imati i `features.advance_payments` ON. Effective rule: \(advance\_payments \land limo\_service\).

### Rezervacije — `reservation_kind` (Dnevna naknada / Daily fee)

- Korisnički naziv: **Dnevna naknada** (CG) / **Daily fee** (EN). Interna vrijednost ostaje **`daily_ticket`**.
- Kolona **`reservation_kind`** na **`reservations`** i **`temp_data`**: `time_slots` (default) | `daily_ticket`.
- Konstante: **`App\Support\ReservationKind`**, aliasi na **`Reservation`** / **`TempData`**; helperi **`isTimeSlots()`**, **`isDailyTicket()`**.
- **Invariant (Phase 2+ checkout/admin):** `time_slots` → oba slot ID NOT NULL; `daily_ticket` → oba NULL (bez sentinel slotova; ne dira **`daily_parking_data`**).
- **Admin Analitika:** popunjenost slotova, delovi dana i operativni slot indikatori koriste **samo** `time_slots`. Dnevna naknada se prikazuje odvojeno; **Limo** u analitici dnevnih naknada = putničko 4+1–7+1 + mini bus 8+1 (`controlDailyFeeListVehicleTypeIds()`); **Autobusi** = ostale kategorije na `daily_ticket`. **Limo pickup (evidencija)** iz `limo_pickup_events` je poseban proizvod.
- **Agency panel — Promjena tablica** (`GET /panel/upcoming`, `PATCH /panel/reservations/{id}/vehicle`): **`PanelReservationListService::allowsPlateChange`**. **Termini** — postojeći upcoming prozor + kategorija + konflikt slotova (`VehicleReplacementCandidateService::hasConflictWithUpcoming`). **Dnevna naknada** — promjena tablice **samo** kad je `reservation_date` **striktno posle** današnjeg dana (`Europe/Podgorica`); isti dan i prošlost blokirani; **bez** Termini slot konflikta. `vehicle_type_id` / `invoice_amount` se ne mijenjaju. Po redu **PDF** link za sve `paid`/`free` predstojeće (isti route kao Realizovane). Poslije promjene tablice: **`SendInvoiceEmailJob`** (paid) ili **`SendAdminUpdatedReservationDocumentJob`** (free). V. **[agency-panel.md](./agency-panel.md)** § Promjena tablica; testovi **`PlateChangePageTest`**, **`FreeReservationPlateChangeEmailTest`**.

### Rezervacije — step forma (GET auto-refresh i scroll)

- **Stranice:** **`GET /guest/reserve`** (`#stepForm`) i **`GET /panel/reservations`** (`#panelStepForm`). Izbor datuma, vrste (`reservation_kind`), vozila ili termina **ponovo šalje istu GET formu** da se osvježe slotovi/cijene — **nije** AJAX checkout (`POST /checkout` ostaje nepromijenjen).
- **Logo banke / kartice:** ispod dugmeta koji pokreće **plaćanje karticom** prikazuje se zajednički partial **`partials/bank-cards-logo.blade.php`** — slika **`public/images/karticebanka.png`** (centrirano). Lokacije: guest **`/guest/reserve`**, agency checkout **`/panel/reservations`**, agency avans top-up **`/panel/avans`** (forma „Pokreni avansnu uplatu”). **Ne** na ostalim ekranima (landing, profil, admin, …).
- **Scroll restore (2026-06-17):** da korisnik ne skače na vrh posle svakog osvježavanja, forma nosi **`data-reservation-auto-scroll`** (`reservation_form_scroll_guest` | `reservation_form_scroll_panel`). Modul **`resources/js/reservationFormScroll.js`** (učitava se iz **`app.js`**):
  - prije submita upisuje **`sessionStorage`** (Y koordinatu, opciono anchor `id`/`name` kontrole, offset ~80px iznad);
  - poslije reloada na **`DOMContentLoaded`** vraća scroll i **briše** ključ;
  - ako Blade postavi **`data-skip-scroll-restore`** (npr. `$errors->any()`), restore se preskače da validacione greške ostanu vidljive.
- **Guest — scroll na grešku poslije checkout POST-a (2026-06-25):** na **`/guest/reserve`**, kad se prikaže validacija ili blokirani checkout (npr. niža kategorija), kontejner **`#guest-reservation-feedback`** dobija **`data-guest-reservation-feedback`**. Isti modul **`reservationFormScroll.js`** na **`DOMContentLoaded`** poziva **`scrollToGuestReservationFeedback()`**: `scrollIntoView({ behavior: 'smooth' })`, offset ~80px za header, kratki ring highlight. **Ne** radi bez markera; **ne** utiče na agency panel, admin ni uspješan redirect na plaćanje. Testovi: **`GuestReservationFeedbackScrollTest`**.
- **Obuhvat:** GET scroll restore — guest + agency step forme; feedback scroll — **samo** guest `/guest/reserve`.
- **Deploy:** nakon izmjene JS pokrenuti **`npm run build`** na okruženju gdje se servira **`public/build`** (folder je u **`.gitignore`**).

---

## 3. Lokalni razvoj (Windows / Laragon)

- **PowerShell u Cursoru:** ne koristiti `&&` za lančanje komandi (stariji PS); koristiti `;` ili posebne linije. Iz korena repoa: `Set-Location c:\laragon\www\bus.kotor.me; .\laragon-artisan.ps1 test` ili **`.\laragon-artisan.cmd test`**.  
  **`php` često nije u PATH-u** u Cursor terminalu. Za **`php artisan`** koristi **jedno od**:
  1. **Skripta u rootu repoa:** `.\laragon-artisan.ps1 <artisan-arg>...` — bira najnoviji `php.exe` pod `C:\laragon\bin\php\`. Za test suite: **`.\laragon-artisan.ps1 test`** (ekvivalent `php artisan test`).
  2. **Ako PowerShell javlja „running scripts is disabled“ (Execution Policy):** koristi **`.\laragon-artisan.cmd <artisan-arg>...`** — isti efekat, **nije** `.ps1` pa politika ne blokira. Alternativa jednokratno:  
     `powershell -ExecutionPolicy Bypass -File .\laragon-artisan.ps1 queue:work --tries=1`  
     Trajnije (samo za tvoj nalog): `Set-ExecutionPolicy -Scope CurrentUser RemoteSigned`.
  3. **Eksplicitna putanja** (primer; folder verzije proveri sa `dir C:\laragon\bin\php`):  
     `C:\laragon\bin\php\php-8.3.30-Win32-vs16-x64\php.exe artisan ...`
- **Sintaksa (`php -l`):** ne pokretati gol `php -l` ako Windows nudi „Open with…“ — koristi **`.\laragon-php.ps1 -l putanja\do\fajla.php`** ili **`.\laragon-php.cmd -l putanja\do\fajla.php`**.
- **AI / automatizacija (Cursor agent, skripte):** iz korena repoa **`.\laragon-artisan.ps1`** ili **`.\laragon-artisan.cmd`** (npr. `test`, `migrate`, `queue:work`) — **ne** `php artisan ...` osim ako je `php` u PATH-u. Isto **`.\laragon-php.ps1`** / **`.\laragon-php.cmd`** umesto `php` za `-l`. Kada korisnik ima strogu Execution Policy, u primerima predložiti **`.cmd`**.
- **Git / GitHub:** nakon izmjena u kodu i **`docs/`**, uobičajeni tok je `git add`, `git commit` sa jasnom porukom, zatim **`git push origin`** (grana u kojoj radiš, npr. `main`). Prije push-a poželjno pokrenuti **`.\laragon-artisan.cmd test`** (ili ciljani podskup testova).
- **MySQL test suite (opciono):** podrazumijevani testovi koriste SQLite (`phpunit.xml`). Za pokretanje protiv MySQL baze čije ime **mora završavati na `_test`**, `phpunit.mysql.xml` i Laragon putanje, vidi **[testing-mysql.md](./testing-mysql.md)** — uključujući **`mysql.exe` u `PATH`** na Windowsu i record **Verified local MySQL run**. Puni MySQL suite je preporučena **pre-release** provjera šeme kao u produkciji.

### 3.1 Blade napomena (parse error)

- U ovom projektu **izbegavati** Blade shorthand `@php($x = ...)` — u praksi je na Windows okruženju više puta izazvao kompajlirani view sa **ParseError** (`unexpected token "endif"`). Umesto toga koristi blok:

```php
@php
    $x = ...;
@endphp
```

### 3.2 Admin UI: jezik (sr-Latn-ME)

- U admin panelu koristiti ispravan oblik: **„Izvještaji“** (ne „Izveštaji“).
- Za riječ **istorija** (i prideve *istorijski*, *istorijska*, genitiv *istorije*, akuzativ *istoriju*) koristiti oblik sa **i**, ne **historija** / *historijski* — u svim vidljivim labelama, porukama i docs tekstu na srpskom. **Ne** mijenjati engleske identifikatore u kodu (`historical_*`, `*_than_history`, imena klasa/servisa).
- **Queue:** za lokalni QA bez workera, **`QUEUE_CONNECTION=sync`** u `.env` — tada **nema** posebnog workera (jobovi se izvršavaju u istom zahtevu). Za **`database`** / **`redis`** mora da radi **`queue:work`** (npr. **`.\laragon-artisan.cmd queue:work --tries=1`**). **Provera da li worker radi (Windows):** u Task Manageru pogledati **`php.exe`** i komandnu liniju da sadrži `artisan queue:work`, ili u PowerShellu npr. `Get-CimInstance Win32_Process -Filter "Name = 'php.exe'" | Select-Object CommandLine`. Ako se poslovi gomilaju, proveri tabelu **`jobs`** (driver `database`). **Test mejlova:** uz `sync` dovoljno je **`MAIL_MAILER=log`** (ili Mailtrap); uz asinhroni red pokreni worker pre akcije koja dispatchuje mejl.
- **Queue worker u pozadini (PowerShell, bez zauzimanja terminala):** ako `php` nije u PATH-u, koristi **punu putanju** do Laragon `php.exe`.
  - Pokretanje:
    - `$php = "C:\laragon\bin\php\php-8.3.30-Win32-vs16-x64\php.exe"` (prilagodi verziju)
    - `Start-Job -Name "buskotor-queue" -ScriptBlock { Set-Location "C:\laragon\www\bus.kotor.me"; $php = $using:php; while ($true) { & $php artisan queue:work --sleep=1 --tries=1 --timeout=120; Start-Sleep -Seconds 1 } }`
  - Provera:
    - `Get-Job -Name "buskotor-queue"`
    - `Receive-Job -Name "buskotor-queue" -Keep`
  - Zaustavljanje:
    - `Stop-Job -Name "buskotor-queue"; Remove-Job -Name "buskotor-queue"`

### Fake QA: sync vs queue režim

Pretpostavka: **`BANK_DRIVER=fake`** i **`FISCALIZATION_DRIVER=fake`**.

| Režim | Tipičan `.env` | Ponašanje |
|--------|----------------|-----------|
| **Fake QA queue** | `QUEUE_CONNECTION=database`, **`FAKE_PAYMENT_E2E_SYNC=false`** | **`PaymentCallbackJob`**, **`ProcessReservationAfterPaymentJob`**, **`SendInvoiceEmailJob`** (gdje ih šalje **`QueueMode`**) idu na **red** → potreban **`queue:work`**. |
| **Fake QA sync** | npr. `QUEUE_CONNECTION=database`, **`FAKE_PAYMENT_E2E_SYNC=true`** | Isti fake pipeline za te jobove ide **`dispatch_sync`** → **worker nije bitan** za taj happy path. |

**`FAKE_PAYMENT_E2E_SYNC`** je prekidač sync vs queue za fake QA pipeline (**`App\Support\QueueMode`**). **`QUEUE_CONNECTION=database`** samo **omogućava** perzistentni red; **ne garantuje** red ako kod koristi **`dispatch_sync`** (npr. kad je sync režim uključen, ili **`QueueMode::dispatchPaymentCallbackSyncForFakeQaForm`** na fake-bank formi — callback uvek inline u istom HTTP zahtevu).

### Frontend (Vite / Tailwind) — build, ne zavisnost od dev servera

- **`npm run build`** generiše **`public/build/*`**. Laravel tada učitava **statičke** CSS/JS; UI treba da izgleda **isto kao u dev modu** i da **radi bez** pokrenutog Vite servera.
- **`npm run dev`** (Vite na npr. `localhost:5173`) je samo za **aktivan** rad na `resources/css` / `resources/js` / utility klasama u Blade-u. Ako nešto radi **samo** uz `npm run dev`, a bez builda ne — to je **bug**.
- Za lokalni **production-like** test: posle izmena fronta uraditi **`npm run build`**, ne oslanjati se na trajno držanje `npm run dev`.

### Lokalni HTTPS (Laragon)

- Aplikacija se može servirati preko **HTTPS** (npr. **`https://bus.kotor.me.test`**, Apache SSL + Laragon cert).
- U **`.env`** postaviti **`APP_URL=https://bus.kotor.me.test`** (ili odgovarajući HTTPS host) da **`url()` / `route()` / asseti** budu **HTTPS-safe**.
- **Fake fiskal** (`FISCALIZATION_DRIVER=fake`) zove istu aplikaciju preko HTTPS; ako je cert **self-signed**, cURL javlja **„SSL certificate problem: self-signed certificate“ (60)**. U **`.env`** privremeno stavi **`FISCAL_HTTP_VERIFY_SSL=false`**, zatim **`php artisan config:clear`**. U produkciji ostavi **`true`** (podrazumevano) — ne isključuj verifikaciju prema pravom fiskalnom API-ju.
- Izbegavati **hardcoded `http://`** u kodu gde to utiče na korisničke linkove ili očekivanja okruženja.

### Minimalni `.env` za lokalni QA (fake tok, bez workera)

```env
BANK_DRIVER=fake
FISCALIZATION_DRIVER=fake
FAKE_PAYMENT_E2E_SYNC=true
QUEUE_CONNECTION=sync
```

Za **fake QA queue** režim (worker + `jobs`): `QUEUE_CONNECTION=database`, **`FAKE_PAYMENT_E2E_SYNC=false`**. Vidi podsekciju **Fake QA: sync vs queue režim** iznad.

Posle izmene `.env`: `.\laragon-artisan.ps1 config:clear` (ili ista PHP putanja + `artisan config:clear`). Za izmene u Tailwind/JS vidi podsekciju **Frontend** iznad (`npm run dev` tokom rada, **`npm run build` pre provere bez Vite-a ili pre deploy-a).


---

## 4. Rute i redirect (Laravel)

- Za relativne URL-ove (CSRF / različiti host test domen):  
  `redirect()->to(route('ime.rute', [], false))` — **ne** `redirect()->route(..., [], false)` (može dati nevalidan status).
- Posle **logout** (web guard): **`redirect()->away($request->root())`** da odredište prati stvarni host zahteva (npr. Laragon `*.test`), a ne isključivo `APP_URL`.

---

## 5. Payment / parking

- **`temp_data`:** životni ciklus plaćanja i audit; ne brisati pri grešci bez operativnog pravila. Nakon uspješnog Bankart `createSession`, **`payment_redirect_url`** se čuva na pending redu; ponovni checkout istog bookinga reuse-uje URL umjesto duplog `createSession` (v. **`workflow-placanje-temp-data.md`** §1d).
- **`daily_parking_data`:** uvek paziti na **oba** slota (`drop_off_time_slot_id`, `pick_up_time_slot_id`); ako su isti ID, brojač jednom.
- **`reservations.created_by_admin`:** boolean, default **`false`** (kolona u bazi; signal za buduća admin/free poslovna pravila — trenutno svi postojeći i standardni tokovi ostaju `false`). Blokiranje i prilagođavanje u glavnom admin panelu: post-lock validacija slotova, prefiltar datuma u UI, redirect sa query **`_fresh`** — v. **[admin-panel.md](./admin-panel.md)** §2.
- **Idempotency:** ključ `merchant_transaction_id`.
- **`merchant_transaction_id` (rezervacije):** jedinstveni **korelacioni** identifikator (temp → plaćanje / status → rezervacija; v. i `payment-concurrency.md`). **Ne koristi se za razlikovanje „tipa“ rezervacije.** Za **plaćeno vs besplatno** koristi se **`reservations.status`**; za **gost/agency vs admin-kreirana besplatna** (oba `free`) koristi se **`reservations.created_by_admin`** uz **`status`**.
- **Korisnički ishod plaćanja / besplatnog checkout-a (flash):** session ključ **`checkout_banner`** — niz `level` (`success` | `info` | `error`), `title_key`, `message_key`, `group` (uglavnom **`checkout_result`**). Mapiranje ishoda: **`App\Support\CheckoutResultFlash`**; tekstovi u **`ui_translations`** grupi **`checkout_result`** (seed **`UiTranslationsSeeder`**). Plaćena rezervacija: **`paid_success_*`** (JIR gotov), **`paid_processing_*`** (plaćanje ok, fiskal/mejl još u obradi — npr. async queue), **`fiscal_delayed_*`** (nerešen **`post_fiscalization_data`**). Prikaz: **`resources/views/partials/checkout-result-banner.blade.php`** na **`guest.reserve`** i **`panel.reservations`**.
- **Redirect posle završnog statusa:** **`PaymentReturnController`** za **`success` / `failed` / `late_success`** šalje korisnika na **`guest.reserve`** (gost) ili **`panel.reservations`** (ulogovan), sa odgovarajućim **`checkout_banner`**. **`GET /payment/return`** na ekranu zadržava samo **`pending`** (tekst + polling na **`/payment/result`**); layout: **`x-guest-layout`** ako nema sesije, **`x-app-layout`** ako je korisnik ulogovan (`resources/views/payment/return.blade.php` + **`payment/partials/return-pending-body.blade.php`**).

### Payment amount integrity

- Nakon kreiranja `temp_data`, cijena se smatra zaključanom (`invoice_amount_snapshot`).
- Nikada ne koristiti `vehicle_types.price` (ili druge runtime izvore) u payment flow-u nakon tog trenutka.
- Svi downstream procesi (reservation, fiskalizacija, late_success → advance) moraju koristiti snapshot iz `temp_data`.
- Korišćenje runtime cijene u payment flow-u nakon checkout-a smatra se bugom.

---

## 6. Front (Vite / Tailwind)

- Stilovi se učitavaju preko **`@vite`** u layoutu.
- **Password polje + oko:** overlay stilovi su u **`resources/views/partials/password-field-overlay-styles.blade.php`** (uključeno u guest i app layout) da layout radi i kada **`public/build` nije sveže** generisan; ipak za izmene Tailwind utility-ja u `.blade` fajlovima povremeno pokreni **`npm run dev`** ili **`npm run build`**.

---

## 7. Ažuriranje TODO / DONE

- Otvoreno: **`docs/project-todo.md`**
- Završeno: **`docs/project-done.md`**
- Nova sesija u Cursoru: **`docs/README.md`** (ulaz u folder), zatim **`docs/handoff-new-chat.md`**

---

## 8. Tematska dokumentacija u `docs/`

U **`docs/`** postoje dubiji opisi po domenima (payment, fiskal, cron, auth, admin, fake vs real, itd.). Oni moraju da poštuju **§ 0** (nema kontradikcije sa kodom).

**Indeks:** `docs/project-status-next-steps.md` → „Ostala dokumentacija“. Primer česte greške: zastareo URL **`/api/payments/callback`** — u aplikaciji je **`POST /api/payment/callback`**. **Produkcija:** `production-runbook.md`, `production-hardening.md`.

**Agency panel** (`/panel`, rezervacije, upcoming/realized, korisnik): **[agency-panel.md](./agency-panel.md)**. **Control panel** (`/control`, dolasci): **[control-panel.md](./control-panel.md)**.

---

## 9. Agency panel (kratko)

- Rute pod prefiksom **`/panel`**, vidi **`docs/agency-panel.md`** (rezervacije, vozila, upcoming/realized, korisnik, invoice).
- **Korisničko uputstvo (PDF):** **`docs/agency-user-guide.md`** — `public/docs/cgbuskotor.pdf`, `engbuskotor.pdf`.
- **Control:** prefiks **`/control`**, vidi **`docs/control-panel.md`**.
- Korisnički tab: **`/panel/user`** — forma u `panel/partials/user-settings-form.blade.php`, **`PATCH /profile`**; brisanje naloga koristi **`user.delete_account_*`** u **`ui_translations`**.
