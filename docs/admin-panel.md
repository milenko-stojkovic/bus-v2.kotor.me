# Admin panel – funkcionalnosti

Specifikacija admin funkcionalnosti. Modeli: Reservation, TempData, DailyParkingData, ListOfTimeSlot, ReportEmail, system_config.

## Dva odvojena „admin“ toka (2026-04)

| Šta | URL prefiks | Auth | Namena |
|-----|-------------|------|--------|
| **Glavni admin panel** | `/admin` | Guard **`panel_admin`**, tabela **`admins`**, kolona **`admin_access=1`** (i **`control_access=0`**) | Dashboard **Upozorenja / Informacije** (`admin_alerts`, pregled nedostupnosti i blokada), navigacija (**Sistem status**, Blokiranje, Besplatne rezervacije, …). Login: **`GET /admin/login`**. |
| **Staff operativa** (rezervacije, late-success) | `/staff` | **`User`** + **`AdminMiddleware`** (uloga admin ili email u `admins`) | `ReservationListController`, `LateSuccessController` — v. `routes/web.php` imena **`staff.*`**. |

**Control panel** (šalter / dolasci): guard **`control`**, **`/control`** — v. **[control-panel.md](./control-panel.md)**. **`admin_access`** i **`control_access`** su međusobno isključivi; isti red u `admins` nikad ne drži oba = 1 (v. migracija + `Admin::booted`).

**Tabela `admin_alerts`:** operativna lista upozorenja (ne inbox); incident **SUCCESS posle `canceled`** upisuje se u **`admin_alerts`** preko **`AdminFiscalizationAlertService::notifyPaymentSuccessAfterCanceled`** (uz postojeći email).

### Sistemska arhiva — neuspjeli fajlovi (`GET /admin/sistemska-arhiva/neuspjeli`)

- **Namjena:** ručni pregled i ponovni pokušaj MEGA uploada za redove **`external_file_archives`** u statusu **`failed`** (npr. nakon privremenih grešaka ili operativnog MEGA problema).
- **Rute:** **`panel_admin.archive.failed`** (lista), **`panel_admin.archive.failed.retry`** (**POST** + CSRF, jedan red po zahtjevu).
- **Prikaz:** id, izvor (`source_table`, `source_id`, `context_type`), `original_local_path`, `generated_file_name`, skraćena greška, datumi, **da li lokalni fajl još postoji** na privatnom disku.
- **Ponovni pokušaj:** poziva **`ExternalFileArchiveService::retryFailedArchive`** — ažurira **isti** red (ne pravi novi `uploaded` duplikat za isti izvor); koristi isto **`generated_file_name`**; **ne** briše objekte na MEGA. Za **`archived_derivative`** + **`limo_plate_upload`** ponovo se priprema JPEG derivat iz postojećeg originala. Ako lokalni fajl nedostaje ili već postoji drugi **`uploaded`** red za isti (`source_table`, `source_id`, `source_column`), akcija se odbija (poruka u sesiji). Kredencijali MEGA se ne prikazuju; nema slobodnog unosa putanje.

**Operativna v1 alertiranja (2026-05):** ne zamenjuje pun monitoring — smanjena šuma, deduplikacija preko **`App\Services\AdminPanel\AdminAlertService::createOnce`** (`payload_json.dedupe_key`; postojeći zapisi i tipovi ostaju kompatibilni). Relevantni tipovi:
- **`free_reservation_request`** / **`guest_free_reservation_request`** — novi FZBR zahtjev (dedupe po zahtjevu); ako email operateru ne uspije nakon upisa u bazu, dodatni tip **`fzbr_request_unnotified`** (dedupe `…:unnotified`).
- **`queue_worker_down`** — kritično: samo kad **`QUEUE_CONNECTION=database`**, postoji **`jobs`** tabela, te **pending** poslovi (nerezervovani) čije je **`available_at`** starije od praga (default **5 min**). **Ne** na prvu provjeru: prvi put se samo upiše cache marker (`system_health:queue_stale:first_seen`) i log `payments` **`system_health_queue_stale_first_seen`**; `admin_alert` nastaje tek ako je isti „stale“ i dalje prisutan nakon prozora potvrde (default **2 min**) i nema otvorenog istog alerta (**`createOnce`**). Poruka navodi queue connection, broj/ starost, vrijeme prvog zapažanja, napomenu o **dvostrukoj** detekciji i da se **worker ne restartuje automatski** (v1). Podešavanja: **`config/queue.php`** → **`system_health`** (`SYSTEM_HEALTH_QUEUE_STALE_*`; default **TTL markera ~25 h** da preživi do sljedećeg dnevnog `alerts:system-health`). Za **`sync`** / ostale drivere — provjera se preskače (marker se briše).
- **`system_config_fake_production`** — samo u **`production`** (ili ručno `--assume-production` u testu): fake bank/fiskal (`BANK_DRIVER` / `FISCALIZATION_DRIVER`) ili `payment.fake_e2e_sync`.
- **`system_health_daily`** — najviše **jedan** zapis dnevno (dedupe `system_health_daily:YYYY-MM-DD`, datum **`Europe/Podgorica`**): skraćen pregled neuspelih jobova (24h), neuspelih MEGA arhiva, MEGA dijagnostike (ako su kredencijali podešeni), i „zaglaveljene“ **`post_fiscalization_data`** (>2h, ako tabela postoji). Komanda: **`alerts:system-health`**, scheduler **07:30** Podgorica — v. **`cron-commands.md`** / **`scheduled-tasks-overview.md`**. **MEGA privremene mrežne greške** pri uploadu / admin preview restore **ne** prave `admin_alerts` same po sebi (v. **`external-file-archive.md`** — retry u servisu); rollup i dalje može brojati redove u **`failed`** kad konačno ostanu neuspješni.
- **`guest_paid_lower_category_than_history`** — informativno nakon uspješnog kreiranja **guest** plaćene rezervacije: nova **`vehicle_types.price`** je **niža** od cijene kategorije na **najnovijoj starijoj** **`reservations`** plaćenoj rezervaciji (`status=paid`, ista normalizovana tablica; bez `temp_data`, free, pending, `vehicles`). Servis **`GuestPaidLowerCategoryAlertService`** (poziv iz **`PaymentSuccessHandler`** poslije transakcije); **ne** blokira checkout, plaćanje ni fiskalizaciju. Email preko **`AdminFiscalizationAlertService::notify`** (`PAYMENT_OPERATIONS_ALERT_EMAIL`); log **`guest_paid_lower_category_alert`**. Dedupe: **`createOnce`** ključ `guest_paid_lower_category:{reservation_id}`.

- **Operativni heartbeat (cache):** **`alerts:system-health`** i **`files:archive-private`** pišu u **Laravel Cache** metapodatke za **Sistem status** i operativni pregled (bez nove DB šeme). Ključevi i TTL (~30 dana): **`App\Support\OperationalHeartbeatCache`** i **`docs/cron-commands.md`**.

### Sistem status — `GET /admin/sistem-status` (`panel_admin.system-status`)

- **Namjena:** jednostavan **read-only** pregled operativnog stanja (metrike iz baze + heartbeat keš). **Bez** akcija, restarta workera, retry-a ili novih tabela.
- **Middleware / kontroler:** `auth:panel_admin` + `admin.panel`; **`App\Http\Controllers\AdminPanel\SystemStatusController`**, servis **`App\Services\AdminPanel\AdminSystemStatusService`**.
- **Podaci:** queue (driver; za `database` — pending, stale po istim pragovima kao `alerts:system-health`, starost najstarijeg, marker `system_health:queue_stale:first_seen` ako postoji); MEGA (`mega:last_diagnose_*` iz keša, ili tekst *nije još provjereno*); privatna arhiva (heartbeat + broj `failed` u `external_file_archives`, link na neuspjele); fiskalizacija (nerešeni `post_fiscalization_data` stariji od 2h); `failed_jobs` (24h); otvoreni kritični `admin_alerts` (naslov, vrijeme, link na Upozorenja); `system_health:last_*` heartbeat.
- **Navigacija:** stavka **Sistem status** u layoutu admin panela.
- **Testovi:** `tests/Feature/AdminPanel/AdminSystemStatusTest.php`.

### Dashboard `GET /admin` (`panel_admin.dashboard`)

Kontroler: **`WarningsController::index`**. Stranica ima tri bloka: **Upozorenja**, **Nedostupni dani i termini**, **Blokirani dani i termini** (meta refresh 300 s za operativni pregled).

**Grafikon kapaciteta (danas + sutra)**

- Isti dataset kao na Control dashboardu: **`DailyCapacityChartService::todayAndTomorrow()`** + partial `daily-capacity-chart`.
- Stubci: **`daily_parking_data`** (`reserved` + `pending` po terminu); kapacitet: **`system_config.available_parking_slots`**.
- **Ukupno rezervacija** za dan: broj **`reservations`** (`time_slots`, `paid`/`free`) — v. **`docs/control-panel.md`**.

**Dnevne naknade — ukupan broj (danas + sutra)**

- **`DailyFeeReservationSummaryService::todayAndTomorrow()`** + partial `daily-fee-reservation-summary`.
- Broj **plaćenih** rezervacija (`reservation_kind=daily_ticket`, `status=paid`) za tekući i sutrašnji dan (`Europe/Podgorica`).

**Blokirani dani i termini**

- Izvor: **`daily_parking_data.is_blocked = 1`**.
- Opseg: samo datumi koji postoje u tabeli i **`date >= danas`** (nema proizvoljnog „+90 dana“ skeniranja praznih dana).
- Grupisanje po danu; uzastopni blokirani slotovi (po rastućem `time_slot_id`, uzastopni celi brojevi) spajaju se u jedan raspon: **početak prvog termina – kraj poslednjeg** (parsiranje stringa `time_slot`, v. **`DaySlotRangeSummaryBuilder`**).
- Ako je blokiran **ceo katalog** slotova za taj dan → prikaz datuma sa oznakom **„— blokiran”** (bez liste intervala).
- Ne zavisi od free/plaćeno niti od broja rezervacija — opisuje samo administrativnu blok zonu. Link **Deblokiraj** vodi na **`panel_admin.blocking.day`**.

**Nedostupni dani i termini**

- Izvor istine: da li se termin **može kupiti** u smislu iste provere kao pri zaključavanju u checkout-u (**`CheckoutController::store`**, transakcija + `lockForUpdate`): za svaki slot iz **`list_of_time_slots`** nedostupan je ako **nema** reda u `daily_parking_data` za taj datum, ili je **`is_blocked`**, ili je **`availableCapacity() < 1`** (uključuje **`pending`**).
- Opseg datuma: kao i gore — **distinct datumi iz `daily_parking_data` sa `date >= danas`**.
- **Uključuje i blokirane** termine (oni su istovremeno i u sekciji Blokirani); ovo je zbir „trenutno se ne može kupiti“.
- Grupisanje i spajanje raspona: isti **`DaySlotRangeSummaryBuilder`**. Ceo dan nedostupan (svi slotovi kataloga) → **„— nedostupan”**.

**Zajednička logika spajanja**

- Klasa **`App\Services\AdminPanel\Blocking\DaySlotRangeSummaryBuilder`**: ulaz = pun skup slotova (redosled kao u `allSlots()`) + lista ID-jeva „označenih“ slotova; izlaz = **`is_full_day`** (ceo katalog pokriven) ili lista stringova raspona.

**Servis:** **`BlockingService::blockedDaySummaries()`**, **`unavailableForPurchaseDaySummaries()`**. Lista na stranici **Blokiranje** (`/admin/blokiranje`) koristi isti **`blockedDaySummaries()`**.

**Testovi:** `tests/Feature/AdminPanel/AdminWarningsDashboardTest.php`, `tests/Unit/DaySlotRangeSummaryBuilderTest.php`.

### Limo događaji (read-only) — `GET /admin/limo` (`admin.limo.index`)

- **Napomena (2026-06):** QR workflow za agencije i evidentičara je ukinut; ova stranica ostaje za **istorijske** pickup/incident zapise. Novi operativni mehanizam za agencije je **dnevna naknada** kroz Rezervacije; provjera na terenu: **Control panel** → `GET /control/dnevna-naknada` (v. `docs/control-panel.md`).
- **Middleware:** `auth:panel_admin` + `admin.panel` (kao ostali moduli glavnog admin panela; **nije** `limo.access` — pristup imaju samo `admins` sa `admin_access=1`, ne i „samo Limo” nalozi).
- **Kontroler:** `App\Http\Controllers\Admin\LimoController::index`; pogled `resources/views/admin/limo/index.blade.php`.
- **Vrsta pregleda (GET `type`):** **`pickup`** (podrazumijevano) ili **`incident`**. Radio dugmad u filter formi; ostaju **`date_from`** / **`date_to`** (zatvoren interval **`[from, to]`** po **`occurred_at`**, **`Europe/Podgorica`**).
- **Pickup (`type=pickup`):** isključivo **`limo_pickup_events`** (bez rezervacija iz `reservations`); redosled **`occurred_at DESC`**; agencija (snapshot), tablica, iznos, izvor (QR / tablica), status fiskalizacije, JIR kad postoji.
- **Incident (`type=incident`):** lista **`limo_incidents`** u istom datumu po **`occurred_at`**; kolone u tabeli prate polja modela (npr. `license_plate_snapshot`, `visible_agency_name`, `agency_name_snapshot`, `note`, `recorded_by_limo_admin_id`, GPS, linkovi na slike).
- **Slika tablice (pickup, samo izvor „tablica”):** link **„Slika tablice”** otvara **`GET /admin/limo/pickups/{limoPickupEvent}/plate-photo-preview`** (`admin.limo.pickups.plate-photo-preview`) — `LimoPickupPlatePhotoPreviewController`; servira fajl sa privatnog diska samo ako putanja počinje sa **`limo_pickup_evidence/`** (potvrda tablice) ili **`limo_pickup_photos/`** (legacy); ako je fajl arhiviran na MEGA i obrisan lokalno, **privremeno** se ponovo preuzima (bez trajnog `local_deleted_at = null`); detalji u **[external-file-archive.md](./external-file-archive.md)**.
- **Slike incidenta:** **`GET /admin/limo/incidents/{limoIncident}/plate-photo-preview`** i **`GET /admin/limo/incidents/{limoIncident}/branding-photo-preview`** (`LimoIncidentPhotoPreviewController`) — dozvoljene su samo relativne putanje ispod **`limo_incidents/`**; isti princip MEGA privremenog restore-a i **`files:cleanup-preview-cache`** kao za pickup (vidi **`external_file_archives`**: `source_table=limo_incidents`, `source_column` = `plate_photo_path` / `branding_photo_path`).
- **Namjerno nije uključeno:** izmjene, brisanje, retry fiskala, ponovno slanje emaila, export.
- **Testovi:** `tests/Feature/Admin/LimoAdminIndexTest.php`, `tests/Feature/Admin/LimoPlatePhotoPreviewTest.php`, `tests/Feature/Admin/LimoIncidentPhotoPreviewTest.php`.

---

**Implementirano (van opšte specifikacije ispod):** pregled i akcije za **`late_manual_review`** / povezane statuse — `App\Http\Controllers\Admin\LateSuccessController` (lista, detalj, **force create** rezervacije, **reject**). Rute su pod prefiksom **`/staff`**, middleware **`admin`** (v. `routes/web.php`).

---

## 1. Rezervacije

| Funkcionalnost | Opis | Modeli / tabele |
|----------------|------|------------------|
| **Pregled rezervacija** | Lista svih rezervacija (filteri: datum, status, guest/user). | `Reservation` |
| **Naknadna rezervacija** | Kreiranje rezervacije od strane admina (npr. telefonski / na šalteru). | `Reservation`, `DailyParkingData` (kapacitet) |
| **Besplatna rezervacija** | Kreiranje rezervacije sa statusom `free` (bez plaćanja). | `Reservation.status` = `'free'` |
| **Pristup i izmena napravljene rezervacije** | Pogled i izmena rezervacije u statusu `late_success` (već upisane). | `Reservation`, `TempData` (ako još postoji) |
| **Izmene termina** | Izmena `drop_off_time_slot_id`, `pick_up_time_slot_id`, `reservation_date` na postojećoj rezervaciji. | `Reservation`, validacija preko `DailyParkingData` |
| **Promena statusa rezervacija** | Menjanje `status` (npr. paid / free). | `Reservation.status` |

### 1.1 Besplatne rezervacije (admin panel) — implementirano

| Ruta | Namena |
|------|--------|
| `GET /admin/besplatne-rezervacije` | `panel_admin.free-reservations` — forma (korak kao gost + polja za snapshot). |
| `POST /admin/besplatne-rezervacije` | `panel_admin.free-reservations.store` — kreiranje (isti Termini duplicate check kao checkout). |
| `POST /admin/besplatne-rezervacije/zahtjevi/{freeReservationRequest}/fulfill` | `panel_admin.free-reservation-requests.fulfill` — **Napravi besplatnu/e rezervaciju/e** (idempotentno). |
| `PUT /admin/besplatne-rezervacije/zahtjevi/{freeReservationRequest}` | `panel_admin.free-reservation-requests.update` — izmjena datuma/termina zahtjeva. |
| `DELETE /admin/besplatne-rezervacije/zahtjevi/{freeReservationRequest}` | `panel_admin.free-reservation-requests.reject` — odbijanje zahtjeva. |
| `GET /admin/besplatne-rezervacije/zahtjevi/{freeReservationRequest}/attachments/{attachment}/preview` | `panel_admin.free-reservation-requests.attachments.preview` — pregled priloga aktivnog zahtjeva. |
| `GET /admin/besplatne-rezervacije/fzbr/attachments/{attachment}/preview` | `panel_admin.fzbr-attachments.preview` — pregled priloga za **fulfilled/rejected** FZBR (lokalno ili MEGA preview). |

- **Kontroler:** `App\Http\Controllers\AdminPanel\FreeReservationController`; **validacija:** `AdminFreeReservationRequest`.
- **Pristigli zahtjevi za besplatne rezervacije:** na dnu iste strane prikazuje se lista aktivnih zahtjeva iz **agency panela** (`/panel/fzbr`):
  - izvor istine: `free_reservation_requests` → `free_reservation_request_segments` → `free_reservation_request_vehicles` (+ `free_reservation_request_attachments`)
  - prikazuju se samo statusi: **`submitted`**, **`updated`** (ne prikazuje `fulfilled`/`rejected`)
  - sortiranje: `created_at DESC`
  - eager loading (bez N+1): `with(['segments.dropOffTimeSlot','segments.pickUpTimeSlot','segments.vehicles.vehicleType.translations','attachments'])`
  - **Dokumenta (private/local storage):** prilozi su u `free_reservation_request_attachments` i prikazuju se kao lista sa linkom za **preview** (admin-only ruta streamuje fajl inline); ista logika **`ExternalFileArchiveService::ensureLocalPreviewForSource`** kao ispod kad je fajl arhiviran na MEGA (`stored_path` ispod **`free-reservation-requests/`**, vidi `FzbrAttachmentPreviewPath`).
- **Pregled besplatnih rezervacija (terminalni statusi):** ispod aktivne liste; GET parametri **`fzbr_review`**: **`approved`** (mapira na `status=fulfilled`, podrazumijevano) ili **`rejected`** (`status=rejected`); **`fzbr_date_from`** / **`fzbr_date_to`** — zatvoren interval po **`updated_at`** (Europe/Podgorica). Tabela: `id`, `created_at`, `updated_at`, `status`, agencija/ustanova (`user` + `institution_*`), email, `reservation_date`, slotovi (iz prvog segmenta ili legacy kolona), tablice vozila, linkovi **„Dokument”** na **`GET /admin/besplatne-rezervacije/fzbr/attachments/{freeReservationRequestAttachment}/preview`** (`panel_admin.fzbr-attachments.preview`) — samo za zahtjeve **`fulfilled`/`rejected`**; privremeni MEGA restore + TTL čišćenje kao u **[external-file-archive.md](./external-file-archive.md)**.
- **Retention:** posle **fulfill** / **reject** zahtjev se **ne briše**. Samo se setuje status (`fulfilled`/`rejected`) i uklanja se upozorenje (pointer).
- **Fulfill (idempotentno):** `FreeReservationRequestFulfillmentService` + `FreeReservationRequestReservationMatcher`.
  - Po vozilu/segmentu: kreira `status=free` rezervacije (`created_by_admin=true`) sa **`free_reservation_request_id`** = ID zahtjeva, ili **povezuje** već postojeću odgovarajuću besplatnu rezervaciju (isti email/ustanova, datum segmenta, slotovi, normalizovana tablica, `invoice_amount=0.00`).
  - **Transakcija:** kreiranje/povezivanje + `status=fulfilled` + uklanjanje `admin_alerts` pointera — **u istoj DB transakciji**; email sa PDF prilozima **posle** commit-a.
  - **Ponovni klik** na isti zahtjev ne kreira duple rezervacije; ako email nije poslat, pokušava ponovo.
  - **Greške:** više od jednog kandidata za istu tablicu → `AmbiguousFreeReservationLinkException`; rezervacija koja odgovara **drugom aktivnom** zahtjevu → `FreeReservationLinkedToOtherRequestException`; Termini duplicate i dalje preko `DuplicateReservationAttemptService` (sa `exceptReservationIds` za redove istog fulfill ciklusa).
  - **Pogrešan FK** (npr. `free_reservation_request_id` pokazuje na drugi zahtjev, ali podaci rezervacije odgovaraju ovom): dozvoljen je **relink** ako ta rezervacija ne pripada liniji drugog zahtjeva.
  - **Ručni repair (produkcija):** `php artisan free-reservation-requests:repair-fulfilled` (`--dry-run`, opciono `--id=`) — skenira `submitted`/`updated` zahtjeve sa već postojećim odgovarajućim rezervacijama i završava ih sigurno (isti matcher).
- **Podaci stranice / slotovi:** `ReservationBookingPageData::forAdminPanel()` — isti `buildSlotPayload` / `FreeReservationRules` kao gost; UI jezik fiksno **cg** (`App::setLocale('cg')` u kontroleru).
- **Bez `temp_data`:** `App\Services\AdminPanel\FreeReservation\AdminDirectFreeReservationService` u transakciji zaključava `daily_parking_data` po `whereDate` + `time_slot_id`, proverava `!is_blocked` i `availableCapacity() >= 1`, kreira `Reservation` (`status=free`, `created_by_admin=true`, `user_id=null`, `preferred_locale=cg`, `invoice_amount` preko `ReservationInvoiceAmount`), **increment `reserved`** po jedinstvenom slotu (isti ID jednom), zatim `SendFreeReservationConfirmationJob`.
- **Worklist:** `BlockZoneWorklistService::onReservationCreated($reservation, null)` ako postoji red po istom `merchant_transaction_id` (retko za novi UUID).
- **Konflikt termina:** `AdminFreeReservationSlotsUnavailableException` → redirect na istu stranu sa query parametrima za `name`, `country`, `license_plate`, `email`, `vehicle_type_id` (bez datuma/termina) + flash `error`.
- **Uspeh:** redirect na praznu stranu + flash `status`; forma bez starog unosa.

**`created_by_admin`:** u ovom toku uvek `true`; ostali tokovi i dalje `false` (v. §2 ispod).

**UI (label tipa vozila):** u dropdown-u za tip vozila prikazuje se lokalizovani naziv + opis + cena kao **`Naziv (Opis) - Cena`**. Opis dolazi iz `vehicle_type_translations.description` (fallback: bez opisa ako je NULL/prazan), a formatiranje je centralizovano u `VehicleType::formatLabel($locale, 'EUR')`.

**Tip rezervacije vs `merchant_transaction_id`:** `merchant_transaction_id` je korelacioni / idempotency ključ (v. **[project-conventions.md](./project-conventions.md)** §5); **ne** određuje da li je rezervacija plaćena, besplatna, gost, agencija ili admin-free — za to služe **`status`** i **`created_by_admin`**.

### 1.2 Pretraga i izmena rezervacija (admin panel) — implementirano

| Ruta | Namena |
|------|--------|
| `GET /admin/rezervacije` | `panel_admin.reservations` — pretraga nad tabelom **`reservations`** (bez `temp_data`). |
| `GET /admin/rezervacije/{reservation}/uredi` | `panel_admin.reservations.edit` — izmena samo **nerealizovanih** rezervacija (`PanelReservationListService::isRealized`); u listi akcije **PDF** + **Izmeni**. |
| `PUT /admin/rezervacije/{reservation}` | `panel_admin.reservations.update` — **termine** (`time_slots`): transakcija, `lockForUpdate` na `daily_parking_data`, **`BlockReservationAdjustmentValidator`**, izmene `reserved`; **dnevna naknada**: samo sigurna polja, bez `daily_parking_data`. Reset `invoice_sent_at` / `email_sent`, dispatch email job. |
| `GET /admin/rezervacije/{reservation}/pdf` | `panel_admin.reservations.pdf` — PDF računa (paid) ili potvrde (free) preko postojećih generatora. |

- **Kontroler:** `App\Http\Controllers\AdminPanel\ReservationController`.
- **Pretraga:** `AdminReservationSearchService` — svi kriterijumi su **AND** između popunjenih polja; polje **MTID** traži **tačno poklapanje** — rezervacije sa **`merchant_transaction_id` = NULL** (ako postoje) ovim kriterijumom se ne nalaze.
- **Vrsta rezervacije (filter):** **Sve** (podrazumijevano), **Termini** (`reservation_kind = time_slots`), **Dnevna naknada** (`daily_ticket`); kombinuje se sa ostalim kriterijumima (AND).
- **Limo putnička vozila (4+1–7+1):** admin pretraga/uredi/PDF/analitika **ne mijenjaju** prikaz historijskih rezervacija sa tim kategorijama; nova ograničenja važe samo za **booking** (Termini isključuju tip; dnevna naknada u agenciji ga zadržava) — v. `docs/agency-panel.md`.
- **Heuristika imena/emaila:** `AdminReservationSearchHeuristic` — jednostavne LIKE varijante (jedno izostavljeno slovo, zamena dva susedna; za ime normalizacija **doo** / **d.o.o.**).
- **Tablica u pretrazi:** polje `license_plate` normalizuje unos na **A–Z0–9** (`oninput` uppercase u formi).
- **Povratak sa edit strane:** query parametar **`rq`** čuva enkodiran prethodni query string pretrage; **`Odkaži`** i uspešan **`PUT`** vode na `GET /admin/rezervacije?{rq}`.
- **Izmena termina po tipu:** `AdminReservationSlotRules` — **paid** i **free + `created_by_admin`** mogu na bilo koje validne termine; **free bez admin kreacije** samo u besplatnom prozoru (`FreeReservationRules::isFreeReservation`, npr. 1/41). **Paid** ostaje paid i pri premještaju u free termine; **`invoice_amount` se ne preračunava**. Status, MTID, `reservation_kind` i fiskalno stanje se **ne** mijenjaju. **Termini duplicate check** pri izmjeni datuma/slotova/tablice (`DuplicateReservationAttemptService`, isključuje trenutnu rezervaciju).
- **Posle prošlog dolaska (isti dan):** `AdminReservationEditPolicy::isPickUpOnlyMode` — dozvoljena je izmjena samo pick-up termina i ostalih polja (ne datuma ni drop-off).
- **Dnevna naknada:** forma bez termina; `AdminDailyTicketUpdateService` — datum, ime, država, tablica, tip vozila, email; bez konverzije vrste i bez kapaciteta.
- **Kategorija vozila:** u edit formi samo tipovi sa **`price` ≤** cene trenutnog tipa (`vehicle_types` po postojećem poretku cene).
- **Kalendar:** granice pretrage — `AdminReservationDateBounds` (min = najraniji datum u `reservations`, max = danas + 90 dana); edit — danas … danas + 90.
- **Email i slanje dokumenta:** račun (paid) i potvrda (free) uvek se šalju na **`reservations.email`** — to je snapshot na rezervaciji, isti izvor kao u PDF-u. **Admin** ima pravo da menja taj snapshot (uključujući email) u toku izmene rezervacije. Posle uspješne izmene: **`SendAdminUpdatedReservationDocumentJob`** regeneriše PDF iz trenutnih podataka rezervacije i šalje ažurirani prilog (predmet/tijelo `paid_invoice_updated_*` / `free_reservation_updated_*` u **`ui_translations`**). **Ne mijenjaju se** MTID, status, `invoice_amount`, JIR/IKOF ni fiskalni zapisi. **`users.email`** se ne koristi kao primalac.
- **Lista rezultata:** **PDF** + **Izmeni** (aktivan link dok rezervacija nije realizovana po `AdminReservationEditPolicy` / `PanelReservationListService`, vremenska zona **Europe/Podgorica**); realizovane prikazuju sivi „Izmeni” (onemogućen).

**Bez izmena na `temp_data`:** ovaj modul ne čita i ne piše `temp_data`.

**Testovi:** `tests/Feature/AdminPanel/AdminPanelReservationTest.php`, `tests/Feature/AdminPanel/AdminReservationEditHardeningTest.php`, `tests/Feature/AdminPanel/AdminReservationKindFilterTest.php`.

---

## 2. Blokiranje termina i dana

| Funkcionalnost | Opis | Modeli / tabele |
|----------------|------|------------------|
| **Blokiranje/deblokiranje dana** | Admin blokira dan/termine bez menjanja `capacity/reserved/pending`. Blokada sprečava novu prodaju preko `daily_parking_data.is_blocked`. | `DailyParkingData.is_blocked` + `block_zone_worklist` |
| **Rezervacije u blok zoni (worklist)** | Slotovi sa postojećim `reserved>0` ili `pending>0` ne postaju odmah blokirani; ulaze u listu za ručno prilagođavanje. | `block_zone_worklist` |

Napomena: blokiranje je **odvojeno** od kapaciteta. `availableCapacity()` i `pending/reserved` semantika ostaju iste; UI/checkout samo dodatno tretira `is_blocked=1` kao nedostupno.

**UI (jasno razdvajanje Blokiraj / Deblokiraj):** na **`GET /admin/blokiranje`** u sekciji **Blokiraj** mogu se čekirati samo termini koji **nisu** već blokirani (već blokirani su prikazani kao informacija, bez `slot_ids[]`). Na **`GET /admin/blokiranje/dan/{date}`** (Deblokiraj) mogu se birati samo termini koji **jesu** blokirani; neblokirani su onemogućeni. Opcija **„Blokiraj ceo dan“** i dalje šalje kompletan skup slot ID-jeva; **`BlockingService::applyBlock`** i ranije preskače redove koji su već `is_blocked`.

Rute (admin panel):
- `GET /admin/blokiranje` (`panel_admin.blocking`)
- `POST /admin/blokiranje` (`panel_admin.blocking.apply`)
- `GET /admin/blokiranje/dan/{date}` (`panel_admin.blocking.day`)
- `POST /admin/blokiranje/dan/apply` (`panel_admin.blocking.unblock.apply`)
- `GET|POST /admin/blokiranje/worklist/{row}/prilagodi` (prilagođavanje rezervacije)

**`reservations.created_by_admin`:** boolean, default `false`. **`true`** samo za admin panel **Besplatne rezervacije** (`AdminDirectFreeReservationService`). Ostali tokovi eksplicitno postavljaju `false`. **Migracija:** `2026_04_11_120000_add_created_by_admin_to_reservations_table.php`.

**Prilagođavanje u blok zoni:** UI datum koristi prefiltar dana (minimum dva teorijski slobodna mesta tog dana — **nije** konačna garancija). Odlučujuća provera novih slotova (`!is_blocked`, `pending=0`, kapacitet; isti slot ID jednom) radi se **posle** `lockForUpdate` na relevantnim `daily_parking_data` redovima (`BlockingController` + `BlockReservationAdjustmentValidator`). Ako finalna validacija padne, nema delimičnih izmena. Posle uspešnog `Primeni` (blok/deblok) ili prilagođavanja redirect nosi `_fresh=timestamp` radi osvežavanja prikaza (bez auto-refresh tokom rada).

**Upit po datumu:** u modulu blokiranja/prilagođavanja, učitavanje i `lockForUpdate` nad `daily_parking_data` koristi **`whereDate('date', …)`** (ne striktno `where('date', …)`), da se datum uvek poklapa sa vrednošću u bazi i na SQLite-u.

**Testovi (izbor):** `tests/Feature/AdminPanel/AdminPanelAuthTest.php` (guard `panel_admin` vs `web`, 403, logout). `tests/Feature/AdminPanel/BlockReservationHardeningTest.php` (default kolone, post-lock odbijanje, blokiran novi slot bez delimičnih izmena, uspešan adjust + `_fresh`, deblok `_fresh`). `tests/Feature/AdminPanel/AdminWarningsDashboardTest.php` (dashboard nedostupni/blokirani). `tests/Feature/AdminPanel/AdminPanelFreeReservationTest.php` (besplatne rezervacije). `tests/Feature/AdminPanel/AdminPanelReservationTest.php` (admin pretraga/izmena rezervacija, §1.2).

---

## 3. Temp data i statusi

| Funkcionalnost | Opis | Modeli / tabele |
|----------------|------|------------------|
| **Promena statusa temp_data** | Izmena `status` (npr. `pending`, `canceled`, `expired`, `late_success`, `late_manual_review`, …). U bazi **nema** `failed` — bankovni neuspjeh = **`canceled`**. | `TempData.status` (`TempData::STATUS_*`) |
| **Promena statusa reservations** | Izmena `Reservation->status` (paid / free). | `Reservation.status` |

---

## 4. E-mailovi i izveštaji

| Funkcionalnost | Opis | Modeli / tabele |
|----------------|------|------------------|
| **Ažuriranje e-mailova za izveštaje** | CRUD za adrese na koje se šalju obaveštenja/izveštaji. | `ReportEmail` (tabela `report_emails`, kolona **`purpose`** ENUM: **`report`** za zakazane PDF izvještaje, **`limo_incidents`** za Limo incidente) |

**Račun / potvrda rezervacije (korisnik):** primalac = **`reservations.email`** (v. §1.2 — snapshot; admin može menjati).

---

## 4.1 Izvještaji (admin panel) — implementirano

- **Nema HTML preview-a**: stranica je samo dvokoračni izbor + PDF export u novom tabu.
- **Datum bounds (svi pickeri)**: min/max se računaju iz `reservations.created_at` (samo datum deo).
- **PDF**: uvek na `cg`, generiše se i kad nema podataka (nule/prazni redovi).
- **Zakazano slanje PDF izvještaja emailom (scheduler)**: komanda `reports:send-scheduled {daily|monthly|yearly}` šalje izvještaje svim primaocima iz `report_emails` gdje je **`purpose = report`** (jedan email po primaocu). Idempotency preko `scheduled_report_deliveries`. U slučaju greške (generisanje PDF ili slanje) kreira se `admin_alerts` zapis koji se uklanja ručno.

| Ruta | Namena |
|------|--------|
| `GET /admin/izvestaji` | `panel_admin.reports` — izbor “Kada” + “Kakav”, zatim izbor opsega i dugmad `PDF` / `Odkaži`. |
| `GET /admin/izvestaji/pdf` | `panel_admin.reports.pdf` — PDF export (inline) za izabrani tip i opseg. |

- **Kontroler:** `App\Http\Controllers\AdminPanel\ReportsController`.
- **Validacija:** `App\Http\Requests\AdminPanel\AdminPanelReportPdfRequest`.
- **Bounds:** `App\Services\AdminPanel\Reports\AdminReportsCreatedAtBounds`.
- **Agregacija:** `App\Services\AdminPanel\Reports\AdminReportsService`.
- **PDF:** `App\Services\Pdf\AdminReportsPdfGenerator` koristi view `pdf.admin-report`.

**Po uplati:** samo `paid` rezervacije, opseg po `reservations.created_at` (datum), `Ukupan prihod = sum(invoice_amount)`, `Broj transakcija = count(paid)`.

**Po realizaciji:** realizovane rezervacije po važećoj sistemskoj definiciji (istekao `Vrijeme odlaska`), opseg po `reservations.reservation_date` (jer realizacija je u okviru istog dana), `Ukupan prihod` se sabira samo za `paid`, a `Broj realizovanih` broji sve realizovane (paid + free).

**Po tipu vozila:** koristi realizovane rezervacije (bez obzira na status), opseg po `reservation_date`, prikaz 4 fiksna reda + `Ukupno` (naziv na `cg`: name + (description), bez cijene). I bez podataka redovi ostaju sa nulama.

**Po tipu rezervacije (`by_reservation_type`):** samo `paid` rezervacije, opseg po `reservations.reservation_date`. Broj vozila = broj rezervacija (redova). Prihod = `sum(invoice_amount)`. Redovi: **Termini** (`reservation_kind = time_slots`), **Dnevna naknada — Limo** i **Dnevna naknada — Autobusi** (`daily_ticket`, Limo = `vehicle_type_id` iz `ReservationVehicleEligibilityService::controlDailyFeeListVehicleTypeIds()` — putničko 4+1–7+1 + mini bus 8+1; Autobusi = ostale kategorije), podzbroj **Dnevna naknada ukupno**, **Ukupno**. PDF naslov/podnaslov: *Prihodi po tipu rezervacije*.

### 4.1.1 Izvještaj: Obaveze po avansima (snapshot) — implementirano (feature-flag)

**Svrha:** formalni snapshot izvještaj “stanje obaveza po avansima” na izabrani dan (npr. kraj fiskalne godine).

**Feature flag:** `config('features.advance_payments')` (ako je OFF → opcija nije vidljiva + endpoint vraća 404).

**Kakav:** `advance_obligations` (dozvoljen samo `when=daily`)

**Računanje (source-of-truth):** isključivo `agency_advance_transactions` (ledger), filter `created_at <= endOfDay(date)`:
- uplaćeno: SUM(type=topup)
- iskorišćeno: ABS(SUM(type=usage))
- korekcije: SUM(type=correction)
- preostalo/obaveza: SUM(svih amount)

PDF naslov: `Izvještaj o obavezama po osnovu avansnih uplata na dan DD.MM.YYYY.`

## 5. Sistemska konfiguracija

| Funkcionalnost | Opis | Modeli / tabele |
|----------------|------|------------------|
| **Broj slotova (kapacitet)** | Izmena vrednosti “broj dostupnih mesta” koja se koristi za nove dane/slotove. | `system_config`, polje `available_parking_slots` (integer). Koristi se u seederu i pri logici kapaciteta. |

Napomena: `system_config` ima `name` (unique) i `value` (integer). Za admin formu: prikaz/izmena reda gde je `name = 'available_parking_slots'`.

### 5.1 Podešavanja (admin panel) — implementirano

| Ruta | Namena |
|------|--------|
| `GET /admin/podesavanja` | `panel_admin.settings` — stranica sa sekcijama: Kapacitet, **Izvještaji – email adrese** (`purpose=report`), **Incident – email adrese** (`purpose=limo_incidents`). |
| `PUT /admin/podesavanja/capacity` | `panel_admin.settings.capacity.update` — validacija `1..99`, upis u `system_config.available_parking_slots`. Promena **ne važi retroaktivno** i primenjuje se za nove dane od **danas + 91 dan** (bez retroaktivnog update `daily_parking_data`). |
| `POST /admin/podesavanja/report-emails` | `panel_admin.settings.report-emails.store` — trim + lowercase + email sintaksa + duplicate unutar **`purpose=report`**, upis u `report_emails`. |
| `DELETE /admin/podesavanja/report-emails/{reportEmail}` | `panel_admin.settings.report-emails.destroy` — hard delete samo ako je red **`purpose=report`** (inače 404); confirm UI. |
| `POST /admin/podesavanja/limo-incident-emails` | `panel_admin.settings.limo-incident-emails.store` — polje **`limo_incident_email`**, ista validacija kao izvještaji; duplicate unutar **`purpose=limo_incidents`**. |
| `DELETE /admin/podesavanja/limo-incident-emails/{reportEmail}` | `panel_admin.settings.limo-incident-emails.destroy` — hard delete samo ako je red **`purpose=limo_incidents`** (inače 404); confirm UI. |

- **Kontroler:** `App\Http\Controllers\AdminPanel\SettingsController`.
- **Kapacitet (UX):** input je inicijalno read-only; `Promjeni` → editable + `Primjeni`/`Odkaži`. Success poruka sadrži konkretan datum važenja (danas + 91 dan).
- **Report emails (UX):** lista sortirana abecedno po emailu; `Dodaj email adresu` otvara formu; `Obriši` ima confirm modal (isti modal za obje liste, različiti `DELETE` URL-i).
- **Incident emails (UX):** analogno izvještajima; prazna lista u podešavanjima je dozvoljena. **Backend:** kada nema redova sa `purpose=limo_incidents`, `LimoIncidentService` i dalje šalje incident na podrazumijevanu adresu **`komunalna.policija@kotor.me`** i loguje fallback na kanalu **`payments`** (ovo se **ne** prikazuje u UI liste). **`ReportEmailsSeeder`** osigurava red **`komunalna.policija@kotor.me`** sa `purpose=limo_incidents` (idempotentno), tako da se ta adresa obično vodi u listi incident primalaca.

**Testovi:** `tests/Feature/AdminPanel/AdminPanelSettingsTest.php`.

---

## 7. Analitika (admin panel) — implementirano

| Ruta | Namena |
|------|--------|
| `GET /admin/analitika` | `panel_admin.analytics` — dashboard sa filterima (period, include free), KPI i tabelama. |
| `GET /admin/analitika/pdf` | `panel_admin.analytics.pdf` — PDF export za aktivne filtere (isti dataset kao UI). |

- **Filteri:** `date_from`, `date_to` (zatvoren interval, `od <= do`), `include_free` (checkbox).
- **Datum od (min):** najstariji datum **realizovane** rezervacije (fallback: najstariji `reservation_date`, pa danas ako nema rezervacija).
- **Datum do (max):** danas + 90 dana.
- **Source of truth:** rezervacije iz `reservations`, blokiranje iz `daily_parking_data.is_blocked`, operativni problemi iz `temp_data` i `post_fiscalization_data`; **Limo** iz `limo_pickup_events` (posebno; nije rezervacija).
- **Prihod (rezervacije):** suma `reservations.invoice_amount` za `status = paid` u periodu (KPI: **Prihod od rezervacija (paid)** / **Ukupno**). **Ne** uključuje Limo.
- **Termini vs dnevna naknada (`reservation_kind`):** KPI kartice razdvajaju **Termini**, **Dnevna naknada ukupno**, **Dnevna naknada — Limo**, **Dnevna naknada — Autobusi** i **Ukupno prihod (rezervacije, paid)**. Polja `time_slots_*` / `daily_ticket_*` / `daily_fee_limo_*` / `daily_fee_buses_*`. **Dnevna naknada** nema termine i **ne ulazi** u zauzete slotove, popunjenost, delove dana (slot prozori), duplo plaćanje istog termina ni „paid u free terminima” (samo `time_slots`). Posebna sekcija **Dnevna naknada** (Limo / Autobusi / Ukupno): Limo = `vehicle_type_id` iz `ReservationVehicleEligibilityService::controlDailyFeeListVehicleTypeIds()` (putničko 4+1–7+1 + mini bus 8+1); Autobusi = ostale kategorije. Agencijska tabela: kolone **Termini**, **DN Limo**, **DN Autobusi**, **DN ukupno** (+ prihodi).
- **Limo pickup (evidencija, poseban blok):** period po **`occurred_at`**, zatvoren interval, vremenska zona **Europe/Podgorica**. U obzir: `status IN (pending_fiscal, fiscalized, fiscal_failed)`; **`incident` isključen**. **Nije** dnevna naknada niti rezervacija parkinga — ne utiče na slot metrike.
- **Ukupan prihod (rezervacije + Limo):** zbir KPI prihoda od plaćenih rezervacija i Limo prihoda za isti izabrani period (jasno označeno u UI i PDF).
- **Zauzeti slotovi:** samo `reservation_kind = time_slots` — po rezervaciji 1 ako `drop_off_time_slot_id == pick_up_time_slot_id`, inače 2; dnevna naknada doprinosi 0.
- **Prosječno zauzeće termina** (`avg_occupancy_slot_level`, KPI indeks — **nije** popunjenost kapaciteta): \(occupied\_slots / (broj\_slotova \* broj\_dana)\); prikazuje se kao broj (npr. `3.99`), ne kao %. Dolazak i odlazak se broje odvojeno; vrijednost može biti > 1. **Prava popunjenost kapaciteta** (`daily_parking_data.reserved / capacity`) trenutno **nije** računata u analitici.
- **Delovi dana:** grupisanje po početnom vremenu *drop-off* termina (00–07, 07–20, 20–24).
- **Analiza po agencijama:** pregled prihoda, rezervacija i zauzetosti po registrovanim korisnicima (`reservations.user_id`), sortirano po prihodu opadajuće. Prihod = suma `invoice_amount` za `paid`; free se prikazuje kao posebna kolona i procenat.
- **Besplatne rezervacije po agencijama:** poseban prikaz besplatnih rezervacija koje su kreirali administratori (`reservations.status = free` + `created_by_admin = true`), grupisano po agencijama (`user_id`), uz “Bez agencije” za `user_id = null`. Ne utiče na KPI i ne zavisi od `include_free`.
- **Operativni indikatori (ops):**
  - **Paid rezervacije u free terminima**: broj `paid` rezervacija čiji su i drop-off i pick-up u “free zonama” (00–07 ili 20–24).
  - **Duplo plaćanje istog termina**: broj **parova** `paid` rezervacija za isti datum i iste tablice sa bar jednim zajedničkim slotom (drop/pick presek). `include_free` ne utiče (računa se samo `paid`).
- **PDF:** `AdminAnalyticsPdfGenerator` (`DomPDF`) koristi view `pdf.admin-analytics-report` i isti dataset iz `AdminAnalyticsService`.

**Testovi:** `tests/Feature/AdminPanel/AdminPanelAnalyticsTest.php`, `tests/Feature/AdminPanel/AdminAnalyticsDailyTicketTest.php`.

### 7.1 Stanje avansa po agencijama (Analitika) — implementirano (feature-flag)

UI sekcija na dnu Analytics strane: **“Stanje avansa po agencijama”** (samo kada je `advance_payments` ON).

- **Ukupno stanje avansa:** SUM(agency_advance_transactions.amount) preko svih agencija
- Tabela po agencijama (samo agencije koje imaju bar 1 ledger red):
  - Uplaćeno ukupno (type=topup)
  - Iskorišćeno ukupno (ABS(type=usage))
  - Korekcije ukupno (type=correction)
  - Trenutno stanje (SUM)
  - Poslednja aktivnost (MAX(created_at))
  - Link na detalj agencije (`panel_admin.agencies.show`)

---

## 8. Uvid (admin panel) — implementirano

Read-only modul, payment-centric (osnovna jedinica prikaza je `merchant_transaction_id`). U navigaciji **Uvid**; unutar modula **dva taba** (partial `admin-panel.insight._tabs`):

| Tab | Ruta (index) | Primarni izvor |
|-----|--------------|----------------|
| **Plaćanje rezervacije** | `GET /admin/uvid` | `temp_data` |
| **Avansna uplata** | `GET /admin/uvid/avans` | `agency_advance_topups` (samo ako je `advance_payments` ON) |

Zajedničko: **log timeline** iz `payments-YYYY-MM-DD.log` (linije koje sadrže MTID), retention `config('logging.channels.payments.days')`; povratak sa detalja čuva query (`rq`).

### 8.1 Plaćanje rezervacije (`temp_data`)

- **Source of truth (search):** `temp_data`
- **Dopuna:** `reservations` se pridružuje po istom MTID (ako postoji)
- **Admin-free rezervacije:** ne pripadaju payment lifecycle-u; ako admin unese MTID koji postoji samo kao admin-free rezervacija, prikazuje se kratka napomena (bez payment detalja).
- **Search UX:** polja `Država`, `Status (temp_data)` i `Resolution reason` su dropdown; `Tablica` se normalizuje na `A–Z0–9` (ALL CAPS); datumi se prikazuju kao `DD.MM.YYYY.`.

| Ruta | Namena |
|------|--------|
| `GET /admin/uvid` | `panel_admin.insight` — search/list nad `temp_data` (AND logika za popunjene kriterijume) + link `Detalji`. |
| `GET /admin/uvid/{merchantTransactionId}` | `panel_admin.insight.show` — detalj case-a (temp_data + rezervacija + timeline + Copy details). |

- **Kontroler:** `App\Http\Controllers\AdminPanel\InsightController`.
- **Validacija:** `App\Http\Requests\AdminPanel\AdminPanelInsightSearchRequest`.
- **Servis:** `App\Services\AdminPanel\Insight\AdminInsightService`.
- **Timeline parser:** `App\Services\AdminPanel\Insight\PaymentLogTimelineService` (labeli uključuju `createSession`, `callback`, `inquiry`, `advance topup`, …).

**Testovi:** `tests/Feature/AdminPanel/AdminPanelInsightTest.php`.

### 8.2 Avansna uplata (`agency_advance_topups`)

Vidljivo samo kada je **`config('features.advance_payments')`** true; inače rute vraćaju **404**.

- **Source of truth (search):** `agency_advance_topups`
- **Dopuna na detalju:** agencija (`users`), ledger redovi (`agency_advance_transactions` po MTID), opciono `reservations` sa istim MTID (npr. `late_success` → avans konverzija), `bank_payload`, timeline iz payments loga (`advance_topup_*` ključevi).
- **Search UX:** MTID (partial), datum od/do (`created_at`), agencija (ime ili email — `AdminAgencySearchService`), status topup-a (`pending` / `paid` / `failed` / `expired`).

| Ruta | Namena |
|------|--------|
| `GET /admin/uvid/avans` | `panel_admin.insight.advance` — lista topup pokušaja + link `Detalji`. |
| `GET /admin/uvid/avans/{merchantTransactionId}` | `panel_admin.insight.advance.show` — sekcije: topup, agencija (link na `panel_admin.agencies.show`), ledger, rezervacija (ako postoji), bank payload, timeline, Copy details. |

- **Kontroler:** `App\Http\Controllers\AdminPanel\AdvanceInsightController`.
- **Validacija:** `App\Http\Requests\AdminPanel\AdminPanelAdvanceInsightSearchRequest`.
- **Servis:** `App\Services\AdminPanel\Insight\AdminAdvanceInsightService`.

**Alternativa:** ista topup istorija (bez timeline-a) na **`GET /admin/agencije/{user}`** — §9.2; ponovno slanje potvrde — §9.4.

**Testovi:** `tests/Feature/AdminPanel/AdminPanelAdvanceInsightTest.php`.

**Napomena:** Plaćanje rezervacije **iz postojećeg avansa** (`payment_method=advance`) **ne** ide kroz Bankart ni ovaj Uvid — vidi odmah **`reservations`** (admin pretraga rezervacija) i ledger na detalju agencije.

## 6. Istorija plaćanja (registrovani korisnici)

| Funkcionalnost | Opis | Modeli / tabele |
|----------------|------|------------------|
| **Pristup history plaćanja** | Admin vidi istoriju rezervacija za registrovane korisnike (po `user_id`). | `Reservation` gde je `user_id` set; prikaz po user-u (npr. izbor korisnika ili pregled jednog korisnika). |

---

## 9. Agencije (admin panel) — implementirano (feature-flag delovi)

Rute:
- `GET /admin/agencije` → `panel_admin.agencies.index`
- `GET /admin/agencije/{user}` → `panel_admin.agencies.show`

Stranice su read-only za većinu avans podataka; napredne akcije su iza feature flag-a.

### 9.1 Lista agencija

Iznad tabele: **pretraga** (`GET ?q=`) po **imenu** ili **emailu** — heuristika ista kao kod admin pretrage rezervacija (`AdminReservationSearchHeuristic` preko `AdminAgencySearchService`: djelimični LIKE, jedno izostavljeno slovo, permutacija susjednih slova; kod imena ignoriše varijante DOO).

Kolone (izbor):
- ime, email, datum registracije
- broj rezervacija
- **saldo avansa** (SQL SUM preko `agency_advance_transactions`) — prikazuje se samo ako je `advance_payments` ON

### 9.2 Detalj agencije

Kada je `advance_payments` ON, prikazuje:
- trenutno stanje avansa (`AgencyAdvanceService::balance`)
- ledger istoriju (`agency_advance_transactions`)
- topup istoriju (`agency_advance_topups`)

### 9.3 Admin korekcija avansa

Na detalju agencije admin može dodati korekciju:
- kreira se novi ledger red `agency_advance_transactions` sa `type=correction` i `note=razlog`
- negativna korekcija ne sme spustiti saldo ispod 0 (konzervativno pravilo)

Ruta:
- `POST /admin/agencije/{user}/avans/korekcija` → `panel_admin.agencies.advance.correction.store`

### 9.4 Retry slanja potvrde topup-a

U “Topup istorija” tabeli se prikazuje kolona “Potvrda”:
- ako je topup `paid` a `confirmation_sent_at` je null → dugme “Pošalji potvrdu ponovo”

Ruta:
- `POST /admin/agencije/{user}/avans/topups/{topup}/confirmation/resend` → `panel_admin.agencies.advance.topups.confirmation.resend`

Idempotency i evidencija slanja su u `agency_advance_topups.confirmation_sent_at` / `confirmation_email`.

### 9.5 Zahtjevi za promjenu kategorije vozila

Ovaj workflow postoji da bi se sprečila zloupotreba ponovnog unosa iste registarske tablice sa drugom (npr. jeftinijom) kategorijom bez provjere dokumentacije. Formulisan je kao neutralna provjera dokaza (slika/PDF).

**Gdje se vidi:**

- Admin vidi **pending** zahtjeve na detalju agencije: **Admin → Agencije → detalj agencije**.
- Warning u **Upozorenja / Informacije** vodi direktno na **pregled zahtjeva** (`GET /admin/agencije/{user}/vehicle-category-change-requests/{request}`): klik na naslov alerta ili **Otvori zahtjev**. Pending: Prihvati/Odbij; već obrađen: read-only sa konačnim statusom.

**Source of truth:**

- tabela **`vehicle_category_change_requests`**
- prilozi u **`vehicle_category_change_request_attachments`** (1–5 fajlova po zahtjevu; legacy **`document_path`** ostaje za kompatibilnost)
- dokumenti su u **private/local storage** (nisu public)

**Dokument preview:**

- admin-only rute streamuju priloge inline (image/PDF) iz private storage-a:
  - **`panel_admin.agencies.vehicle_category_change_requests.attachments.preview`** — pojedinačni prilog
  - **`panel_admin.agencies.vehicle_category_change_requests.document`** — legacy jedan fajl (`document_path`)
- pregled zahtjeva i tabela pending na agenciji prikazuju listu **Prilozi** sa Preview linkom po fajlu
- admin email navodi broj priloga i link na stranicu pregleda (bez prilaganja svih fajlova)

**Approve (Prihvati):**

- zahtjev mora biti `pending`
- reaktivira postojeće `removed` vozilo (ne kreira novo):
  - `vehicle_type_id = requested_vehicle_type_id`
  - `vehicles.status = active`
- `vehicle_category_change_requests.status = approved`
- upisuje se `reviewed_by_admin_id` i `reviewed_at`
- uklanja se warning iz Upozorenja / Informacije

**Reject (Odbij):**

- `vehicle_category_change_requests.status = rejected`
- upisuje se `reviewed_by_admin_id` i `reviewed_at`
- vozilo ostaje `removed`
- uklanja se warning iz Upozorenja / Informacije

**Retention:**

- zahtjevi se ne brišu (ostaju `approved/rejected`)
- **pending:** prilozi ostaju u **private/local** storage-u; admin preview radi normalno
- **approved/rejected:** nakon odluke asinhrono se pokreće **`ArchiveVehicleCategoryChangeRequestAttachmentsJob`** (queue) — prilozi se uploaduju na **MEGA** u `vehicle-category-changes/{approved|rejected}/{Y}/{m}/request-{id}/`, lokalni fajl se briše **samo** nakon uspješnog uploada; metadata na **`vehicle_category_change_request_attachments`**: `archived_at`, `archive_provider`, `archive_path`, `archive_error`, `local_deleted_at` (DB redovi se **ne** brišu)
- approve/reject **ne čeka** MEGA; ako arhiva padne, lokalni fajl ostaje i job se može ponoviti (queue retry ili ručno ponovno dispatch)
- admin pregled prikazuje status po prilogu: lokalno dostupan / arhivirano na MEGA / arhiva neuspješna; Preview link samo dok lokalni fajl postoji; za arhivirane prikazuje se `archive_path` (nema MEGA preview linka u v1)

Guest rezervacije (`user_id` = null) nemaju “istoriju po korisniku”; mogu se pretraživati po email-u, tablici, datumu itd.

---

## 7. Tehnički predlog (Laravel)

- **Rute:** sve pod prefiksom npr. `admin/`, middleware `auth` + admin guard ili `role:admin`.
- **Kontroleri:** npr. `App\Http\Controllers\Admin\ReservationController`, `TempDataController`, `ReportEmailController`, `SystemConfigController`, `DailyParkingDataController` (blokiranje).
- **Modeli:** `Reservation`, `TempData`, `DailyParkingData`, `ListOfTimeSlot`, `ReportEmail`; za `system_config` opciono model `SystemConfig` sa helperom za `available_parking_slots`.
- **Politike:** provera da je trenutni user admin pre pristupa ovim akcijama.

Ovaj dokument služi kao referenca za implementaciju admin panela; pojedinačne funkcionalnosti mogu se realizovati korak po korak.
