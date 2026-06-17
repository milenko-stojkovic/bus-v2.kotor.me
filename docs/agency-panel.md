# Agency panel (ulogovani korisnik, `/panel`)

**Poslednje ažuriranje:** 2026-06-17

Prefiks ruta: **`/panel`**, middleware **`auth`** + **`verified`**. Gornja navigacija: `resources/views/layouts/navigation.blade.php`.

---

## Rute (sažetak)

| Ruta | Naziv rute (izbor) | Opis |
|------|-------------------|------|
| `GET /panel/reservations` | `panel.reservations` | Nova rezervacija (`ReservationBookingPageData`) — **Termini** (slotovi) ili **Dnevna naknada** (bez slotova; agencija kartica/avans). GET auto-refresh pri izboru datuma/vrste/vozila/termina; scroll pozicija se čuva u **`sessionStorage`** (v. **`project-conventions.md`** § Rezervacije — step forma). |
| `GET /panel/upcoming` | `panel.upcoming` | **Promjena tablica** — promjena registarske tablice na budućim rezervacijama (samo Termini) |
| `GET /panel/realized` | `panel.realized` | Realizovane, link na PDF u novom tabu |
| `GET /panel/vehicles` | `panel.vehicles` | Vozila |
| `GET /panel/fzbr` | `panel.fzbr.create` | **Besplatne rezervacije** — podnošenje zahtjeva |
| `GET /panel/avans` | `panel.advance.index` | Avans: stanje, ledger i istorija topup pokušaja |
| `POST /panel/avans/topup` | `panel.advance.topup.store` | Pokretanje avansne uplate (kreira topup attempt + start payment) |
| `GET /panel/avans/return` | `panel.advance.return` | Povratak sa banke (status se čita iz baze) |
| `GET /panel/statistics` | `panel.statistics` | Statistika: ukupno plaćeno, broj realizovanih posjeta, tabela po tablicama |
| `GET /panel/limo` | `panel.limo.index` | **Limo** — informativna stranica (Stari grad, mjesta ukrcaja, obaveza dnevne naknade) |
| `POST /panel/limo/qr/generate` | `panel.limo.qr.generate` | **Deprecated** — 404 osim ako je `LIMO_QR_WORKFLOW_ENABLED=true` |
| `GET /panel/limo/qr/{limoQrToken}` | `panel.limo.qr.show` | **Deprecated** — 404 (isti flag) |
| `GET /panel/limo/qr/{limoQrToken}/pdf` | `panel.limo.qr.pdf` | **Deprecated** — 404 (isti flag) |
| `GET /panel/user` | `panel.user` | Korisnik: ime, jezik, email, lozinka |
| `PATCH /profile` | `profile.update` | Čuva profil (uključujući lozinku ako je uneta) |
| `PATCH /panel/reservations/{id}/vehicle` | `panel.reservations.vehicle` | Promjena tablice/vozila na predstojećoj **Termini** rezervaciji |
| `GET /panel/reservations/{id}/invoice/view` | `panel.reservations.invoice.view` | PDF **inline** (browser tab) |
| `GET /panel/reservations/{id}/invoice` | `panel.reservations.invoice` | PDF **download** |

`GET /profile` redirectuje na `/panel/user` (`profile.edit`).

---

## Avans (advance payments) — implementirano (feature-flag)

**Feature flag:** `config('features.advance_payments')` (ENV `ADVANCE_PAYMENTS_ENABLED`).

Ako je flag **OFF**:
- navigaciona stavka **Avans** se vidi kao disabled (bez linka) uz tooltip
- avans rute (`/panel/avans*`) vraćaju **404**
- na strani **Rezervacije** se ne prikazuju avans opcije

Ako je flag **ON**:

### 1) Uplate avansa (topup)

- **Stranica:** `GET /panel/avans` (`panel.advance.index`)
- Prikazuje:
  - trenutno stanje avansa (saldo) = **SUM(agency_advance_transactions.amount)**
  - ledger istoriju (poslednjih 50)
  - istoriju topup pokušaja (poslednjih 50)

**Pokretanje uplate:** `POST /panel/avans/topup`
- kreira `agency_advance_topups` red (status `pending`, MTID UUID)
- startuje payment session (Bankart) ili u fake driveru odmah tretira kao `paid`
- **ne koristi `temp_data`**

**Potvrda uplate (paid):**
- topup prelazi u `paid` (callback / fake)
- kreira se ledger red u `agency_advance_transactions`:
  - `type=topup`, `reference_type=advance_topup`, `reference_id=topup.id`, `amount=+X`

**avansna uplata se NE fiskalizuje**
- razlog: uplata avansa predstavlja povećanje obaveze Opštine prema agenciji (prepaid saldo), a ne realizovan prihod
- fiskalizacija nastaje tek kada se avans iskoristi za kupovinu rezervacije ili dnevne naknade kroz standardni paid reservation pipeline

### 2) Potvrda o evidentiranoj avansnoj uplati (PDF + email)

Nakon uspešnog topup-a (`paid`) šalje se email agenciji sa PDF potvrdom u prilogu:
- dokument je **potvrda**, **nije račun** i **nije fiskalni račun**
- evidencija slanja je na topup attempt-u:
  - `agency_advance_topups.confirmation_sent_at`
  - `agency_advance_topups.confirmation_email`

### 3) Plaćanje rezervacije iz avansa

Na `GET /panel/reservations` (rezervacije/checkout), kada je flag ON:
- prikazuje se **Raspoloživi avans** i izbor načina plaćanja:
  - kartica (postojeći tok)
  - avans (`payment_method=advance`) — disabled ako saldo nije dovoljan

Backend tok `payment_method=advance`:
- **ne koristi `temp_data`** i **ne ide na Bankart**
- u transakciji zaključava (lock) agenciju + relevantne `daily_parking_data` redove
- finalno proverava saldo pod lock-om
- kreira `Reservation` odmah kao `paid` sa `payment_method=advance`
- kreira ledger `agency_advance_transactions` red:
  - `type=usage`, `amount=-invoice_amount`, `reference_type=reservation`, `reference_id=reservation.id`
- pokreće standardni post-payment pipeline za `paid` rezervacije (invoice/fiskalizacija kao i inače)

### Kategorije vozila — Termini vs dnevna naknada (2026-06)

Putnička vozila za limo servis (**Putničko vozilo / 4+1–7+1**, baseline `vehicle_type_id = 1`) **nisu** dostupna za nove rezervacije po **Terminima** (gost i agencija). Ista kategorija ostaje dostupna za **Dnevnu naknadu**. Pravilo: `ReservationVehicleEligibilityService` (ID-jevi iz `vehicle_type_translations`, ne hardkodirani). Validacija: `CheckoutReservationRequest` + filtriranje u `ReservationBookingPageData`. Historijske rezervacije, admin i analitika nisu dirani.

### Dnevna naknada (`reservation_kind=daily_ticket`)

Na **`GET /panel/reservations`** agencija bira **Termini** (default) ili **Dnevna naknada** (radio ispod objašnjenja). Iznad radio dugmadi stalno su prikazana oba objašnjenja (Termini + Dnevna naknada) sa linkovima na mape (Benovo; Autoboka, Puč, Perast, Risan) — CG/EN preko `ui_translations` (`booking_kind_expl_*`).

**Dnevna naknada** je dostupna i agencijama (`/panel/reservations`) i gostima (`/guest/reserve`).

- Agencije mogu platiti karticom ili iz raspoloživog avansa.
- Gosti mogu platiti isključivo karticom.
- Za goste i agencije važe ista poslovna pravila: Dnevna naknada nema slotove, ne koristi `daily_parking_data` i ne podliježe kontroli kapaciteta.
- Putnička vozila (limo servis, 4+1 do 7+1) dostupna su samo za Dnevnu naknadu i nisu dostupna za Termini rezervacije.

- **Termini:** postojeći tok — obavezni arrival/departure slotovi, kapacitet preko `daily_parking_data`, duplicate check po slotu+tablici.
- **Dnevna naknada:** isti iznos po kategoriji vozila kao plaćena slot rezervacija; **bez** slotova, **bez** `daily_parking_data`, **bez** slot duplicate checka; isti plate+datum može imati i slot rezervaciju.
- Checkout: `POST /checkout` sa `auth_panel_booking=1` i `reservation_kind=daily_ticket` (kartica → `temp_data` + banka; avans → odmah `Reservation` kao za Termini, bez lock-a na slotovima).
- PDF i email: fiskalni/ne-fiskalni račun i potvrda prikazuju **Dnevna naknada**, datum važenja i lokacije Autoboka/Puč (bez termina).
- Predstojeće/realizovane: dnevna naknada je predstojeća cijeli dan važenja, realizovana od sljedećeg kalendarskog dana.
- Admin analitika (odvojeni brojači) — Phase 3B.

### Limo (informativno; QR workflow ukinut)

**Phase 2 (2026-06):** Agencijska stavka menija **Limo** (ranije „Limo QR”) vodi na informativnu stranicu. QR generisanje, skeniranje, evidentičar na `/limo/*` i OCR su **isključeni** po defaultu (`LIMO_QR_WORKFLOW_ENABLED=false`). Operativni mehanizam za dan je **dnevna naknada** kroz Rezervacije.

Ruta **`GET /panel/limo`** dostupna kada su ispunjena **oba** uslova:

- `ADVANCE_PAYMENTS_ENABLED=true` (config `features.advance_payments`)
- `LIMO_SERVICE_ENABLED=true` (config `features.limo_service`)

Ako je bilo koji uslov false, `/panel/limo` vraća **404**, a navigaciona stavka „Limo” može biti vidljiva ali **disabled** (bez linka) uz tooltip.

- **Informativna stranica:** odobrena mjesta ukrcaja (map linkovi), obaveza važeće **dnevne naknade**, kontrola od strane komunalne policije / ovlašćenih kontrolora (provjera tablice: Control panel `/control/dnevna-naknada`, v. `docs/control-panel.md`).
- **Deprecated QR rute** (`panel.limo.qr.*`): ostaju u kodu; middleware **`limo.qr_workflow`** vraća **404** dok je flag isključen. Rollback: `LIMO_QR_WORKFLOW_ENABLED=true`.
- **Jezik (cg/en):** `nav_limo`, `limo_info_*` u `ui_translations` / `UiText`.

Istorijski QR podaci, admin pregled događaja (`/admin/limo`) i servisi (fiskalizacija po starim pickup zapisima) **nisu** brisani. Detalji: **[limo-service.md](./limo-service.md)**.

---

## Besplatne rezervacije

Stranica: **`GET /panel/fzbr`** (`panel.fzbr.create`).

Tekstovi na vrhu forme (pravni uvod, info-blok, pomoć za upload) dolaze iz **`ui_translations`** grupe **`free_request`** (`fzbr_description`, `fzbr_instruction`, `documents_hint`, `documents_limit`, …), seedovano u **`UiTranslationsSeeder`**. Poslije izmjene teksta na produkciji ažurirati redove u bazi (npr. ponovnim seedovanjem ili ručnim SQL-om) — **nema promjene validacije, ruta, kontrolera niti zahtjeva za broj priloga u kodu**.

**Operativni proces (škole i slične ustanove):** škola ili ustanova prvo kontaktira **Sekretarijat za lokalne prihode, budžet i finansije** Opštine Kotor na **prihodi@kotor.me**; nakon odobrenja Sekretarijata, odobrenje se proslijedi agenciji/prevozniku koji podnosi zahtjev kroz **Besplatne rezervacije**. Uz formular se prilaže raspoloživa dokumentacija (npr. angažovanje prevoznika, odobrenje Sekretarijata); **nepotpuna dokumentacija se može poslati u prvim koracima**, a ostatak naknadno — **administrator ne odobrava besplatnu rezervaciju dok sva neophodna dokumentacija ne bude dostavljena i pregledana** (poslovno pravilo; implementacija odobrenja ostaje u admin toku).

Forma je podijeljena u dvije cjeline:

- **Cjelina 1 (datum + segmenti + vozila):**
  - Datum (jedan datum po zahtjevu) — **`dd/mm/yyyy`** + dugme kalendara (`<x-iso-date-input>`, backend `reservation_date` kao `Y-m-d`)
  - Segmenti (min 1, max 5): svaki segment je jedan par **Vrijeme dolaska** + **Vrijeme odlaska**
  - Vozila po segmentu (iz voznog parka ulogovane agencije; min 1; max = `system_config.available_parking_slots`; bez duplikata unutar segmenta)

- **Cjelina 2 (dokaz + saglasnost + slanje):**
  - Upload dokumentacije (slike/PDF, ukupno do 10 MB)
  - Saglasnost politike privatnosti
  - Dugme “Podnesi zahtjev”

Na vrhu stranice prikazuje se:
- **pravno / procesno objašnjenje** (ključ `free_request.fzbr_description`)
- **instrukcija korisniku** u odvojenom info-bloku (ključ `free_request.fzbr_instruction`)
- **pomoć za priloge** (`free_request.documents_hint` + `documents_limit`)

**Arhiva privatnih priloga (MEGA):** kada je zahtjev u terminalnom statusu (`fulfilled` / `rejected`), operativno se mogu arhivirati fajlovi sa privatnog diska na MEGA (`files:archive-private --source=fzbr`); detalji u **[external-file-archive.md](./external-file-archive.md)**. Kredencijali ostaju u `.env`; nema uploada iz browsera. **Admin pregled arhiviranih priloga:** na **`GET /admin/besplatne-rezervacije`** sekcija **„Pregled besplatnih rezervacija”** (filter odobreni/odbijeni + datum po `updated_at`) — link **„Dokument”** otvara privatnu preview rutu sa istim TTL / `files:cleanup-preview-cache` ponašanjem kao ostali admin preview-i iz arhive.

### Moji poslati zahtjevi (2026-06)

Na dnu **`GET /panel/fzbr`** prikazuje se sekcija **„Moji poslati zahtjevi”** — lista zahtjeva ulogovane agencije (`free_reservation_requests.user_id`).

- **Izvor:** `AgencyFzbrSubmittedRequestListService` (učitava zahtjeve agencije, filtrira vidljivost).
- **Kolone:** datum zahtjeva, vrijeme dolaska/odlaska (po segmentu), tablice, status, datum slanja (`created_at`, Podgorica).
- **Statusi (DB → UI):** `submitted` / `updated` → *Čeka se obrada*; `fulfilled` → *Odobreno*; `rejected` → *Odbijeno*. Nema polja za razlog odbijanja u bazi.
- **Vidljivost:** `submitted` i `updated` uvijek vidljivi. `rejected` vidljiv dok barem jedan segment zahtjeva još nije prošao (kraj pick-up termina na `reservation_date`, ista logika vremena kao `PanelReservationListService::isUpcoming`). `fulfilled` ostaje dok postoji barem jedna povezana besplatna rezervacija koja još nije realizovana; sakriva se kad su sve realizovane.
- **Veza zahtjev ↔ rezervacija:** migracija `reservations.free_reservation_request_id` (nullable FK); admin fulfill postavlja FK pri kreiranju `status=free` rezervacija. Stariji odobreni bez FK: servis pri učitavanju pronalazi odgovarajuće admin `free` redove (email agencije, tablice i termini iz segmenta zahtjeva) i upisuje FK; prikaz i sakrivanje `fulfilled` tada idu preko povezanih rezervacija. Ako nema pronađenih rezervacija, koriste se termini iz snapshot-a samog zahtjeva.
- **Prazno stanje:** *Nemate poslatih zahtjeva za besplatne rezervacije.*
- **i18n:** `panel.fzbr_submitted_*`, `panel.fzbr_request_status_*` u `UiTranslationsSeeder`.
- **Testovi:** `tests/Feature/Panel/FzbrSubmittedRequestsListTest.php`.

---

## Predstojeće vs realizovane (liste)

Servis: **`App\Services\Reservation\PanelReservationListService`**.

- **Predstojeće** (interno `upcoming`): datum rezervacije je **posle** danasnjeg dana **ili** (datum je **danas** i **`now` je pre kraja** pick-up termina: `ListOfTimeSlot::getEndTimeForDate` za taj datum). Za dnevnu naknadu: predstojeća dok je `reservation_date >= danas` (Podgorica).
- **Realizovane:** komplement (prošlost ili danas posle kraja odlaska; dnevna naknada od sljedećeg kalendarskog dana).
- **Promjena tablica** (`GET /panel/upcoming`) koristi isti upcoming upit, ali je poslovno usmjerena na promjenu tablice — v. sekcija ispod.

---

## Promjena tablica (`GET /panel/upcoming`, 2026-06)

Korisnički naziv menija: **Promjena tablica** (EN: **Plate change**). Ruta i klasa ostaju `panel.upcoming`. Stranica i dalje učitava predstojeće rezervacije (`PanelReservationListService::upcomingFor`), ali je poslovno usmjerena na promjenu tablice/vozila.

- **Dnevna naknada** (`reservation_kind=daily_ticket`): **nema** akcije promjene; prikazuje se poruka da promjena tablice nije dostupna. `PATCH /panel/reservations/{id}/vehicle` odbija daily fee (`UpdateReservationVehicleRequest` + `PanelReservationListService::allowsPlateChange`).
- **Termini** (`time_slots`): postojeća pravila (kategorija, konflikt termina, upcoming prozor).

## Promena vozila / tablice (samo predstojeće Termini)

- Dozvoljeno samo vozilo istog korisnika koje ispunjava oba uslova:
  - **kategorija**: **`vehicle_types.price` ≤** cene kategorije na rezervaciji (snapshot **`vehicle_type_id`** se **ne** menja)
  - **dostupnost**: kandidat vozilo nema konflikt za isti datum po pravilu:
    - konflikt je samo ako je **`drop_off_time_slot_id` isti** (drop=drop) **ili** je **`pick_up_time_slot_id` isti** (pick=pick)
    - nema konflikta za cross-match (**drop=pick** / **pick=drop**)
- **Plaćena** rezervacija: reset **`invoice_sent_at`** / **`email_sent`**, zatim **`SendInvoiceEmailJob`** (PDF se generiše u jobu, bez trajnog skladišta).
- **Besplatna** (`reservations.status = free`): samo **`license_plate`** / **`vehicle_id`**, bez fiskal/PDF mejla.

**UI (label tipa vozila):** u panelu i na korisničkim formama tip vozila se prikazuje kao **`Naziv (Opis) - Cena`** (opis je lokalizovan iz `vehicle_type_translations.description`, ako postoji). Formatiranje je centralizovano u `VehicleType::formatLabel($locale, 'EUR')`. Ako opis nedostaje, prikaz je `Naziv - Cena`.

Validacija: **`App\Http\Requests\UpdateReservationVehicleRequest`**.

---

## User tab (`/panel/user`)

- Jedna forma: **`resources/views/panel/partials/user-settings-form.blade.php`** (PATCH `/profile`).
- Polja: **name**, **lang** (`cg` / `en`), **email**, **password** (trenutna / nova / potvrda) sa istim eye partialima kao auth; **`country`** ostaje kao **hidden** sa trenutnom vrednošću.
  - Napomena: locale **`cg`** predstavlja zajedničku jezičku grupu (Crnogorski / Srpski / Hrvatski / Bošnjački) i to je **jedina** šifra koja se čuva u `users.lang` za taj skup varijanti.
- **Save** / **Cancel** aktivni samo kad ima izmena (Alpine); Cancel vraća inicijalne vrednosti.
- **Email:** nova adresa upisuje se u **`users.email`**, **`email_verified_at`** se postavlja na **`null`**, šalje se verification mail (minimalni „Opcija A“ tok bez odvojene tabele).
- Tekstovi: grupa **`user`** u **`UiTranslationsSeeder`** + **`UiText::t`**.

---

## Brisanje naloga

**`resources/views/profile/partials/delete-user-form.blade.php`** — stringovi iz **`ui_translations`** (grupa **`user`**, ključevi **`delete_account_*`**, **`cancel`**).

---

## Statistika (`/panel/statistics`)

**Unos datuma (Od / Do):** **`dd/mm/yyyy`** + kalendar (`<x-iso-date-input>`, skriveni `Y-m-d` u GET upitu). Rezervacije i dalje koriste mesečni kalendar (`partials/reservation-date-calendar`).

Servis **`App\Services\Reservation\PanelStatisticsService`**: koristi **`PanelReservationListService::realizedFor`** za istu definiciju „realized“, ali omogućava **filter po datumu** kroz query parametre:

- `date_from` i `date_to` (GET parametri) — zatvoren interval \([from,to]\) nad **`reservations.reservation_date`**
- Validacija: `App\Http\Requests\Panel\PanelStatisticsRequest` (`date_from`/`date_to` su `date`, uz pravilo `date_from <= date_to`)
- PDF export: `GET /panel/statistics/pdf` (`panel.statistics.pdf`) — koristi **identičan dataset** kao UI (isti request + bounds + `PanelStatisticsService::overview`), vraća PDF **inline**.
  - Za razliku od zvaničnih PDF dokumenata (račun/potvrde) koji su cg-only, ovaj PDF je **informativni export** i jezik prati agenciju (`users.lang`, cg/en; fallback cg).

### Bounds (source of truth: samo agencija)

Bounds se računaju **isključivo iz rezervacija ulogovane agencije** (nikad globalno), servis: `App\Services\Reservation\PanelStatisticsDateBounds`:

- **min date**: najranija *realizovana* `reservation_date` za tog korisnika (fallback: najranija `reservation_date`; fallback: danas)
- **max date**: danas + 90 dana

### Šta je obuhvaćeno filterom

Sve statistike poštuju:

- `WHERE user_id = auth()->id()`
- `reservation_date` u \([date_from, date_to]\)

KPI-ovi ostaju isti (samo uz filter):

- **Total paid** = suma `invoice_amount` za `reservations.status = paid` unutar realizovanih u opsegu
- **Broj posjeta** = broj realizovanih rezervacija u opsegu
- **Tabela vozila** = grupisano po `license_plate + vehicle_type_id` nad realizovanim u opsegu

## Veza: besplatne rezervacije

Checkout za besplatan termin **ne** ide na banku i **ne** pokreće fiskalizaciju; vidi kratku napomenu u **[success-payment-pipeline.md](./success-payment-pipeline.md)** i kod: **`CheckoutController`**, **`PaymentSuccessHandler`**, **`SendFreeReservationConfirmationJob`**, **`FreeReservationRules`**.

---

## Brisanje vozila iz voznog parka (workflow zamene)

Ako pokušate da obrišete vozilo koje je vezano za **predstojeće rezervacije**, sistem:

- prvo proverava da li za svaku spornu predstojeću rezervaciju postoji zamensko vozilo (ista agencija, **ista ili niža** kategorija, i bez konflikta po pravilima iznad)
- ako **ne postoji** zamena za bar jednu rezervaciju → brisanje se ne dozvoljava i prikazuje se poruka da je potrebno dodati drugo vozilo iste kategorije
- ako **postoji** zamena za sve → otvara se workflow **`GET /panel/vehicles/{vehicle}/remove`** gde za svaku spornu rezervaciju birate zamensko vozilo, pa se uz potvrdu:
  - u jednoj transakciji radi finalna provera kombinacije (da ista zamena ne napravi konflikt drop=drop/pick=pick između više rezervacija),
  - radi se “hard membership” provera da je svaki izabrani `vehicle_id` zaista član ponovo izračunate candidate liste za tu rezervaciju (sprečava ručno podmetanje parametara),
  - ažuriraju rezervacije na nova vozila,
  - i tek onda briše targetirano vozilo.

---

## Vozila – uklanjanje i promjena kategorije

Ovaj modul sprečava da se ista registarska tablica ponovo unese sa drugom kategorijom bez administrativne provjere dokumentacije.

### Statusi vozila: active vs removed

- **`active` vozila** se prikazuju agenciji u voznom parku i nude se u dropdown-ovima (Rezervacije, Promjena tablica, Besplatne rezervacije…).
- **`removed` vozila** se **ne prikazuju** agenciji u voznom parku i ne koriste se u dropdown-ovima.

### Uklanjanje vozila

- Ako vozilo **nije imalo nijednu rezervaciju** u istoriji:
  - može biti **fizički obrisano**.
- Ako je vozilo imalo **makar jednu rezervaciju** u istoriji:
  - **ne briše se fizički**,
  - dobija status **`removed`** (zadržava se kontinuitet istorije).

### Ponovni unos iste tablice

Ako agencija ponovo unese **istu registarsku tablicu**:

- Ako je izabrana **ista kategorija** kao ranije uklonjenom vozilu:
  - vozilo se **reaktivira** (`status = active`).
- Ako je izabrana **druga kategorija**:
  - direktan unos se **blokira**,
  - prikazuje se objašnjenje i forma za **upload dokumenta** (slika ili PDF),
  - agencija šalje **zahtjev za promjenu kategorije** koji ide administratoru na odobrenje.

Dok zahtjev ne bude odobren, vozilo se ne može koristiti sa novom kategorijom.

### Napomena (anti-abuse razlog)

Workflow postoji da bi se sprečilo da se ista registarska tablica ponovo unese sa drugom (npr. jeftinijom) kategorijom bez provjere dokumentacije.
