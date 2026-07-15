# Control panel (operativni dolasci)

**Poslednje ažuriranje:** 2026-06-25  

Lagani panel za šalter / kontrolu ulaska: **login**, **grupe dolazaka po terminu**, **grafikon kapaciteta**, **pretraga rezervacija (Termini)** i zasebno **kontrola dnevne naknade** (uključujući provjeru tablice za komunalnu policiju). Odvojen je od **agency** panela (`/panel`, `User`), od **glavnog admin panela** (`/admin`, guard `panel_admin`, `admins.admin_access`) i od **operativnog staff pregleda** (`/staff`, `User` + `AdminMiddleware` — rezervacije, late-success).

---

## Rute i autentikacija

| Šta | Ruta / ime |
|-----|------------|
| Login forma | `GET /control/login` → `control.login` |
| Login POST | `POST /control/login` → `control.login.store` |
| Logout | `POST /control/logout` → `control.logout` |
| Dashboard | `GET /control/` → `control.dashboard` |
| Kontrola dnevne naknade | `GET /control/dnevna-naknada` → `control.daily_fee.index` |
| Provjera tablice (POST) | `POST /control/dnevna-naknada/provjeri` → `control.daily_fee.check` |

- **Guard:** `control` (`config/auth.php`) — session, provider **`admins`**, model **`App\Models\Admin`**.
- **Gost na `/control/*`:** `bootstrap/app.php` šalje neulogovane na **`control.login`** (ne na `login` za agencije).
- **Pristup nalogu:** u tabeli **`admins`** mora biti **`control_access = true`**; login proverava email + lozinku i ovaj flag (`ControlAuthController`).
- **Migracija:** `2026_04_06_120000_add_control_access_to_admins_table.php` (kolona + za postojeći red `username = control` postavlja pristup).
- **Seed:** `AdminsSeeder` — npr. nalog sa `control_access` za lokalni QA (email v. seeder; lozinka je heš u seedu).

Tekstovi u view-ima su **hardcoded CG stringovi** (nema `UiText` grupe za control), namerno kratak operativni UI.

---

## Kontrola dnevne naknade (2026-06)

**Phase 3:** QR provjera je ukinuta; komunalna policija / kontrolori ručno unose registarsku tablicu.

- **Rute:** `control.daily_fee.index`, `control.daily_fee.check` — isti guard **`auth:control`** (`admins.control_access = true`).
- **Servis:** `App\Services\Control\DailyFeeControlService` — normalizacija tablice (`DuplicateReservationAttemptService::normalizeLicensePlate`), ručna provjera za **današnji** `reservation_date` (`Europe/Podgorica`): **plaćena dnevna naknada** (`daily_ticket` + `paid`) **ili** **rezervacija/potvrda termina** (`time_slots` + `paid`/`free`, uklj. legacy `reservation_kind` NULL).
- **Unos tablice:** `<x-license-plate-input>` + `DailyFeeControlCheckRequest` (server regex `^[A-Z0-9]+$`).
- **Samo čitanje:** nema plaćanja, fiskalizacije, emaila, OCR-a, GPS-a, QR-a, izmjena rezervacija.
- **Rezultat:** „Plaćena dnevna naknada: DA“ i/ili „Rezervacija termina za danas: DA“ (ili „Važeća rezervacija za danas: NE“) + detalji po pogotku (vrsta, agencija, datum važenja, tip vozila, email, vrijeme kreiranja). Više pogodaka istog dana/tablice — lista.
- **Lista za danas (dno stranice):** tabela svih **plaćenih** dnevnih naknada za **današnji** `reservation_date` (`Europe/Podgorica`) čiji je `vehicle_type_id` u kategorijama **putničko/limo 4+1–7+1** ili **minibus 8+1** (`ReservationVehicleEligibilityService::controlDailyFeeListVehicleTypeIds()` — ID-jevi iz `vehicle_type_translations`, ne hardkodirani). Kolone: tablica, agencija/korisnik, tip vozila, vrijeme kupovine, datum važenja (**bez** kolone email — štednja prostora na terenu). Naslov sekcije prikazuje **Ukupno vozila** (broj redova na listi). Sort: `license_plate` ASC. Prazno stanje: *Nema vozila sa plaćenom dnevnom naknadom za danas.* **Lista se ne mijenja** kada ručna provjera tablice uključuje i Termine — to su dva odvojena prikaza.
- **Osvježavanje:** isto kao dashboard Termini — prikaz **Posljednje osvježavanje**, dugme **Osvježi sada** (`window.location.reload()`), automatski reload svakih **5 minuta** (`resources/views/control/daily-fee-control.blade.php`).
- **Ne provjerava:** istorijske Limo QR tabele, druge datume. Lista na dnu stranice i dalje samo plaćene dnevne naknade (v. ispod).
- **Testovi:** `tests/Feature/Control/DailyFeeControlTest.php`.

---

## Napomena: “Agencije” i avans u Control panelu

U trenutnom stanju repoa (V2) **Control panel (`/control`) nema modul “Agencije”** niti prikaz avans podataka.

Avans (advance payments) i agencije sa avans podacima su u **Admin panelu (`/admin`)**, modul **Agencije**:
- `GET /admin/agencije` / `GET /admin/agencije/{user}`
- prikaz salda avansa + ledger/topup istorija (iza feature flag-a `config('features.advance_payments')`)

Ako planiraš da u Android “control/staff” aplikaciji postoji ekran “Agencije” (read-only avans), taj ekran treba dokumentovati u odgovarajućem doc-u (ili ovde, ili u `docs/admin-panel.md`, u zavisnosti od toga pod kojim guard-om će živeti).

---

## Dashboard Termini (`GET /control`)

Kontrolor za **Termine** koristi glavni dashboard. **Dnevna naknada** ima poseban ekran **`/control/dnevna-naknada`** (v. gore).

- **Osvježavanje (Termini dashboard):** **Posljednje osvježavanje**, dugme **Osvježi sada**, automatski reload svakih **5 minuta** (`resources/views/control/dashboard.blade.php`).

### Grafikon kapaciteta (danas + sutra)
- **Stubci po terminu (`reserved`, `pending`, `total`):** iz **`daily_parking_data`** za taj kalendarski dan (`Europe/Podgorica`) — trenutno stanje soft-locka i potvrđenih rezervacija po slotu. **Dnevna naknada ne dira** `daily_parking_data`, pa se u stubcima ne vidi.
- **Crvena linija kapaciteta:** `SystemConfig::availableParkingSlots()` (ne `daily_parking_data.capacity` na grafikonu).
- **„Ukupno rezervacija: X (plaćene + besplatne)“:** broj iz tabele **`reservations`** za taj dan — samo **`time_slots`** (ili legacy `reservation_kind` NULL), `status IN (paid, free)`.
- **Partial:** `resources/views/partials/daily-capacity-chart.blade.php`; isti dataset na admin **Upozorenja** (`WarningsController`).
- **Testovi:** `tests/Feature/Control/DailyCapacityChartsRenderTest.php`, `tests/Unit/Operations/DailyCapacityChartServiceTest.php`.

### Pretraga rezervacija

- **Kontroler:** `ControlDashboardController::searchReservations()`; validacija `ControlReservationSearchRequest` (tablica: `MontenegroLicensePlate::normalizeAscii`, regex `^[A-Z0-9]*$`).
- **Obavezno:** bar jedan kriterijum (datum, ime, email, tip vozila, tablica, status).
- **Tablica u pretrazi:** `<x-license-plate-input>` u formi; SQL upit normalizuje i upoređuje bez razmaka (`REPLACE(UPPER(license_plate), ' ', '') LIKE …`), kao admin pretraga rezervacija.
- **Samo Termini:** u kodu je fiksiran filter `reservation_kind = time_slots` (ili `NULL` za stare redove). **Dnevna naknada** se ne prikazuje — za nju je `/control/dnevna-naknada`.
- **Datum:** ako je unesen — filter **striktno** po tom `reservation_date`. Ako **nije** unesen — pretraga ide **preko svih datuma** (prošlost, danas, budućnost); **nema** implicitnog cut-offa `>= danas`. Sort bez datuma: `reservation_date` DESC, zatim `id` DESC. Polje u formi: native **`input type="date"`** (`name="date"`, submit **`Y-m-d`**) — namjerno **ne** `iso-date-input` zbog pouzdanosti na iPhone/Safari (v. **`project-conventions.md`** §1).
- **Status (opciono u formi):** `paid` / `free` / bilo koji (bez UI za `daily_ticket`).
- **Tip vozila u rezultatima:** `VehicleType::formatControlLabel('cg')` — bez cijene i bez duplog naziva kada je opis već puni label (v. `VehicleType` u kodu).

### Dolasci po terminu (vidljivost)

Servis: **`App\Services\Control\ControlArrivalSlots`**.

- Za **danas** i **sutra** (kalendarski dan **`reservation_date`**) prolazi se svaki red **`list_of_time_slots`**.
- Termin ulazi u listu ako je trenutno vreme u prozoru **`ListOfTimeSlot::isInArrivalControlWindow($now, $day, 1)`** (`ControlArrivalSlots::PREVIEW_HOURS_BEFORE_START`): od **(početak termina − 1 h)** do **kraja termina** (uključujući parsiranje **`24:00`** kao ponoći **sledećeg** dana — v. `getEndTimeForDate`). Kraći prozor smanjuje listu u špicu; starije termine kontrolor traži pretragom.
- **`$now`** i dani su u **`config('reservations.operations_timezone')`** (podrazumevano kao `APP_TIMEZONE`, npr. `Europe/Podgorica`).

Za svaki vidljivi termin učitavaju se rezervacije gde je **`reservation_date`** taj dan i gde je termin ili **drop-off** ili **pick-up**:

- `drop_off_time_slot_id = slot.id` **ILI** `pick_up_time_slot_id = slot.id`

**Nema filtra po `status`:** isto pravilo važi za **`paid`**, **`free`** i **miješane** parove termina (jedan „besplatan“ prozor, drugi plaćeni) — bitan je samo ID termina i datum. Prikaz tipa vozila: **`formatControlLabel('cg')`** (npr. `Putničko vozilo (4+1, 5+1, 6+1 i 7+1 mjesta)`), ne `formatLabel` sa cijenom.

Ista rezervacija može da se pojavi u **dve grupe** ako su u prozoru istovremeno i drop-off i pick-up termin (različiti slotovi).

---

## Admin lista rezervacija (usklađen prozor, drugačiji filter)

**`Reservation::scopeNextThreeHours`** koristi **isti** `isInArrivalControlWindow`, ali filtrira samo **`drop_off_time_slot_id`** (podrazumevani filter na admin listi). Control dashboard eksplicitno uključuje i **pick-up**.

---

## Testovi

- **`tests/Unit/ListOfTimeSlotArrivalWindowTest.php`** — prozor dolaska i `24:00`.
- **`tests/Feature/Control/ControlPanelTest.php`** — login, dolasci, pretraga (ukl. filter statusa i isključenje `daily_ticket`).
- **`tests/Feature/Control/DailyFeeControlTest.php`** — dnevna naknada, provjera tablice (ukl. Termini), lista za danas.
- **`tests/Feature/Control/DailyCapacityChartsRenderTest.php`** — grafikon na dashboardu.

---

## Povezano

- Operativna TZ: **`config/reservations.php`** (`operations_timezone`).
- Logout **web** korisnika / agencija koristi **`redirect()->away($request->root())`** da redirect prati stvarni host (Laragon / više domena) — v. `AuthenticatedSessionController`, `ProfileController`.
