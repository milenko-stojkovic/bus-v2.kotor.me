# Agency panel (ulogovani korisnik, `/panel`)

**Poslednje ažuriranje:** 2026-05-28

Prefiks ruta: **`/panel`**, middleware **`auth`** + **`verified`**. Gornja navigacija: `resources/views/layouts/navigation.blade.php`.

---

## Rute (sažetak)

| Ruta | Naziv rute (izbor) | Opis |
|------|-------------------|------|
| `GET /panel/reservations` | `panel.reservations` | Nova rezervacija (`ReservationBookingPageData`) — **Termini** (slotovi) ili **Dnevna karta** (agency-only, bez slotova) |
| `GET /panel/upcoming` | `panel.upcoming` | Predstojeće rezervacije, promena vozila |
| `GET /panel/realized` | `panel.realized` | Realizovane, link na PDF u novom tabu |
| `GET /panel/vehicles` | `panel.vehicles` | Vozila |
| `GET /panel/fzbr` | `panel.fzbr.create` | FZBR (Formular za besplatnu rezervaciju) — podnošenje zahtjeva |
| `GET /panel/avans` | `panel.advance.index` | Avans: stanje, ledger i istorija topup pokušaja |
| `POST /panel/avans/topup` | `panel.advance.topup.store` | Pokretanje avansne uplate (kreira topup attempt + start payment) |
| `GET /panel/avans/return` | `panel.advance.return` | Povratak sa banke (status se čita iz baze) |
| `GET /panel/statistics` | `panel.statistics` | Statistika: ukupno plaćeno, broj realizovanih posjeta, tabela po tablicama |
| `GET /panel/limo` | `panel.limo.index` | Limo QR: lista aktivnih QR za današnji dan (samo kad je advance ON) |
| `POST /panel/limo/qr/generate` | `panel.limo.qr.generate` | Generisanje privremenog QR tokena (limit po agenciji/danu) |
| `GET /panel/limo/qr/{limoQrToken}` | `panel.limo.qr.show` | Prikaz QR (iz dekriptovanog tokena) |
| `GET /panel/limo/qr/{limoQrToken}/pdf` | `panel.limo.qr.pdf` | Preuzimanje QR kao PDF (štampa) — samo za današnje tokene |
| `GET /panel/user` | `panel.user` | Korisnik: ime, jezik, email, lozinka |
| `PATCH /profile` | `profile.update` | Čuva profil (uključujući lozinku ako je uneta) |
| `PATCH /panel/reservations/{id}/vehicle` | `panel.reservations.vehicle` | Zamena vozila na upcoming rezervaciji |
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
- **avansna uplata se NE fiskalizuje**

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

### Dnevna karta (`reservation_kind=daily_ticket`)

Na **`GET /panel/reservations`** agencija bira **Termini** (default) ili **Dnevna karta** (radio ispod objašnjenja). Iznad radio dugmadi stalno su prikazana oba objašnjenja (Termini + Dnevna karta) sa linkovima na mape (Benovo; Autoboka, Puč, Perast, Risan) — CG/EN preko `ui_translations` (`booking_kind_expl_*`). Gost (`/guest/reserve`) **nema** ovu opciju.

- **Termini:** postojeći tok — obavezni arrival/departure slotovi, kapacitet preko `daily_parking_data`, duplicate check po slotu+tablici.
- **Dnevna karta:** isti iznos po kategoriji vozila kao plaćena slot rezervacija; **bez** slotova, **bez** `daily_parking_data`, **bez** slot duplicate checka; isti plate+datum može imati i slot rezervaciju.
- Checkout: `POST /checkout` sa `auth_panel_booking=1` i `reservation_kind=daily_ticket` (kartica → `temp_data` + banka; avans → odmah `Reservation` kao za Termini, bez lock-a na slotovima).
- PDF i email: fiskalni/ne-fiskalni račun i potvrda prikazuju **Dnevna karta**, datum važenja i lokacije Autoboka/Puč (bez termina).
- Predstojeće/realizovane: daily ticket je predstojeći cijeli dan važenja, realizovan od sljedećeg kalendarskog dana.
- Admin analitika (odvojeni brojači) — Phase 3B.

### Limo QR (pick-up taksa)

Rute **`/panel/limo*`** vidljive i dostupne samo kada su ispunjena **oba** uslova:

- `ADVANCE_PAYMENTS_ENABLED=true` (config `features.advance_payments`)
- `LIMO_SERVICE_ENABLED=true` (config `features.limo_service`)

Ako je bilo koji uslov false, `/panel/limo*` vraća **404**, a navigaciona stavka “Limo” može biti vidljiva ali **disabled** (bez linka) uz tooltip.

- **Lista i generisanje:** `GET /panel/limo`, `POST /panel/limo/qr/generate`, **detalj QR:** `GET /panel/limo/qr/{limoQrToken}`.
- Na vrhu Limo QR stranice prikazuje se **info-blok** sa operativnim uputstvom: mjesto ukrcavanja (map link), način evidentiranja (skeniranje jednodnevnog QR-a), efekti skeniranja (skidanje avansa + fiskal + email) i osnovni uslovi da agencija može pružati limo uslugu.
- QR važi **samo za tekući kalendarski dan** (timezone projekta).
- **Maks. 20 generisanih „slotova”** po agenciji po danu (aktivni tokeni + već evidentirani Limo pickup-i tog dana).
- U bazi se čuvaju **`token_hash`** i **`encrypted_token`**; na ekranu se QR prikazuje iz dekriptovane vrijednosti.
- Dugme **„Preuzmi PDF”** preuzima jednostavan PDF za štampu (QR + agencija + datum). **Nema finansijskog efekta**; važi samo za **današnje** tokene (inače 404).
- **Finansijski efekat** (skidanje avansa, fiskal, email računa) nastupa tek kada **Limo evidenter** potvrdi pickup putem **`POST /limo/pickup/qr`** — samo generisanje QR-a ne troši avans.
- **Jezik (cg/en):** tekstovi u navigaciji, na stranicama Limo QR-a i u **ne-fiskalnom** PDF-u za štampu koriste grupu **`panel`** u `ui_translations` (ključevi `nav_limo`, `limo_*`, `limo_qr_pdf_*`, `limo_generate_error_*`), prema `users.lang` / `SetLocale`. **Fiskalni Limo račun** (`PaidInvoicePdfGenerator::renderLimoBinary`, šablon `pdf/paid-invoice`) i prateći email (**`SendLimoInvoiceEmailJob`**, `UiText` grupa `emails`) **uvijek su na `cg`**, nezavisno od `users.lang` (v. `limo-service.md`, test `LimoFiscalizationTest::test_limo_invoice_email_body_is_cg_when_agency_lang_is_en`).

Detalji modela: **[limo-service.md](./limo-service.md)**.

---

## FZBR (Formular za besplatnu rezervaciju)

Stranica: **`GET /panel/fzbr`** (`panel.fzbr.create`).

Tekstovi na vrhu forme (pravni uvod, info-blok, pomoć za upload) dolaze iz **`ui_translations`** grupe **`free_request`** (`fzbr_description`, `fzbr_instruction`, `documents_hint`, `documents_limit`, …), seedovano u **`UiTranslationsSeeder`**. Poslije izmjene teksta na produkciji ažurirati redove u bazi (npr. ponovnim seedovanjem ili ručnim SQL-om) — **nema promjene validacije, ruta, kontrolera niti zahtjeva za broj priloga u kodu**.

**Operativni proces (škole i slične ustanove):** škola ili ustanova prvo kontaktira **Sekretarijat za lokalne prihode, budžet i finansije** Opštine Kotor na **prihodi@kotor.me**; nakon odobrenja Sekretarijata, odobrenje se proslijedi agenciji/prevozniku koji podnosi FZBR. Uz formular se prilaže raspoloživa dokumentacija (npr. angažovanje prevoznika, odobrenje Sekretarijata); **nepotpuna dokumentacija se može poslati u prvim koracima**, a ostatak naknadno — **administrator ne odobrava besplatnu rezervaciju dok sva neophodna dokumentacija ne bude dostavljena i pregledana** (poslovno pravilo; implementacija odobrenja ostaje u admin toku).

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

**Arhiva privatnih priloga (MEGA):** kada je zahtjev u terminalnom statusu (`fulfilled` / `rejected`), operativno se mogu arhivirati fajlovi sa privatnog diska na MEGA (`files:archive-private --source=fzbr`); detalji u **[external-file-archive.md](./external-file-archive.md)**. Kredencijali ostaju u `.env`; nema uploada iz browsera. **Admin pregled arhiviranih priloga:** na **`GET /admin/besplatne-rezervacije`** sekcija **„Pregled besplatnih rezervacija po FZBR”** (filter odobreni/odbijeni + datum po `updated_at`) — link **„Dokument”** otvara privatnu preview rutu sa istim TTL / `files:cleanup-preview-cache` ponašanjem kao ostali admin preview-i iz arhive.

---

## Predstojeće vs realizovane

Servis: **`App\Services\Reservation\PanelReservationListService`**.

- **Upcoming:** datum rezervacije je **posle** danasnjeg dana **ili** (datum je **danas** i **`now` je pre kraja** pick-up termina: `ListOfTimeSlot::getEndTimeForDate` za taj datum). Ako kraj termina ne može da se parsira, tretira se kao upcoming.
- **Realized:** sve ostalo (prošlost ili danas posle kraja odlaska).

---

## Promena vozila (samo upcoming)

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

- **`active` vozila** se prikazuju agenciji u voznom parku i nude se u dropdown-ovima (Rezervacije, Upcoming, FZBR…).
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
