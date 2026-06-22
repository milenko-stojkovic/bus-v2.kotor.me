# Cron / scheduled commands – spisak

Svi cron job-ovi su Laravel Artisan komande.

- **Local SAFE schedule**: `routes/console.php` (bez real bank/fiscal poziva)
- **Production-only schedule**: `bootstrap/app.php` preko `withSchedule()` (bank/fiscal komande pod `app()->environment('production')`)

Puna tabela rasporeda: **`docs/scheduled-tasks-overview.md`**.

**Napomena:** `temp_data` se zadržava kao **audit trail** — uspešno plaćanje **ne briše** red (status `processed`).

---

## 1. ProcessPendingReservations

**Komanda:** `reservations:process-pending`

**Stanje u kodu (važno):** komanda je trenutno **no-op / stub** — `handle()` ne menja redove, **ne briše `temp_data`**, **ne kreira rezervacije** i **ne poziva fiskal** (`app/Console/Commands/ProcessPendingReservations.php`). Izlaz u konzoli samo broji `pending` redove radi transparentnosti. U produkciji je i dalje zakazana u `bootstrap/app.php` radi budućeg razvoja, ali **ne smije se tumačiti** kao aktivna obrada plaćanja ili pending rezervacija.

**Originalna namjera (dok se ne implementira):** eventualna obrada `pending` redova mora biti usklađena sa `docs/payment-state-machine.md` i `docs/workflow-placanje-temp-data.md` — **`temp_data` se ne briše** na uspehu glavnog payment toka (audit).

**Frekvencija:** npr. svakih 5 minuta (kada je uključena u schedule).

**Tabele (kad/ako bude implementacija):** temp_data, reservations, eventualno post_fiscalization_data — trenutno komanda ih **ne dira**.

---

## 1b. RetryPostFiscalization

**Komanda:** `post-fiscalization:retry`

**Opis:** Retry fiskalizacije za rezervacije iz **post_fiscalization_data** gde je **next_retry_at <= now**. Poziva fiskalni API; pri uspehu ažurira reservation fiscal_*, briše slog iz post_fiscalization_data i šalje kupcu **novi fiskalni PDF** i email. Pri neuspehu poveća attempts i postavi next_retry_at.

**Frekvencija:** svakih 10 minuta (bootstrap/app.php).

**Tabele:** post_fiscalization_data, reservations.

---

## 1c. CheckPendingPaymentStatus (timeout callback)

**Komanda:** `payment:check-pending-inquiry`

**Opis:** (1) Za **temp_data** u **pending** starije od **`payment.stale_pending_warn_after_minutes`** (npr. 12) — log **`payment_pending_too_long`** u `payments` (throttle keš po slogu; **bez promene statusa**). (2) Samo ako je **`PaymentStatusInquiryService::isImplemented()`** = true (Bankart + `BANKART_STATUS_INQUIRY_ENABLED` + kompletna konfiguracija): za pending starije od **`payment.pending_inquiry_after_minutes`** poziva **inquire()**; keš **throttle** po **`merchant_transaction_id`** (`payment.status_inquiry_throttle_minutes`). Rezultat **SUCCESS** / **ERROR** (Bankart `transactionStatus`) → **`PaymentCallbackJob`**. Odgovor **„Transaction not found“** → **`PaymentInitFailureService`** (`canceled`, `resolution_reason=payment_init_failed`, release lock) — **nije** dispatch joba.

**Frekvencija:** svakih 1 minut (bootstrap/app.php).

**Tabele:** temp_data, reservations, daily_parking_data.

**Config:** `stale_pending_warn_after_minutes` (`PAYMENT_STALE_PENDING_WARN_AFTER_MINUTES`); `pending_inquiry_after_minutes` (`PAYMENT_PENDING_INQUIRY_AFTER_MINUTES`); `status_inquiry_throttle_minutes` (`PAYMENT_STATUS_INQUIRY_THROTTLE_MINUTES`); `status_inquiry_not_found_grace_minutes` (`PAYMENT_STATUS_INQUIRY_NOT_FOUND_GRACE_MINUTES`); `bankart_status_inquiry_enabled` (`BANKART_STATUS_INQUIRY_ENABLED`).

---

## 2. ExpirePendingReservations

**Komanda:** `reservations:expire-pending`

**Opis:** Proverava temp_data slogove koji su **pending** duže od praga (`config/reservations.php` → `pending_expire_minutes`). Postavlja status **`expired`**, loguje tranziciju i **smanjuje `pending`** na `daily_parking_data` za **oba** time slota (v. `ExpirePendingReservations`). Namijenjeno **pravim** pending session-ima (korisnik na banci). **Ne** zamjenjuje odmah zatvaranje init failure-a (`payment_init_failed` u checkout-u / inquiry-u).

**Preporuka produkcija:** `RESERVATIONS_PENDING_EXPIRE_MINUTES=15` ili **30** (ne držati globalno **5** osim privremenog workaround-a).

**Frekvencija:** svakih 10 minuta.

**Tabele:** temp_data.

---

## 3. AssignLateSuccessReservations

**Komanda:** `reservations:assign-late-success`

**Opis:** Proverava temp_data slogove sa statusom **late_success**. **Namjerno no-op stub** — `handle()` ne kreira rezervacije i ne mijenja redove (`AssignLateSuccessReservations.php`). **`late_success` obrada je ručna:** staff workflow **`/staff/late-success`** (`LateSuccessController`: force / reject). Automatska dodjela nije u planu (slot/kapacitet posle expire-a). V. **`payment-state-machine.md`** §4b, **`project-done.md`** (2026-06-19).

**Frekvencija:** nije zakazana u produkciji (stub; ručno po potrebi samo za dijagnostiku).

**Tabele:** temp_data (samo čitanje u stubu).

---

## 3b. SyncDailyParkingDays

**Komanda:** `parking:sync-days`

**Opis:** Osigurava pokrivenost `daily_parking_data` za tekući dan i narednih ~90 dana (`upsert`); briše redove za prošle datume. Ne menja namerno postojeće `reserved` / `pending` za žive redove na način koji krši poslovna pravila — v. `SyncDailyParkingDays`.

**Frekvencija:** dnevno u 00:05 (Local SAFE schedule: `routes/console.php`).

---

## 4. UpdateDailyParkingAvailability

**Komanda:** `parking:update-availability`

**Opis:** Ažurira daily_parking_data prema napravljenim rezervacijama. Povećava **reserved** i smanjuje **pending** kada se rezervacija fiskalizuje. Može proveravati i resetovati kapacitet za novi datum ako je potrebno.

**Frekvencija:** **nije** u Laravel `Schedule` u repozitorijumu — pokretanje **ručno** ili vlastiti cron ako operativno treba (u nekim tekstovima ostaje spomen opsega 5–10 min kao orijentacija).

**Tabele:** daily_parking_data, reservations, temp_data.

---

## 5. SendReservationEmails

**Komanda:** `reservations:send-emails`

**Opis:** Proverava reservations gde **`email_sent = Reservation::EMAIL_NOT_SENT` (0)**. Šalje potvrdu rezervacije korisniku. Nakon slanja → **`EMAIL_SENT` (1)** preko **`markConfirmationEmailSent()`**. *(Stanje **EMAIL_SENDING** (2) koriste queue jobovi za lock — v. `SendInvoiceEmailJob`.)*

**Frekvencija:** **nije** u Laravel `Schedule` u repozitorijumu — pokretanje **ručno** ili vlastiti cron ako operativno treba (u nekim tekstovima ostaje spomen opsega 5–10 min kao orijentacija).

**Tabele:** reservations.

---

## 6. CleanupOldTempData

**Komanda:** `temp-data:cleanup`

**Opis:** Briše samo **stare ne-pending** redove po retention pravilu (default 180 dana). `pending` se **nikad** ne briše. Uspešno plaćanje i dalje **ne briše** red (`temp_data.status = processed`) — audit ostaje dok ne pređe cutoff.

**Frekvencija:** dnevno.

**Tabele:** temp_data.

---

## 6b. LimoCleanupTemporaryData

**Komanda:** `limo:cleanup-temporary-data`

**Opis:** Briše **istekle nekorišćene** Limo privremene podatke (bez poziva banke/fiskala):

1. **`limo_qr_tokens`** — redovi gdje je **`valid_on` strogo prije današnjeg kalendarskog dana** (`Europe/Podgorica`). Već iskorišćeni QR-i su ionako obrisani pri pickup-u.
2. **`limo_plate_uploads`** — redovi gdje je **`expires_at` &lt; sada** i **`consumed_at` je NULL**; za svaki red briše se fajl na **private** `local` disku ako postoji, zatim slog.

**Ne briše:** `limo_pickup_events`, `limo_pickup_photos`, fajlove u **`limo_pickup_evidence/`** (dokazi nakon potvrde). Potrošeni (`consumed`) plate upload slogovi se ne diraju ovom komandom.

**Frekvencija:** dnevno u **00:10** (`Europe/Podgorica`) — Local SAFE schedule u `routes/console.php`.

**Logovi (`payments`):** `limo_qr_tokens_cleaned`, `limo_plate_uploads_cleaned`.

**Tabele / disk:** `limo_qr_tokens`, `limo_plate_uploads`, privatni storage za privremene fotografije.

---

## Napomene

- **`reservations:process-pending`** je trenutno **intencionalno no-op** (v. §1 ispod) — ostale komande u ovom fajlu opisuju stvarno ponašanje kada `handle()` radi posao.
- Ostale komande koriste tipično Eloquent za statuse u **`temp_data`** (`pending`, `canceled`, `expired`, `late_success`, …) i snapshot polja gde je primenjivo. Za **`agency_advance_topups`** postoji zaseban status **`failed`** — to **nije** `temp_data.status`.
- Za pokretanje scheduler-a na serveru: `* * * * * php /path/to/artisan schedule:run` (cron entry).
- Lokalno: `php artisan schedule:work` ili `php artisan schedule:list`.
- Komande su u `app/Console/Commands/`.
- Raspored scheduler-a je podeljen:
  - **Local SAFE schedule**: `routes/console.php`
  - **Production-only schedule**: `bootstrap/app.php` → `withSchedule()`
- Config: `config/reservations.php` (pending_expire_minutes, temp_data_retention_days); env: `RESERVATIONS_PENDING_EXPIRE_MINUTES`, `TEMP_DATA_RETENTION_DAYS` (legacy: `RESERVATIONS_TEMP_DATA_RETENTION_DAYS`).

---

## Lokalni cron (Laragon Cronical)

Ovaj projekat koristi Laravel scheduler. Na Windows / Laragon-u se tipično koristi **Cronical** koji u pozadini izvršava cron entry.

### Gde je `cronical.dat`

- Podrazumevana putanja (Laragon): `C:\laragon\bin\cronical\cronical.dat`

### Cronical job (obavezno: `schedule:run` na minut)

Dodaj sledeći job u `cronical.dat`:

```
* * * * * cmd /c cd C:\laragon\www\bus.kotor.me && php artisan schedule:run >> NUL 2>&1
```

Ako `php` nije u PATH-u, koristi punu putanju do Laragon PHP:

```
* * * * * cmd /c cd C:\laragon\www\bus.kotor.me && C:\laragon\bin\php\php-8.x.x\php.exe artisan schedule:run >> NUL 2>&1
```

### Kako pokrenuti Cronical service

- U Laragon UI: **Menu → Cron → Start** (ili “Cronical” ako je tako imenovano)
- Provera da radi: pogledaj “Last run” u Cronical UI, ili proveri da se `schedule:run` izvršava svake minute.

### Šta je zakazano za “Kartica avansa”

Scheduler je dodat u `routes/console.php`:

- `advance:send-yearly-statements` se izvršava **1. januara u 10:00**

### Lokalno testiranje schedulera (dev only)

Privremeno izmeni u `routes/console.php`:

```php
Schedule::command('advance:send-yearly-statements')
    ->everyMinute();
```

Zatim sa uključenim Cronical job-om sačekaj 1–2 minuta i proveri:

- da se u logu `storage/logs/payments-*.log` pojavljuje `advance_yearly_statement_sent` ili `advance_yearly_statement_skipped`
- ili ručno pokreni `php artisan schedule:run` i proveri output/log

Nakon testa **vrati** scheduling na:

```php
Schedule::command('advance:send-yearly-statements')
    ->yearlyOn(1, 1, '10:00');
```

---

## Laravel scheduler / Laragon safe local schedule

### Cronical pokreće samo `schedule:run`

U `C:\laragon\bin\cronical\cronical.dat` dodati (svaki minut):

```
* * * * * cmd /c cd C:\laragon\www\bus.kotor.me && php artisan schedule:run >> NUL 2>&1
```

*(Ako `php` nije u PATH-u, vidi gore punu putanju.)*

### SAFE job-ovi koji su zakazani lokalno

Ovi job-ovi su dodati u `routes/console.php` i smatraju se bezbednim za lokalni dev scheduler jer ne kontaktiraju stvarnu banku niti fiskalni servis:

- `advance:send-yearly-statements` — **yearlyOn(1, 1, '10:00')**
  - Guard: ne radi ništa ako je `config('features.advance_payments') === false`
- `reports:send-scheduled daily|monthly|yearly`
  - **daily**: `dailyAt('07:00')` — period = prethodni dan
  - **monthly**: `monthlyOn(1, '07:05')` — period = prethodni mjesec
  - **yearly**: `yearlyOn(1, 1, '07:10')` — period = prethodna godina
  - Primalac(i): tabela `report_emails` (jedan email po primaocu; nema fallback primaoca ako je tabela prazna)
  - PDF paketi: “po uplati”, “po tipu vozila” i (kada je `advance_payments` ON) “obaveze po avansu”
  - Idempotency: tabela `scheduled_report_deliveries` (unique: period + recipient)
  - Failure: bez parcijalnih emailova ako PDF generisanje padne; admin email `bus@kotor.me` + `admin_alerts` zapis (idempotentno po periodu)
- `alerts:system-health` — **`dailyAt('07:30')`** (`Europe/Podgorica`)
  - Kreira **`admin_alerts`** samo kada je potrebno; **v1** — nije pun monitoring, nema posebnog email kanala za ove tipove.
  - **`queue_worker_down`:** samo za **`database`** queue; prvi „stale“ `jobs` signal **nije** alarm — keš + log; alarm nakon potvrde (v. **`config/queue.php`** `system_health` i **`docs/admin-panel.md`**). **Bez** auto-restarta workera u kodu.
  - Ostalo: u **production** — fake payment/fiscal / `FAKE_PAYMENT_E2E_SYNC`; dnevni rollup (neuspeli poslovi 24h, `external_file_archives.status=failed`, MEGA dijagnostika ako su kredencijali podešeni, nerešeni `post_fiscalization_data` stariji od 2h).
  - Deduplikacija: **`AdminAlertService::createOnce`** (v. **`docs/admin-panel.md`**).
  - **Heartbeat (cache):** na početku run-a **`system_health:last_run_at`**; nakon normalnog završetka komande **`system_health:last_ok_at`**; nakon `MegaDiagnoseService::run()` — **`mega:last_diagnose_at`**, **`mega:last_diagnose_ok`**, opciono **`mega:last_diagnose_error`** (skraćeno). Klasa **`App\Support\OperationalHeartbeatCache`**, TTL ~30 dana (čita **Sistem status** u adminu, v. **`docs/admin-panel.md`**).
- `reservations:expire-pending` — **everyFiveMinutes** (prag `pending_expire_minutes`, default **5**)
- `parking:sync-days` — **dailyAt('00:05')**
- `files:archive-private --source=all --limit=50 --require-mega-health` — **everySixHours** (`Europe/Podgorica`), **withoutOverlapping(360)** (mutex do 360 minuta ako se run „zaglavio“)
  - Mala serija: najviše **50** kandidata po kategoriji (FZBR prilozi / Limo plate / Limo pickup foto — v. komanda).
  - **MEGA gate:** ako je `--require-mega-health` i MEGA dijagnostika nije uspješna (`MegaDiagnoseService`, ista `login_ok` / `folder_found` / `ok` ideja kao u `alerts:system-health`), komanda **ne arhivira**; log na `payments`: **`files_archive_private_skipped_mega_unhealthy`**. Ručni ili dry-run pozivi **bez** ovog flag-a ostaju kao prije.
  - Na kraju rada (kad se kandidati obrade): log **`files_archive_private_summary`** na `payments` (ukupno `scanned` / `archived` / `failed` / `skipped`, itd.).
  - **Heartbeat (cache):** na početku (valjanog `source`) **`archive_private:last_run_at`**; nakon normalnog završetka **`archive_private:last_ok_at`** i JSON string **`archive_private:last_summary`** (`scanned`, `archived`, `failed`, `skipped`, `timestamp`, `source`, `limit`, `dry_run`, `require_mega_health`; ako je run prekinut MEGA gate-om, polje **`aborted`** sa razlogom, brojači 0). Klasa **`App\Support\OperationalHeartbeatCache`**, TTL ~30 dana.
  - **`limo_incidents`** i dalje nisu u obimu `files:archive-private` (TODO u kodu).
- `temp-data:cleanup` — **daily**

### EXCLUDED job-ovi (namerno nisu zakazani lokalno)

Sledeći job-ovi su **namerno izostavljeni** iz lokalnog scheduler-a (ne pojavljuju se u `schedule:list`) zbog rizika od realnih eksternih finansijskih/fiskalnih radnji:

- `post-fiscalization:retry`
  - **Reason**: poziva stvarni fiskalni servis i šalje fiskalni PDF / email nakon uspeha
- `payment:check-pending-inquiry`
  - **Reason**: radi bankarski inquiry prema stvarnoj banci (Bankart), može triggerovati payment state machine
- `reservations:process-pending`
  - **Reason**: registrovana samo u **`bootstrap/app.php`** kada je okruženje **`production`**; u kodu je trenutno **no-op** (broji pending `temp_data`, bez izmjena). Nije duplirana u `routes/console.php` SAFE listi.

Sledeće komande su **nezakazane** jer frekvencija u dokumentu nije striktno definisana (navedeno je opseg ili “po potrebi”):

- `reservations:assign-late-success` — **Reason**: no-op stub; **`late_success`** se rješava ručno preko **`/staff/late-success`**, ne automatskim cron-om (v. `payment-state-machine.md` §4b)
- `parking:update-availability` — **Reason**: “svakih 5–10 minuta” (nije striktna frekvencija)
- `reservations:send-emails` — **Reason**: “svakih 5–10 minuta” (nije striktna frekvencija)

---

## MEGA arhiva — `files:mega-diagnose` i security lock

- Ručna dijagnostika: **`php artisan files:mega-diagnose`** — ne šifruje lozinku u izlazu; potvrđuje login i bazni folder (v. `docs/external-file-archive.md` → Artisan).
- Ako megajs javi **`Wrong password?`** / **`ENOENT (-9)`** a browser na mega.nz i dalje radi, to **ne** znači ispravne server kredencijale: mogući su zastareli Laravel config keš, ili **MEGA security lock** (npr. nakon sumnjive aktivnosti). **Puna procedura** (zaključavanje, promjena lozinke, `config:clear`, šta ne raditi kod retry-a): v. **[external-file-archive.md](./external-file-archive.md)** — sekcija **Operativni runbook: MEGA security lock**.
- **Ne** agresivno ponavljati login iz skripti/crona dok se uzrok ne riješi — rizik ponovnog okidanja zaštite.
- Zakazani **`alerts:system-health`** (dnevni MEGA dio) umereno poziva dijagnozu; **`queue_worker_down`** koristi dvostruku provjeru i cache marker (v. **`docs/admin-panel.md`**). Ručni „hammer“ MEGA login-a i dalje izbjegavati.

---

## Production readiness (bank/fiscal) — ne sme se zaboraviti

Sledeći scheduled job-ovi su kritični za produkciju jer imaju veze sa **stvarnim plaćanjem** i/ili **stvarnom fiskalizacijom**. Lokalno su **namerno isključeni iz scheduler-a** (SAFE schedule) da bi se izbegao rizik, ali pre izlaska u produkciju moraju biti:

- **implementirani i stabilni** (ako su označeni kao stub/TODO),
- **konfigurisani** (env, kredencijali, URL),
- **operativno provereni** (cron + queue worker + logovi/alerti),
- i jasno verifikovani kroz `schedule:list` + end-to-end scenarije.

### Komande koje zahtevaju production proveru

- `payment:check-pending-inquiry`
  - **Zašto je kritično**: radi real bank inquiry (Bankart) i može pokrenuti payment state machine (dispatch `PaymentCallbackJob`).
  - **Pre produkcije**: potvrditi da je inquiry bezbedan (throttle, idempotency), da su env/kredencijali validni, i da se ponašanje slaže sa `docs/payment-callback-handling.md` i `docs/payment-states.md`.

- `post-fiscalization:retry`
  - **Zašto je kritično**: radi realnu fiskalizaciju (poziva fiskalni servis), menja reservation fiscal_* i šalje fiskalni PDF/email.
  - **Pre produkcije**: potvrditi da su fiskalni env parametri validni, retry/backoff pravila i audit logovi rade, i da je email flow stabilan.

- `reservations:process-pending`
  - **Stanje u kodu:** **no-op** — v. **`docs/cron-commands.md`** §1. Zakazana u produkciji u **`bootstrap/app.php`**, ali **trenutno ne izvršava** pipeline obrade plaćanja.
  - **Pre produkcije / operativno:** ne očekivati efekat ove komande dok se ne dogodi stvarna implementacija `handle()`; kada se implementira, ponovo procijeniti rizik i dokumentaciju.

### Operativni minimum (pre produkcije)

- Cron (na serveru): `* * * * * php /path/to/artisan schedule:run >> /dev/null 2>&1`
- Queue worker: stabilno pokrenut (u produkciji se bank/fiscal tokovi ne smeju oslanjati na `sync`).
- `storage/logs/payments-*.log` i alert email (ako je podešen) pokazuju očekivane događaje.

### Plesk fallback queue worker (`queue-worker.php`)

Kada **Laravel Toolkit Queue** nije dostupan (nema SSH/supervisor), koristi se root skripta **`queue-worker.php`** kao Plesk scheduled task:

| Postavka | Vrijednost |
|----------|------------|
| Cron | `* * * * *` (svake minute) |
| Tip zadatka | Run a PHP script |
| Skripta | `bus-v2.kotor.me/queue-worker.php` (relativno od home domena) |

**Ponašanje:**

- Pokreće `queue:work` **bez** `--stop-when-empty` — worker ostaje aktivan do **`--max-time=55`** i provjerava red svake **`--sleep=1`** sekunde, pa job koji stigne nekoliko sekundi poslije starta crona ne čeka do sljedeće minute.
- **`--tries=3`**, **`--timeout=130`**, **`--memory=512`** — ne mijenjati bez usklađivanja sa `docs/production-hardening.md`.
- **Lock** `plesk_queue_worker_bus_v2` (TTL **70s**, malo iznad max-time) preko **`Cache::lock`** (`CACHE_STORE=database` + tabela `cache_locks`); ako cache lock nije dostupan, **`flock`** na `storage/framework/queue-worker.lock`. Drugi poziv dok prvi traje → **exit 0** (nema preklapanja).
- Preferirano rješenje i dalje: **supervisor/systemd** sa trajnim `queue:work`; ova skripta je kompromis za Plesk-only hosting.

Paralelno: **`schedule-run.php`** (isti cron pattern) za `php artisan schedule:run`.

### Verifikacija

Na Windows/Laragon:

- `.\laragon-artisan.cmd schedule:list`

Proveri da:

- SAFE job-ovi gore postoje
- EXCLUDED job-ovi nisu prisutni
- `schedule:list` ne puca (posebno zbog cron izraza)
