# External file archive (MEGA)

**Poslednje ažuriranje:** 2026-06-19 (ponovna provjera usklađenosti sa kodom; ponašanje neizmijenjeno od 2026-05)

Privatni fajlovi ispod `storage/app/private/` (npr. `limo_plate_uploads`, `limo_pickup_evidence`, `limo_pickup_photos` (tabela + zastarjeli prefiks putanje), **`limo_incidents/`** (fotografije incidenta), **`free-reservation-requests/`** (prilozi FZBR)) mogu se **arhivirati na MEGA** nakon što više nisu potrebni na produkcijskom disku. **Kredencijali za MEGA idu samo u `.env`** i koriste se **isključivo server-side** (PHP poziva Node skriptu); **nikakav browser** ne vidi lozinku.

---

## Izvor istine u bazi

Tabela **`external_file_archives`** drži:

- vezu na izvor (`source_table`, `source_id`, `source_column`)
- generisano ime fajla na MEGA (`generated_file_name` — **nije** originalni naziv korisnika)
- `mega_node_id` / `mega_path` kada je upload uspio
- `original_local_path` — relativna putanja na `local` (private) disku prije brisanja (i dalje cilj za preview/restore, čak i kad je na MEGA arhiviran **derivat**)
- **`archived_derivative`** (bool), **`derivative_source_path`**, **`derivative_options`** (JSON) — samo za **Limo plate upload** arhivu: na MEGA ide optimizovani JPEG, metadata opisuje izrez/resize (vidi Artisan ispod)
- `status`: `pending` | `uploaded` | `failed` | `superseded`
- `archived_at`, `local_deleted_at`
- **`preview_restored_at`**, **`preview_expires_at`** — vremenski ograničen admin **privremeni** re-download sa MEGA (Limo pickup/incident, FZBR prilozi u admin pregledu); vidi ispod.

**Logički izvor (source identity):** `source_table` + `source_id` + `source_column`.

**Status semantika (operativno):**

| Status | Značenje |
|--------|----------|
| `pending` | Upload u toku / red tek kreiran |
| `uploaded` | Trajna kopija na MEGA snimljena; lokalni fajl se briše tek poslije ovoga |
| `failed` | **Aktivni** neuspjeh — nema usklađenog `uploaded` siblinga za isti izvor |
| `superseded` | **Istorijski** neuspjeh: isti izvor je kasnije uspješno arhiviran (`uploaded`); red ostaje kao audit (error, `generated_file_name`), nije retry-abilan i **ne** ulazi u health / listu neuspjelih |

Kad red postane `uploaded`, servis označava starije `failed` redove istog izvora kao `superseded` (ne dira `pending` / druge `uploaded`). MEGA upload i brisanje lokalnog fajla ostaju neizmijenjeni.

**Ručna usklađenost postojećih podataka:** `php artisan files:reconcile-external-archives` (`--dry-run` bez upisa) — samo `failed` → `superseded` gdje već postoji `uploaded` sibling; bez MEGA, bez brisanja redova/fajlova. Nije na scheduleru.

---

## Pravilo brisanja lokalnog fajla

**Lokalni fajl se briše samo ako** su **upload na MEGA** i **ažuriranje reda u bazi** na `uploaded` uspješno završeni.** Ako upload ili DB update padne, fajl ostaje na disku.

---

## Transijentni retry (v1, self-healing)

**Upload:** `ExternalFileArchiveService::archiveLocalPrivateFile` pokušava do **3** puta samo kad Node/client vrati poruku klasificiranu kao **transijentnu** (`MegaArchiveFailureClassifier`: npr. timeout, `ECONNRESET`, `EAI_AGAIN`, tipični HTTP 502/503/504, rate limit — konzervativno; **ne** retry za pogrešnu lozinku, nedostajući folder, nedostajuću konfiguraciju, prazan/nekorektan JSON od skripte). Pauze između pokušaja: **1 s**, **3 s**. Derivat (privremeni JPEG) se briše tek **nakon** što su svi upload pokušaji završeni (da retry ne gubi fajl).  
Logovi (`payments`): `external_archive_upload_retry`, `external_archive_upload_exhausted`, `external_archive_upload_recovered_after_retry`; konačni neuspjeh i dalje `external_archive_upload_failed`.

**Admin preview restore:** `restoreFromMegaForPreview` / `ensureLocalPreviewForSource` — do **3** preuzimanja, pauze **~400 ms** i **~1,5 s** između pokušaja, samo za transijentne greške; **`status`** reda ostaje **`uploaded`**, ne označava se `failed` zbog preview-a. Logovi: `external_archive_preview_restore_retry`, `external_archive_preview_restore_failed`; uspjeh: postojeći `external_archive_preview_restored` (+ `download_attempt`).

**Admin alerti:** prvi transient MEGA timeout **ne** kreira `admin_alert`; operativni signal i dalje može doći iz **`alerts:system-health`** (npr. red u **`failed`**, dijagnostika) kada problem postane trajni ili agregovan.

---

## Generisano ime (bez korisničkog imena)

Format (ASCII, bez razmaka):

`{context_type}__{source_table}_{source_id}__{source_column}__{uuid}.{ext}`

Ekstenzija se uzima iz lokalne putanje (lower-case), osim kod **Limo plate derivata** gdje se na MEGA forsira **`.jpg`** (`ArchiveFilenameGenerator` + `extensionOverride`). `uuid` osigurava jedinstvenost.

---

## Konfiguracija

`config/external_archive.php` — **`preview_ttl_minutes`** iz **`EXTERNAL_ARCHIVE_PREVIEW_TTL_MINUTES`** (default **60**): trajanje privremenog preview fajla na disku (od trenutka re-downloada).

`config/services.php` → `services.mega`:

- `email` ← `MEGA_EMAIL`
- `password` ← `MEGA_PASSWORD`
- `base_folder` ← `MEGA_BASE_FOLDER` (default `bus.kotor`)
- `node_binary` ← `MEGA_NODE_BINARY` (opciono, inače `node`)
- `user_agent` ← `MEGA_USER_AGENT` (default **`BusKotorArchive/1.0`**) — eksplicitni **User-Agent** za megajs `Storage` (MEGA koristi ga za identifikaciju klijenta; preporučeno za server-side login).

Svi fajlovi iz **`files:archive-private`** (FZBR, Limo plate/pickup) idu **direktno u bazni folder** na MEGA — **bez podfoldera**.

**Izuzetak — zahtjevi za promjenu kategorije vozila:** nakon admin **approve/reject**, queue job **`ArchiveVehicleCategoryChangeRequestAttachmentsJob`** koristi **`MegaArchiveClient::uploadLocalFileToRelativePath`** i strukturu `vehicle-category-changes/{approved|rejected}/{Y}/{m}/request-{id}/attachment-{id}-{original_name}`. Metadata je na **`vehicle_category_change_request_attachments`** (ne u **`external_file_archives`**). Dok je zahtjev **pending**, prilozi ostaju samo lokalno.

---

## Operativni runbook: MEGA security lock i poruka „Wrong password?”

**Kontekst (MEGA support):** nalog može biti **privremeno zaključan** mehanizmom zaštite (npr. sumnjiva aktivnost nalik **credential stuffing**-u). Server-side login preko **megajs** / API-ja tada može pukati sa porukom u stilu:

`ENOENT (-9): Object not found. Wrong password?`

**Važno:** **Browser sesija** na mega.nz može i dalje **izgledati aktivna** — to **nije** dokaz da API login sa servera radi. Web i megijs koriste različite puteve i stanje zaštite naloga.

### 1. Šta „Wrong password?” u megajs poruci **može** značiti

1. **Stvarno pogrešni kredencijali** u `.env` (pogrešan email ili lozinka).
2. **Zastarjeli Laravel config / keš** (promijenjen `.env`, a aplikacija još drži stari `config`).
3. **MEGA security lock** ili ograničenje naloga — poruka je **dvosmislena**; sistem može odbiti login iako lozinka u `.env` tačno odgovara zapisu prije zaključavanja.

### 2. Prve operativne provjere (redoslijed)

1. Pokrenuti **`php artisan files:mega-diagnose`** (ili `.\laragon-artisan.cmd files:mega-diagnose` na Windowsu) i pročitati **`login_ok`**, **`folder_found`**, polje **`error`** bez otkrivanja lozinke u logu.
2. U `.env` provjeriti **`MEGA_EMAIL`** i **`MEGA_PASSWORD`** (bez dijeljenja vrijednosti u tiketima/chatu); uskladiti sa aktivnim nalogom u MEGA web konzoli.
3. Nakon izmjene env-a: **`php artisan config:clear`** i po potrebi **`php artisan cache:clear`**, zatim ponovo **`files:mega-diagnose`**.
4. U MEGA **web** interfejsu: istorija sesija / sigurnosni događaji / obavještenja o zaključanom ili ograničenom nalogu.

### 3. Ako je nalog zaključan ili zaštita blokira API login

1. **Ručno** promijeniti lozinku naloga u MEGA (prema pravilima provajdera).
2. Ažurirati **`.env`** (`MEGA_PASSWORD`, i email ako treba).
3. **`config:clear`**, po potrebi **`cache:clear`**.
4. Ponovo **`files:mega-diagnose`** dok **`login_ok`** ne bude uspješan prije ponovnog oslanjanja na `files:archive-private` / preview restore.

### 4. Šta **ne** raditi

- **Ne** pokretati masovno ili u uskoj petlji login/upload/dijagnozu (pojačava rizik da MEGA ponovo okine zaštitu).
- Kod operativnih problema prvo **uzrok** (kredencijali vs keš vs lock), pa umereni retry — v. i transijentni retry u kodu (**ne** odnosi se na pogrešnu klasifikaciju zaključanog naloga).

### 5. `MEGA_USER_AGENT`

**Ostaviti uvek konfigurisanim** (`MEGA_USER_AGENT` / `services.mega.user_agent`) — identifikacija klijenta je bitna za server-side ponašanje i podršku.

### 6. Strategija ako se zaključavanja ponavljaju

Razmotriti **migraciju** ka **S3-kompatibilnom** objektnom skladištu (ili drugom provajderu) kao smanjenje operativnog rizika od MEGA security pravila — plan posebno, nije implementacija u ovom repou dokumentovana.

---

## Servisi

- `App\Services\ExternalArchive\MegaArchiveService` — implementacija `MegaArchiveClient` (Node `scripts/mega-archive.js`, paket `megajs`). Login koristi fiksni **`userAgent`** iz `MEGA_USER_AGENT` / `services.mega.user_agent` (default `BusKotorArchive/1.0`). **Upload:** podržava **`uploadLocalFileToRelativePath`** (ugneždeni folderi ispod baznog foldera) pored flat uploada u bazni folder. **Download:** megajs `File.prototype.download(opts)` vraća **Readable** stream; callback (ako se koristi) dobija **`(err, Buffer)`**, ne stream — skripta koristi `const rs = file.download({}); rs.pipe(writeStream)`.
- **`App\Services\ExternalArchive\MegaArchiveFailureClassifier`** — konzervativno: da li je tekst greške **transijentan** (retry) ili ne (fail fast).
- `App\Services\ExternalArchive\ExternalFileArchiveService::archiveLocalPrivateFile(...)` — pending red → upload (do 3 pokušaja za transijentne greške) → `uploaded` → brisanje **originalnog** lokalnog fajla na `original_local_path`. Opcioni argument **`ArchiveDerivativeUpload`**: upload sa privremenog JPEG-a (`LimoPlateArchiveDerivativeBuilder`), metadata na redu; privremeni derivat se briše nakon Node poziva.
- **`App\Services\Limo\LimoPlateArchiveDerivativeBuilder`** — samo za arhivu (ne dira OCR niti upload tok): korisnički izrez `plate_crop_*_bp` + **~12,5%** margine, inače cijela slika; max duža strana **1600 px**; **JPEG Q80**; bez grayscale. Zahtijeva PHP **GD**.
- **`App\Services\ExternalArchive\MegaDiagnoseService`** + komanda **`files:mega-diagnose`** — login + listing root + postojanje `MEGA_BASE_FOLDER` bez uploada (vidi Artisan).
- `ExternalFileArchiveService::restoreFromMega(...)` — trajni restore: preuzimanje na `original_local_path`, **`local_deleted_at = null`**.
- **`ExternalFileArchiveService::restoreFromMegaForPreview(...)`** — preuzimanje na istu putanju za **admin preview**; **`local_deleted_at` ostaje postavljen** (izvor istine za „kanonski” primjerak ostaje MEGA); postavljaju se **`preview_restored_at`** i **`preview_expires_at`** (TTL).
- **`ExternalFileArchiveService::ensureLocalPreviewForSource(...)`** — ako fajl postoji lokalno, vraća putanju; ako ne postoji i postoji odgovarajući **`uploaded`** MEGA red sa **`local_deleted_at`**, poziva `restoreFromMegaForPreview`. Putanja u arhivi mora se poklapati sa proslijeđenom relativnom putanjom.
- **`App\Services\VehicleCategoryChange\VehicleCategoryChangeAttachmentArchiveService`** + job **`ArchiveVehicleCategoryChangeRequestAttachmentsJob`** — arhiva priloga zahtjeva za promjenu kategorije **nakon** `approved`/`rejected` (ne za `pending`); metadata na **`vehicle_category_change_request_attachments`**; logovi `payments`: `vehicle_category_change_attachment_archived`, `vehicle_category_change_attachment_archive_failed`, `vehicle_category_change_attachment_archive_local_missing`.

### Admin — pregled slike tablice (Limo pickup)

- Ruta: **`GET /admin/limo/pickups/{limoPickupEvent}/plate-photo-preview`** (`admin.limo.pickups.plate-photo-preview`), samo za **`source = plate`**; fajl samo ispod prefiksa **`limo_pickup_evidence/`** (produkcija, vidi `LimoPlatePickupService`) ili **`limo_pickup_photos/`** (legacy); zabranjen `..` i ostale proizvoljne putanje; nema javnog MEGA linka.

### Admin — pregled slika incidenta (Limo)

- Rute: **`GET /admin/limo/incidents/{limoIncident}/plate-photo-preview`** (`admin.limo.incidents.plate-photo-preview`) i **`GET /admin/limo/incidents/{limoIncident}/branding-photo-preview`** (`admin.limo.incidents.branding-photo-preview`) — `LimoIncidentPhotoPreviewController`; dozvoljene su samo putanje ispod prefiksa **`limo_incidents/`** (vidi `LimoIncidentService`, helper `App\Support\LimoIncidentEvidencePreviewPath`).
- Arhiva: **`source_table = limo_incidents`**, **`source_id`** = ID reda, **`source_column`** = `plate_photo_path` ili `branding_photo_path`; **`original_local_path`** mora se tačno poklapati sa vrijednošću u tom polju (isti uslov kao kod pickup preview-a).

### Admin — pregled FZBR priloga (terminalni zahtjevi)

- Ruta: **`GET /admin/besplatne-rezervacije/fzbr/attachments/{freeReservationRequestAttachment}/preview`** (`panel_admin.fzbr-attachments.preview`) — `FzbrAttachmentPreviewController` + `ServeFzbrAttachmentFile`; samo kada roditeljski zahtjev ima **`status` ∈ {`fulfilled`, `rejected`}**; dozvoljene su samo putanje ispod prefiksa **`free-reservation-requests/`** (`App\Support\FzbrAttachmentPreviewPath`).
- Arhiva: **`source_table = free_reservation_request_attachments`**, **`source_column = stored_path`**, **`context_type = fzbr_attachment`** (kao kod `files:archive-private --source=fzbr`).
- Postojeća ruta za aktivne zahtjeve (**`GET /admin/besplatne-rezervacije/zahtjevi/{request}/attachments/{attachment}/preview`**) koristi isti servis za serviranje (MEGA privremeni restore + ista validacija putanje).

### Privremeni MEGA restore i čišćenje (zajednički princip)

- Ako je lokalni fajl obrisan poslije arhive, prvi zahtjev ga **privremeno** vraća na disk; čišćenje: **`php artisan files:cleanup-preview-cache`** (zakazano dnevno **00:15** `Europe/Podgorica` u `routes/console.php`) briše istekle preview fajlove gdje je **`local_deleted_at` još uvijek postavljen** i **`preview_expires_at <= sada`**; red u **`external_file_archives`** ostaje **`uploaded`**, MEGA se ne dira.

Log kanal `payments` (bez binarnog sadržaja): `external_archive_upload_started`, **`external_archive_derivative_prepared`** (`original_bytes`, `archive_bytes`, `reduction_percent`, `derivative_options`), `external_archive_upload_succeeded` (ista polja veličine kad je derivat), **`external_archive_upload_retry`**, **`external_archive_upload_exhausted`**, **`external_archive_upload_recovered_after_retry`**, `external_archive_upload_failed`, `external_archive_local_deleted`, **`external_archive_preview_restored`** (uklj. `download_attempt`), **`external_archive_preview_restore_retry`**, **`external_archive_preview_restore_failed`**, **`external_archive_preview_cache_cleanup`**, rani `external_archive_preview_restore_failed` iz `ensureLocalPreviewForSource` samo za neočekivane izuzetke (ne za uobičajeni neuspjeh downloada nakon logovanja u preview servisu). **Arhiva komanda:** **`files_archive_private_summary`** (na kraju prolaza: `scanned` / `archived` / `failed` / `skipped`, `source`, `limit`, `dry_run`, `require_mega_health`), **`files_archive_private_skipped_mega_unhealthy`** kad je uključen `--require-mega-health` a MEGA dijagnostika nije prošla. **Admin ponovni pokušaj:** **`external_archive_admin_retry_started`** (kad operator pokrene retry iz panela).

---

### Admin — neuspjeli uploadi (glavni panel)

- **`GET /admin/sistemska-arhiva/neuspjeli`** (`panel_admin.archive.failed`) — samo **`status = failed`** (**aktivni** neuspjeh; `superseded` se ne prikazuje — to su istorijski pokušaji nakon kasnijeg `uploaded` za isti izvor).
- **`POST /admin/sistemska-arhiva/neuspjeli/{external_file_archive}/retry`** (`panel_admin.archive.failed.retry`) — **`ExternalFileArchiveService::retryFailedArchive`**: isti red i **`generated_file_name`**, bez brisanja na MEGA; za **`limo_plate_upload`** sa derivatom ponovo se gradi JPEG. Vidi **[admin-panel.md](./admin-panel.md)**.
- **Usklađenost:** `php artisan files:reconcile-external-archives` (`--dry-run`) — v. status tabelu gore.

## Artisan

- `php artisan files:archive-private --source=all|fzbr|limo --dry-run --limit=100`  
  - **`--require-mega-health`:** pri stvarnoj arhivi (bez `--dry-run`) prvo poziva **`MegaDiagnoseService`** (login + bazni folder, kao **`files:mega-diagnose`**). Ako nije „zdravo“ (`ok`, `login_ok`, `folder_found` — smisao kao u `alerts:system-health`), komanda **prekida prije skeniranja kandidata** (nema uploada/brisanja) i loguje **`files_archive_private_skipped_mega_unhealthy`** na `payments`. Korisno za **zakazane** prolaze. Ručno testiranje i dry-run i dalje mogu **bez** ovog flag-a.
  - **FZBR:** samo kada je zahtjev u terminalnom statusu (`fulfilled`, `rejected` — vidi `FreeReservationRequest`). PDF/dokumenti se arhiviraju **bez** slike derivata.  
  - **Limo plate:** `consumed_at` nije `NULL`. Prije uploada na MEGA gradi se **JPEG derivat** (korisnički izrez + ~12,5% margine ako postoji `plate_crop_*_bp`, inače cijela slika; max duža strana 1600 px, kvalitet 80). Na MEGA ide derivat; `original_local_path` i dalje pokazuje na originalnu privatnu putanju. U `external_file_archives`: `archived_derivative`, `derivative_source_path`, `derivative_options` (JSON). Log `payments`: `original_bytes`, `archive_bytes`, `reduction_percent`. Original na disku ostaje do uspješnog uploada + DB update.  
  - **Limo pickup foto:** roditeljski `limo_pickup_events.invoice_email_sent_at` nije `NULL` (konzervativno: poslije slanja računa emailom).  
  - **`limo_incidents`:** još **nije** uključeno (TODO — politika zadržavanja dokaza / email).
- **Zakazivanje (lokalni SAFE scheduler, `routes/console.php`):** `files:archive-private --source=all --limit=50 --require-mega-health` — **svakih šest sati**, timezone **`Europe/Podgorica`**, **`withoutOverlapping(360)`** (mutex do 360 min). Mala serija po kategoriji; incident fajlovi i dalje nisu obuhvaćeni dok komanda ne proširi izvor.
- `php artisan files:restore-private {archive_id}` — trajni restore sa MEGA na originalnu privatnu putanju (`local_deleted_at` se briše).
- `php artisan files:reconcile-external-archives` — `failed` → `superseded` gdje već postoji `uploaded` za isti izvor; **`--dry-run`** bez upisa. Nije na scheduleru.
- **`php artisan files:mega-diagnose`** — provjera login-a i postojanja baznog foldera na MEGA (Node akcija `diagnose` u `scripts/mega-archive.js`; **ne** kreira bazni folder). Izlaz: maskirani email, **User-Agent** (`MEGA_USER_AGENT` / config), JSON polja (`login_ok`, `folder_found`, `root_children_sample`, …); lozinka se **nikad** ne ispisuje. Korisno kad browser login radi, a megajs vraća npr. `ENOENT (-9)`. **Kada poruka spominje „Wrong password?”:** prvo slijediti sekciju **Operativni runbook: MEGA security lock** iznad — ne zaključivati odmah da je `.env` pogrešan.
- **`php artisan files:cleanup-preview-cache`** — briše **istekle** privremene preview fajlove (vidi gore); ne dira redove gdje je **`local_deleted_at` null** (lokalno zadržani fajlovi).

Na Windows-u iz korena repa: `.\laragon-artisan.cmd files:archive-private --dry-run`, `.\laragon-artisan.cmd files:mega-diagnose` (vidi `docs/project-conventions.md` §3).

### Migracije (redoslijed)

1. `2026_05_14_120000_create_external_file_archives_table.php`
2. `2026_05_15_100000_add_preview_columns_to_external_file_archives_table.php`
3. `2026_05_15_120000_add_derivative_columns_to_external_file_archives_table.php`

---

## Šta još nije

- **Politika brisanja** fajlova na MEGA nije definisana.
- **Derivat arhive** za **Limo incident** branding / plate fotografije — nije uključen (samo puni fajl kad/ako incident uđe u `files:archive-private`).
- **UI** za generički pregled / trajni restore arhive (bez Limo admin preview linka) nije implementiran — i dalje postoji **`files:restore-private`** po ID arhive.
