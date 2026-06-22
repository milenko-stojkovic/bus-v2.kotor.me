# Production readiness i disaster recovery

**Namena:** Praktičan vodič za priprema produkcije, rutinsku provjeru i smanjivanje rizika gubitka podataka ili servisa. Nije zamjena za ugovor o SLA-u niti za pun monitoring steka — fokus je na onome što aplikacija **bus.kotor.me** eksplicitno podržava (alerti, zakazane komande, arhiva, admin alati).

**Povezana dokumentacija:** [admin-panel.md](./admin-panel.md) (admin panel, **Sistem status**, upozorenja), [cron-commands.md](./cron-commands.md), [scheduled-tasks-overview.md](./scheduled-tasks-overview.md), [external-file-archive.md](./external-file-archive.md), [testing-mysql.md](./testing-mysql.md).

---

## 1. Svrha dokumenta

- **Operativna spremnost:** jasno šta mora raditi u produkciji (scheduler, queue, okruženje, integracije). **V2 je u produkciji** (`https://bus.kotor.me`) — baseline monitoring, alerti, recovery i payment/fiskal tok su operativni; finija politika retencije/alerta i audit granice = **post-go-live hardening** u [`project-todo.md`](./project-todo.md) §1 (nije blokada).
- **Spremnost za obnovu:** šta treba imati (backup, konfiguracija, znanje) da se servis vrati nakon kvara ili gubitka servera — bez oslanjanja na „poznato je samo jednom čovjeku”.
- **Izbjegavanje implicitnih pretpostavki:** credentials, cron, worker procesi i privatni disk nisu vidljivi iz samog Git repozitorijuma; ovaj dokument navodi **šta operativno treba evidentirati** izvan koda.

---

## 2. Produkcijsko okruženje — očekivano stanje

Provjera prije/po puštanju u rad (checklist). Prilagoditi hosting (Plesk, systemd, managed MySQL) prema stvarnoj arhitekturi.

| Stavka | Očekivanje / napomena |
|--------|------------------------|
| `APP_ENV` | `production` |
| `APP_DEBUG` | `false` |
| HTTPS | Uključen; validan certifikat; bez miješanog sadržaja gdje je zahtijevano |
| Laravel scheduler | Jedan cron (ili ekvivalent) koji pokreće `php artisan schedule:run` svake minute ili prema uputstvu hosta |
| Queue | Ako je `QUEUE_CONNECTION=database` — **jedan ili više** `queue:work` procesa (supervisor/systemd) ili **Plesk** `queue-worker.php` svake minute (v. [cron-commands.md](./cron-commands.md) § Plesk fallback); bez toga poslovi (npr. fiskal/email) ne izvršavaju se |
| MySQL | Stabilna verzija; preporučen **strict** SQL način u skladu sa Laravel migracijama |
| Vremenska zona aplikacije | `APP_TIMEZONE` / zakazani taskovi relevantni za **`Europe/Podgorica`** gdje je u kodu eksplicitno (v. [scheduled-tasks-overview.md](./scheduled-tasks-overview.md)) |
| Plaćanje i fiskal | Pravi driveri u `.env` / config — **ne** `fake` u produkciji za banku/fiskal (v. `alerts:system-health` u [cron-commands.md](./cron-commands.md)) |
| Zaštita od fake drivera | Komanda `alerts:system-health` u produkciji može podići kritičan `admin_alert` ako su fake provideri ili rizične zastavice |
| Backup | Plan i odgovorna osoba (v. sekcija 5) — ne pretpostavljati da „host automatski sve čuva” |
| Disk | Dovoljno mjesta za aplikaciju, privatni storage (`storage/...`), logove; trend rasta |
| MEGA | Kredencijali za eksternu arhivu privatnih fajlova konfigurisani i testirani (v. [external-file-archive.md](./external-file-archive.md)) |
| Pošta | `MAIL_*` ispravno — zakazani izvještaji i operativni emailovi zavise od toga |
| Neuspjeli poslovi | Pregled `failed_jobs`; rutinski uvid (admin / Sistem status / rollup u `admin_alerts`) |

**Važno:** stranica **Sistem status** (`GET /admin/sistem-status`, v. [admin-panel.md](./admin-panel.md)) i komanda **`alerts:system-health`** su **operativna pomoć** (čitanje stanja, deduplikovani alerti). **Nisu** pun infrastrukturni monitoring (CPU, latencija mreže, replikacija baze, itd.) — to ostaje na IT/ops sloju ako je potreban.

---

## 3. Obavezni scheduler / worker procesi

### Scheduler

- Laravel mora izvršavati **`schedule:run`** u skladu sa dokumentacijom ([scheduled-tasks-overview.md](./scheduled-tasks-overview.md)).
- Raspored je **dvodijelan:** „SAFE” u `routes/console.php`; komande koje zovu stvarnu banku/fiskal **samo u produkciji** u `bootstrap/app.php` → `withSchedule`.

### Zakazane komande — sažetak (ne izmišljati druge)

**Samo u `production`** (`bootstrap/app.php`):

| Komanda | Kad (otprilike) | Kratak cilj |
|---------|-----------------|-------------|
| `reservations:process-pending` | svakih ~5 min | **No-op (stub):** broji pending `temp_data`, bez izmjena u bazi (v. `cron-commands.md` §1). Ne oslanjati se na obradu plaćanja dok se ne implementira sadržaj komande. |
| `payment:check-pending-inquiry` | svakih ~1 min | Bank inquiry (npr. Bankart) → ažuriranje stanja uplate |
| `post-fiscalization:retry` | svakih ~10 min | Ponovni pokušaj naknadne fiskalizacije (`post_fiscalization_data`) |

**SAFE / opšte** (`routes/console.php`, uključujući lokal):

| Komanda | Kad (otprilike) | Kratak cilj |
|---------|-----------------|-------------|
| `reservations:expire-pending` | svakih ~5 min | Istek pending rezervacija (prag default 5 min), oslobađanje lockova |
| `parking:sync-days` | dnevno 00:05 | Sinhronizacija `daily_parking_data` |
| `limo:cleanup-temporary-data` | dnevno 00:10 (Podgorica) | Čišćenje privremenih Limo entiteta |
| `files:cleanup-preview-cache` | dnevno 00:15 (Podgorica) | Brisanje isteklih privremenih MEGA preview fajlova |
| `files:archive-private --source=all --limit=50 --require-mega-health` | svakih ~6 h (Podgorica), `withoutOverlapping` | Serijska privatna MEGA arhiva |
| `temp-data:cleanup` | dnevno | Čišćenje starih ne-pending `temp_data` po retention pravilu |
| `reports:send-scheduled daily` | dnevno 07:00 (Podgorica) | Zakazani admin PDF izvještaji |
| **`alerts:system-health`** | **dnevno 07:30 (Podgorica)** | **Operativni zdravlje rollup + heartbeat keš** |
| `reports:send-scheduled monthly` | 1. u mjesecu 07:05 | Mjesečni izvještaji |
| `reports:send-scheduled yearly` | 1. jan 07:10 | Godišnji izvještaji |
| `advance:send-yearly-statements` | 1. jan 10:00 | Godišnje izjave avansa (feature-guard) |

*Tačan raspored: `php artisan schedule:list` na serveru.*

### Queue worker

- Ako je default queue **`database`**, bez aktivnog workera **neće se obraditi** dispatchovani poslovi (npr. jobs za plaćanje/fiskal/email zavisno od toka).
- Očekivanje: bar jedan stabilan proces `php artisan queue:work` (ili failover konfiguracija), restart politika pri deployu dokumentovana.

---

## 4. Operativne dnevne / sedmične provjere

### Dnevno (ili pri smjeni)

- **`failed_jobs`** u zadnjih 24h (broj u Sistem statusu / DB).
- **Nefiskalizovano / zaglaveljeno:** `post_fiscalization_data` nerešeno duže od operativnog praga (npr. >2h — usklađeno sa rollup logikom u `alerts:system-health`).
- **Neuspjela MEGA arhiva:** redovi `external_file_archives` u `failed`; admin stranica neuspjelih (v. [admin-panel.md](./admin-panel.md)).
- **Queue:** za `database` driver — pending backlog; „stale” poslove prati heartbeat i alert tip `queue_worker_down` (dvostruka potvrda u kodu).
- **Kritična admin upozorenja:** otvoreni `admin_alerts` sa `severity` kritičnim; dashboard Upozorenja + Sistem status.
- **Svježina heartbeat-a:** u Sistem statusu — da li su **`system_health:last_*`**, **`mega:last_diagnose_*`**, **`archive_private:last_*`** u očekivanim okvirima nakon noćnog schedulera (npr. nakon 07:30).

### Sedmično

- **Preview keš:** da li `files:cleanup-preview-cache` radi (nema abnormalnog rasta privremenih fajlova).
- **Rast arhive / broj `external_file_archives`:** trend neuspjelih vs uspješnih.
- **Kapacitet diska** na aplikacionom i privatnom volumenu.
- **Spot provjera restore-a:** bar jednom u nekom periodu praktičan test restauracije (v. sekcija 8) — ne samo „backup postoji”.

---

## 5. Backup strategija

**Ne pretpostavljamo** konkretan backup alat hosta. Ovo su **preporučeni obuhvati** koje IT treba eksplicitno pokriti:

| Resurs | Zašto |
|--------|--------|
| **MySQL baza** | Jedini izvor istine za rezervacije, uplate, fiskalne reference, agencije, alerte, redove arhive, itd. |
| **Privatni / upload fajlovi** | Sadržaj van Git-a (npr. pod `storage` koji nije javno serviran); lokalni originali prije MEGA uploada |
| **MEGA arhiva** | Dokazi i prilozi arhivirani van servera; obnova po scenariju „disk nestao ali MEGA živ” |
| **`.env` i tajne** | Bez njih deploy na čist server ne zna ključeve; čuvati **van javnog repoa**, uz kontrolisan pristup |
| **Raspored cron / systemd / supervisor** | Da novi server zna kako pokretati `schedule:run` i `queue:work` |
| **nginx / Apache / Plesk** | Virtual host, SSL, putanje do `public/`, limiti uploada |

**Politika zadržavanja:** dogovoriti retenciju (npr. dnevni + sedmični + mjesečni) i gdje se čuvaju kopije (odvojeni medij / drugi region). Detaljna dorada **`temp_data`** i srodnih operativnih podataka — **post-production hardening**, v. [`project-todo.md`](./project-todo.md) §1 (ne blokada za trenutni rad).

---

## 6. Disaster recovery — minimalni scenariji

Kratke smjernice; detalje dopunjavaju interni IT runbook-ovi.

| Scenario | Šta uraditi (smjernice) |
|----------|-------------------------|
| **Queue worker stao** | Provjeriti proces; restart worker-a; pregledati `failed_jobs` i backlog u `jobs`; nakon oporavka eventualno ponovo pokrenuti specifične poslove prema proceduri. |
| **MEGA kredencijali blokirani / pogrešni** | Ne povećavati agresivno broj uzastopnih login pokušaja; slijediti [external-file-archive.md](./external-file-archive.md) / runbook (web MEGA, `files:mega-diagnose`, `.env`, `config:clear`); admin retry za neuspjele arhive nakon što je konekcije OK. |
| **Disk puno** | Osloboditi log / privremene foldere po politici; osigurati da arhiva i uploadi imaju plan; ne brisati produkcijske baze „nasumično”. |
| **Gomilanje `post_fiscalization_data`** | Utvrđivati uzrok (fiskal dostupnost, konfiguracija, worker); ručni pregled u admin tokovima; ne pretpostavljati automatsko „ispravljanje” biznis stanja. |
| **Korumpiran ili loš deploy** | Vratiti poznat dobar artefakt (tag/commit); `composer install --no-dev`, migracije kontrolisano, `config:cache` po proceduri; rollback plan dokumentovan. |
| **Izgubljen lokalni preview keš** | `files:cleanup-preview-cache` i MEGA restore putem postojećih servisa za preview — aplikacija već podržava privremeni restore (v. [external-file-archive.md](./external-file-archive.md)). |
| **MySQL restore iz backupa** | Zaustaviti writer aplikacije ili prebaciti u read-only režim prema politici; restaurirati dump; provjeriti verziju šeme vs migracije; pokrenuti verifikaciju iz sekcije 8. |

---

## 7. Šta nije automatski recovery

Sljedeće **ne** rješavaju samo scheduler, queue ili MEGA retry — zahtijevaju ljudsku odluku ili poseban postupak:

- Ručna korekcija **stanja plaćanja** ili sporova sa bankom.
- **Uspješna fiskalizacija** bez valjanog odgovora servisa (praćenje po poslovnim pravilima i podršci fiskalnog provajdera).
- **FZBR** odobravanja, odbijanja i operativni workflow u admin panelu.
- **Poslovne i pravno-knjižovne** ispravke izvan modela aplikacije.
- Bilo kakvo „brisanje” problematičnih slogova bez analize integriteta i audit traga.

---

## 8. Restore verification

**Backup se ne smatra valjanim dok se restauracija bar jednom ne provjeri** (idealno na izolovanom okruženju ili stagingu).

Nakon restore / nakon veće infrastrukturne promjene, provjeriti:

- [ ] Aplikacija se bootuje (`php artisan about` ili otvaranje javne stranice).
- [ ] Scheduler je definisan i `schedule:list` pokazuje očekivane unose.
- [ ] Queue worker može da se pokrene i obradi test posao (ako se koristi queue).
- [ ] Konekcija ka MySQL i kritične tabele prisutne.
- [ ] **MEGA dijagnostika** (`files:mega-diagnose` ili ekvivalent) kada je arhiva u upotrebi.
- [ ] **Admin login** (`panel_admin`) i otvaranje **Sistem status** bez greške.
- [ ] **Generisanje PDF** (npr. zakazani izvještaj ili ručna akcija iz admin/toksa koji šalje PDF) — prema onome što je za ops relevantno.

Za MySQL parity testova u razvoju v. [testing-mysql.md](./testing-mysql.md) — to nije produkcijski restore, ali pomaže da migracije i testovi budu usklađeni.

---

## 9. Buduća moguća poboljšanja (nije v1)

- **S3-kompatibilna** sekundarna arhiva uz ili umjesto MEGA za smanjenje operativnog rizika jednog provajdera.
- **Vanjski monitoring** (uptime, endpoint health, disk, MySQL replika).
- **Centralizovani logovi** (Elasticsearch, Loki, cloud log servis).
- **Redovne DR vježbe** (staging restore drill bar jednom godišnje).

*Kratko: planirati kad budu budžet i prioritet; ne blokira trenutnu produkciju.*
