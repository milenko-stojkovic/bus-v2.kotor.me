# Production runbook (kratka checklist)

Operativni koraci posle deploy-a ili pri prvom puštanju u produkciju. Detalji tokova: `success-payment-pipeline.md`, `payment-architecture.md`.

---

## Trenutna topologija (2026-06-19)

### Javni URL-ovi

| Okruženje | URL | Uloga |
|-----------|-----|--------|
| **V2 produkcija** | `https://bus.kotor.me` | **Aktivna** aplikacija — plaćanje, fiskal, PDF, email |
| **V1 rezerva (rollback)** | `https://bus-v1.kotor.me` | Stara V1 aplikacija — samo rezerva, ne dirati bez plana |
| **V2 staging** | `https://bus-v2.kotor.me` | E2E validacija završena; odvojena baza; ranije simulacija Bankart/fiskal |
| **Lokalno** | npr. `https://bus.kotor.me.test` | Razvoj, PHPUnit, fake driver |

### Plesk — folderi i Document Root

| Poddomen / domen | Document Root (relativno) | Fizički folder aplikacije |
|------------------|---------------------------|---------------------------|
| **`bus.kotor.me`** (V2 produkcija) | `bus-v2.kotor.me/public` | **`bus-v2.kotor.me`** |
| **`bus-v1.kotor.me`** (V1 rezerva) | `bus.kotor.me/public` (ili ekvivalent) | **`bus.kotor.me`** (stari V1 kod) |
| **`bus-v2.kotor.me`** (staging) | `bus-v2.kotor.me/public` (staging instanca) | staging deploy istog repoa |

Cron taskovi (`schedule-run.php`, `queue-worker.php`) moraju pokazivati na **fizički folder V2 produkcije:** `bus-v2.kotor.me/`.

### Baze podataka

| Okruženje | MySQL baza |
|-----------|------------|
| **V2 produkcija** | **`bus`** |
| **V1 rezerva** | **`opstinakotor_busnova`** |

### SSL

- **`bus.kotor.me`** — Let's Encrypt, **Secured** (ručni reissue pri cut-over-u).
- **`www.bus.kotor.me`** — **Not Secured**; ne koristi se u aplikaciji (`APP_URL` bez `www`).

---

## Cut-over V1 → V2 (2026-06-19) — rezime

**Status:** produkcija puštena na `https://bus.kotor.me`. Tok potvrđen: Bankart → callback → rezervacija → fiskalizacija → QR → PDF → email.

### Maintenance mode

Prije finalnog puštanja uključen je Laravel **Maintenance Mode** da korisnici ne prave rezervacije tokom migracije. Nakon provjera isključen — produkcija aktivna.

### Migracija rezervacija iz V1

Podaci preneseni iz V1 tabele `reservations` u V2 bazu **`bus`**, preko privremenih tabela:

- `v1_reservations`
- `v1_vehicle_types`

**Ukupno preneseno:** **21.342** rezervacije.

| Status | Broj |
|--------|------|
| `paid` | 20.934 |
| `free` | 408 |

**Preneseni statusi:** samo `paid` i `free`.

**Cijena** prenesena prema V1 tipu vozila:

| V1 `vehicle_type` ID | Cijena |
|----------------------|--------|
| 1 | 15,00 € |
| 2 | 20,00 € |
| 3 | 40,00 € |
| 4 | 50,00 € |

**`created_by_admin`:** postavljeno na **`0`** za sve prenesene redove — V1 nije imala pouzdan podatak da li je `free` rezervaciju kreirao korisnik ili admin.

**`daily_parking_data`:** ažurirana samo za datume koji **već postoje** u toj tabeli; istorijske rezervacije za prošle datume nisu uticale na kapacitet. Provjera `reserved > capacity` — **nijedan problem**.

**Privremene tabele** `v1_reservations` / `v1_vehicle_types`: preporučljivo **zadržati nekoliko dana** radi provjere i rollback mogućnosti, zatim ukloniti (v. `project-todo.md`).

### Seeder / referentni podaci

Zadržane seeder tabele (test podaci uklonjeni prije go-live):

`admins`, `roles`, `system_config`, `vehicle_types`, `vehicle_type_translations`, `list_of_time_slots`, `daily_parking_data`, `report_emails`, `ui_translations`

### Produkcijski `.env` (ključne vrijednosti)

```env
APP_NAME=KotorBus
APP_ENV=production
APP_DEBUG=false
APP_URL=https://bus.kotor.me
ASSET_URL=
```

Nakon izmjene `.env`:

```bash
php artisan config:clear
php artisan config:cache
php artisan route:cache
php artisan view:cache
php artisan queue:restart
```

### Runtime verzije (produkcija)

- **Laravel** 12.52.0
- **PHP** 8.3.31
- Frontend: `npm run build` → `/build/manifest.json`
- PDF: `barryvdh/laravel-dompdf`; QR generisanje i upis u bazu
- Fiskal: `FISCAL_SELLER_*` usklađeno sa produkcijskim sertifikatom (testirano pri puštanju)

### Composer / server cleanup

Nakon problema sa `Pdo\Mysql` uklonjeni dupli pogrešni folderi `config/config` i `vendor/vendor`.

---

## Pre deploy-a (.env checklist)

- [ ] **`.env` na serveru:** `APP_ENV=production`, `APP_DEBUG=false`, **`APP_URL`** = javni HTTPS URL (isti kao u browseru).
- [ ] **`BANK_DRIVER=bankart`**, **`FISCALIZATION_DRIVER=real`** (ne ostavljati `fake` u produkciji).
- [ ] Bankart: `BANKART_*` popunjeno; fiskal: `FISCAL_API_URL`, `FISCAL_API_TOKEN`, `FISCAL_ENU_IDENTIFIER`, `FISCAL_USER_CODE`, `FISCAL_USER_NAME`.
- [ ] **`QUEUE_CONNECTION=database`** (ili redis) — ne `sync` u produkciji za callback/fiskal/email.
- [ ] **`SESSION_SECURE_COOKIE=true`** ako je sajt isključivo preko HTTPS.
- [ ] Mail: `MAIL_*` / `MAIL_NOREPLY_*` za stvarni SMTP; testirati slanje na stagingu.
- [ ] Opciono: timeout env za Bankart/fiskal — vidi `config/http-outbound.php` (uključujući per-endpoint: `BANKART_CREATE_SESSION_*`, `FISCAL_DEPOSIT_*`, `FISCAL_RECEIPT_*`, budući `BANKART_STATUS_INQUIRY_*`).
- [ ] `PAYMENT_STALE_PENDING_WARN_AFTER_MINUTES` — prag za log `payment_pending_too_long` (default 12).

---

## Deploy komande (sa servera, iz roota aplikacije)

```bash
php artisan migrate --force
npm ci && npm run build
php artisan queue:restart
php artisan config:cache
php artisan route:cache
php artisan view:cache
php artisan event:cache
```

- [ ] **`storage/` i `bootstrap/cache/`** — vlasnik procesa koji pokreće PHP (npr. `www-data`) mora moći da piše (`storage/logs`, `framework/cache`, `framework/views`).

---

## Queue worker

**Produkcija (`bus.kotor.me`, folder `bus-v2.kotor.me`):**

- Plesk scheduled task **`bus-v2.kotor.me/queue-worker.php`**, cron **`* * * * *`**
- Na serveru: `queue:work` sa **`--stop-when-empty`** — worker se gasi kad isprazni red, što sprečava gomilanje paralelnih procesa
- Paralelno: **`bus-v2.kotor.me/schedule-run.php`** → `php artisan schedule:run`
- Pri **`APP_ENV=production`** aktivne su produkcione scheduler komande iz `bootstrap/app.php` (`reservations:process-pending`, `payment:check-pending-inquiry`, `post-fiscalization:retry`, …)

**Staging (`bus-v2.kotor.me`) / repozitorijum:**

- Root skripta **`queue-worker.php`** u repou koristi **`--max-time=55`** i **`--sleep=1`** **bez** `--stop-when-empty`, sa lock-om `plesk_queue_worker_bus_v2` — v. **`docs/cron-commands.md`** § Plesk fallback. Staging može koristiti istu skriptu iz repoa; produkcija je podešena drugačije prema opterećenju.

**Opšte:**

- [ ] Pokrenuti worker sa istim `APP_ENV` / `.env` kao web.
- [ ] Posle **deploy-a:** **`php artisan queue:restart`**
- [ ] Timeout workera ≥ **130s** (npr. `ProcessReservationAfterPaymentJob`)
- [ ] **Email `EMAIL_SENDING`:** ako job padne, `email_sent` se vraća na **`EMAIL_NOT_SENT`**

### Kako proveriti da worker radi

- Proces: `ps aux | grep "queue:work"` (ili lista servisa u systemd).
- Posle starta u **production** u `payments.log` treba **jednom** po procesu: **`queue_worker_booted`** (PID u kontekstu).
- Test job: npr. kratki test checkout sa queue ≠ `sync` i provera da se job obradi (`jobs` tabela se čisti ili `failed_jobs` ostaje prazno).

---

## Posle deploy-a (smoke)

- [ ] **`GET /up`** (health) — 200.
- [ ] Jedan test checkout (staging ili mali iznos): callback → rezervacija u bazi → `payments.log` bez greške.
- [ ] **`storage/logs/payments.log`** — proveriti `payment_reservation_created`, po potrebi `payment_fiscal_success` / `invoice_email_sent`.
- [ ] **Uputstvo za agencije (PDF):** `GET /docs/cgbuskotor.pdf` i `/docs/engbuskotor.pdf` vraćaju 200 (fajlovi u `public/docs/`, v. **`docs/agency-user-guide.md`**).

---

## Česti problemi

| Simptom | Provera |
|--------|---------|
| Mejl ne stiže | `QUEUE_CONNECTION`, da li worker radi; `invoice_sent_at` / log `invoice_email_*` |
| Fiskal ne prolazi | `post_fiscalization_data`, komanda `post-fiscalization:retry`, `payments.log` |
| Callback ne radi | URL banke → `POST /api/payment/callback`, potpis, `APP_URL` |

---

## Dokumentacija vezana za hardening

- **`docs/production-hardening.md`** — timeout/retry politika, „stuck“ scenariji, log eventi.

---

## Produkcija V2 — operativa (kontinuirano)

- [ ] Ukloniti privremene tabele `v1_reservations`, `v1_vehicle_types` nakon perioda provjere (nekoliko dana poslije cut-over-a) — v. `project-todo.md`
- [ ] Backup / rollback plan: V1 dostupan na `https://bus-v1.kotor.me` (folder `bus.kotor.me`, baza `opstinakotor_busnova`)
- [ ] Monitoring: `payments.log`, `failed_jobs`, `post_fiscalization_data`, `admin_alerts`

Detaljna operativna checklista: **`docs/production-readiness-and-disaster-recovery.md`**.
