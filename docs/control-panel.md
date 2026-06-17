# Control panel (operativni dolasci)

**Poslednje ažuriranje:** 2026-06-10  

Lagani panel za šalter / kontrolu ulaska: **login**, **grupe dolazaka po terminu** i **pretraga rezervacija**. Odvojen je od **agency** panela (`/panel`, `User`), od **glavnog admin panela** (`/admin`, guard `panel_admin`, `admins.admin_access`) i od **operativnog staff pregleda** (`/staff`, `User` + `AdminMiddleware` — rezervacije, late-success).

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
- **Servis:** `App\Services\Control\DailyFeeControlService` — normalizacija tablice (`DuplicateReservationAttemptService::normalizeLicensePlate`), pretraga **`reservation_kind = daily_ticket`**, **`status = paid`**, **`reservation_date` = danas** (`Europe/Podgorica`).
- **Samo čitanje:** nema plaćanja, fiskalizacije, emaila, OCR-a, GPS-a, QR-a, izmjena rezervacija.
- **Rezultat:** „Plaćena dnevna naknada: DA/NE“ + detalji (agencija, datum važenja, tip vozila, email, vrijeme kreiranja). Više pogodaka istog dana/tablice — lista.
- **Lista za danas (dno stranice):** tabela svih **plaćenih** dnevnih naknada za **današnji** `reservation_date` (`Europe/Podgorica`) čiji je `vehicle_type_id` u kategorijama **putničko/limo 4+1–7+1** ili **minibus 8+1** (`ReservationVehicleEligibilityService::controlDailyFeeListVehicleTypeIds()` — ID-jevi iz `vehicle_type_translations`, ne hardkodirani). Sort: `license_plate` ASC. Prazno stanje: *Nema vozila sa plaćenom dnevnom naknadom za danas.* Ručna provjera tablice ostaje nepromijenjena.
- **Ne provjerava:** termine (`time_slots`), historijske Limo QR tabele, druge datume.
- **Testovi:** `tests/Feature/Control/DailyFeeControlTest.php`.

---

## Napomena: “Agencije” i avans u Control panelu

U trenutnom stanju repoa (V2) **Control panel (`/control`) nema modul “Agencije”** niti prikaz avans podataka.

Avans (advance payments) i agencije sa avans podacima su u **Admin panelu (`/admin`)**, modul **Agencije**:
- `GET /admin/agencije` / `GET /admin/agencije/{user}`
- prikaz salda avansa + ledger/topup istorija (iza feature flag-a `config('features.advance_payments')`)

Ako planiraš da u Android “control/staff” aplikaciji postoji ekran “Agencije” (read-only avans), taj ekran treba dokumentovati u odgovarajućem doc-u (ili ovde, ili u `docs/admin-panel.md`, u zavisnosti od toga pod kojim guard-om će živeti).

---

## Dolasci po terminu (vidljivost)

Servis: **`App\Services\Control\ControlArrivalSlots`**.

- Za **danas** i **sutra** (kalendarski dan **`reservation_date`**) prolazi se svaki red **`list_of_time_slots`**.
- Termin ulazi u listu ako je trenutno vreme u prozoru **`ListOfTimeSlot::isInArrivalControlWindow($now, $day, 3)`**: od **(početak termina − 3 h)** do **kraja termina** (uključujući parsiranje **`24:00`** kao ponoći **sledećeg** dana — v. `getEndTimeForDate`).
- **`$now`** i dani su u **`config('reservations.operations_timezone')`** (podrazumevano kao `APP_TIMEZONE`, npr. `Europe/Podgorica`).

Za svaki vidljivi termin učitavaju se rezervacije gde je **`reservation_date`** taj dan i gde je termin ili **drop-off** ili **pick-up**:

- `drop_off_time_slot_id = slot.id` **ILI** `pick_up_time_slot_id = slot.id`

**Nema filtra po `status`:** isto pravilo važi za **`paid`**, **`free`** i **miješane** parove termina (jedan „besplatan“ prozor, drugi plaćeni) — bitan je samo ID termina i datum.

Ista rezervacija može da se pojavi u **dve grupe** ako su u prozoru istovremeno i drop-off i pick-up termin (različiti slotovi).

---

## Admin lista rezervacija (usklađen prozor, drugačiji filter)

**`Reservation::scopeNextThreeHours`** koristi **isti** `isInArrivalControlWindow`, ali filtrira samo **`drop_off_time_slot_id`** (podrazumevani filter na admin listi). Control dashboard eksplicitno uključuje i **pick-up**.

---

## Testovi

- **`tests/Unit/ListOfTimeSlotArrivalWindowTest.php`** — prozor dolaska i `24:00`.
- **`tests/Feature/Control/`** — feature testovi za control rute (gde postoje).

---

## Povezano

- Operativna TZ: **`config/reservations.php`** (`operations_timezone`).
- Logout **web** korisnika / agencija koristi **`redirect()->away($request->root())`** da redirect prati stvarni host (Laragon / više domena) — v. `AuthenticatedSessionController`, `ProfileController`.
