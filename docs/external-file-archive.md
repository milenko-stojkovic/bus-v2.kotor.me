# External file archive (MEGA)

**Poslednje ažuriranje:** 2026-05-14

Privatni fajlovi ispod `storage/app/private/` (npr. `limo_plate_uploads`, `limo_pickup_photos`, prilozi FZBR) mogu se **arhivirati na MEGA** nakon što više nisu potrebni na produkcijskom disku. **Kredencijali za MEGA idu samo u `.env`** i koriste se **isključivo server-side** (PHP poziva Node skriptu); **nikakav browser** ne vidi lozinku.

---

## Izvor istine u bazi

Tabela **`external_file_archives`** drži:

- vezu na izvor (`source_table`, `source_id`, `source_column`)
- generisano ime fajla na MEGA (`generated_file_name` — **nije** originalni naziv korisnika)
- `mega_node_id` / `mega_path` kada je upload uspio
- `original_local_path` — relativna putanja na `local` (private) disku prije brisanja
- `status`: `pending` | `uploaded` | `failed`
- `archived_at`, `local_deleted_at`

---

## Pravilo brisanja lokalnog fajla

**Lokalni fajl se briše samo ako** su **upload na MEGA** i **ažuriranje reda u bazi** na `uploaded` uspješno završeni.** Ako upload ili DB update padne, fajl ostaje na disku.

---

## Generisano ime (bez korisničkog imena)

Format (ASCII, bez razmaka):

`{context_type}__{source_table}_{source_id}__{source_column}__{uuid}.{ext}`

Ekstenzija se uzima iz lokalne putanje (lower-case); `uuid` osigurava jedinstvenost.

---

## Konfiguracija

`config/services.php` → `services.mega`:

- `email` ← `MEGA_EMAIL`
- `password` ← `MEGA_PASSWORD`
- `base_folder` ← `MEGA_BASE_FOLDER` (default `bus.kotor`)
- `node_binary` ← `MEGA_NODE_BINARY` (opciono, inače `node`)

Svi fajlovi idu **direktno u bazni folder** na MEGA — **bez podfoldera**.

---

## Servisi

- `App\Services\ExternalArchive\MegaArchiveService` — implementacija `MegaArchiveClient` (Node `scripts/mega-archive.js`, paket `megajs`). **Download:** megajs `File.prototype.download(opts)` vraća **Readable** stream; callback (ako se koristi) dobija **`(err, Buffer)`**, ne stream — skripta koristi `const rs = file.download({}); rs.pipe(writeStream)`.
- `App\Services\ExternalArchive\ExternalFileArchiveService::archiveLocalPrivateFile(...)` — pending red → upload → `uploaded` → brisanje lokalnog fajla.
- `ExternalFileArchiveService::restoreFromMega(...)` — preuzimanje nazad na `original_local_path`, `local_deleted_at = null`. U Node payload idu **`mega_path`** i (kad treba) **`generated_file_name`** — ako `storage.navigate(mega_path)` ne nađe fajl, skripta traži po imenu u folderu **`MEGA_BASE_FOLDER`**.

Log kanal `payments` (bez binarnog sadržaja): `external_archive_upload_started`, `external_archive_upload_succeeded`, `external_archive_upload_failed`, `external_archive_local_deleted`.

---

## Artisan

- `php artisan files:archive-private --source=all|fzbr|limo --dry-run --limit=100`  
  - **FZBR:** samo kada je zahtjev u terminalnom statusu (`fulfilled`, `rejected` — vidi `FreeReservationRequest`).  
  - **Limo plate:** `consumed_at` nije `NULL`.  
  - **Limo pickup foto:** roditeljski `limo_pickup_events.invoice_email_sent_at` nije `NULL` (konzervativno: poslije slanja računa emailom).  
  - **`limo_incidents`:** još **nije** uključeno (TODO — politika zadržavanja dokaza / email).
- `php artisan files:restore-private {archive_id}` — restore sa MEGA na originalnu privatnu putanju.

Na Windows-u iz korena repa: `.\laragon-artisan.cmd files:archive-private --dry-run` (vidi `docs/project-conventions.md` §3).

---

## Šta još nije

- **Politika brisanja** fajlova na MEGA nije definisana.
- **UI** za pregled / restore arhive nije implementiran (dovoljni su metapodaci u bazi + `files:restore-private`).
