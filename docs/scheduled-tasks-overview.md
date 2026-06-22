# Scheduled Tasks Overview

Pregled svih trenutno planiranih (scheduled) taskova u projektu.

## Gde je scheduler definisan

- **Local SAFE schedule**: `routes/console.php` (komande koje ne kontaktiraju stvarnu banku/fiskalni servis)
- **Production-only schedule**: `bootstrap/app.php` (`withSchedule(...)`) — bank/fiscal komande su namerno pod `app()->environment('production')`

## Kako pokrenuti scheduler

- Jednokratno izvršenje due taskova:
  - `php artisan schedule:run`
- Kontinuirano lokalno:
  - `php artisan schedule:work`
- Pregled rasporeda:
  - `php artisan schedule:list`

## Aktivni scheduled taskovi

### Local SAFE (Laragon/Cronical)

| Command | Schedule | Command file | Kratko |
|---|---|---|---|
| `reservations:expire-pending` | every 5 minutes | `app/Console/Commands/ExpirePendingReservations.php` | Pending -> expired, oslobađa soft lock (prag default 5 min) |
| `parking:sync-days` | daily at 00:05 | `app/Console/Commands/SyncDailyParkingDays.php` | Sinhronizuje redove `daily_parking_data` za današnji dan + narednih 90 dana; briše stare datume |
| `limo:cleanup-temporary-data` | daily at 00:10 | `app/Console/Commands/LimoCleanupTemporaryData.php` | Briše stare nekorišćene `limo_qr_tokens` i istekle nekonzumirane `limo_plate_uploads` (+ privremeni fajlovi); **ne** briše dokaze u `limo_pickup_evidence/` |
| `files:cleanup-preview-cache` | daily at 00:15 | `app/Console/Commands/CleanupArchivePreviewCacheCommand.php` | Briše istekle privremene MEGA preview fajlove (`external_file_archives` ostaje `uploaded`) |
| `files:archive-private …` | every 6 hours | `app/Console/Commands/ArchivePrivateFilesCommand.php` | `--source=all --limit=50 --require-mega-health`; MEGA dijagnostika mora proći prije arhive; `withoutOverlapping(360)`; summary/skip logovi na `payments` |
| `temp-data:cleanup` | daily | `app/Console/Commands/CleanupOldTempData.php` | Briše samo stare **ne-pending** redove po retention pravilu (default 180 dana) |
| `advance:send-yearly-statements` | yearly on Jan 1 at 10:00 | `routes/console.php` | “Kartica avansa” (prethodna godina); idempotentno; feature-guard |
| `reports:send-scheduled daily` | daily at 07:00 | `app/Console/Commands/SendScheduledAdminReports.php` | Zakazani admin PDF izvještaji (po uplati, po tipu vozila, + obaveze po avansu kada je enabled) |
| `alerts:system-health` | daily at 07:30 | `app/Console/Commands/AlertsSystemHealthCommand.php` | Minimalno operativno zdravlje → `admin_alerts` (queue backlog, fake config u produkciji, dnevni rollup: failed jobs / arhive / MEGA / post_fiscalization); dedupe u servisu |
| `reports:send-scheduled monthly` | monthly on day 1 at 07:05 | `app/Console/Commands/SendScheduledAdminReports.php` | Zakazani admin PDF izvještaji za prethodni mjesec |
| `reports:send-scheduled yearly` | yearly on Jan 1 at 07:10 | `app/Console/Commands/SendScheduledAdminReports.php` | Zakazani admin PDF izvještaji za prethodnu godinu |

### Production-only (bank/fiscal)

Ovi taskovi su **namerno zakazani samo u produkciji** (da se lokalno izbegnu realne finansijske/fiskalne radnje):

| Command | Schedule | Command file | Kratko |
|---|---|---|---|
| `reservations:process-pending` | every 5 minutes | `app/Console/Commands/ProcessPendingReservations.php` | **No-op (stub):** samo broji pending `temp_data`, **ne** mijenja DB / fiskal / rezervacije (v. **`docs/cron-commands.md`** §1). Zakazano samo u produkciji (`bootstrap/app.php`). |
| `payment:check-pending-inquiry` | every 1 minute | `app/Console/Commands/CheckPendingPaymentStatus.php` | Bank inquiry (Bankart) → `PaymentCallbackJob` |
| `post-fiscalization:retry` | every 10 minutes | `app/Console/Commands/RetryPostFiscalization.php` | Retry stvarne fiskalizacije |

**VAŽNO (pre produkcije):** ove komande moraju biti operativno proverene i kompletno konfigurisanih env/kredencijala. Vidi `docs/cron-commands.md` → **“Production readiness (bank/fiscal)”**.

## Ručno pokretanje komandi

Možeš ih pokrenuti pojedinačno. Na **Windowsu** u Cursor terminalu često **`php` nije u PATH-u** — koristi iz korena repoa **`.\laragon-artisan.cmd <komanda>`** (v. **`docs/project-conventions.md`** §3).

**Napomena:** komande **`parking:update-availability`** i **`reservations:send-emails`** **nisu** u Laravel `Schedule` u repozitorijumu — ovde su navedene samo za ručno pokretanje / eventualni spoljni cron.

- `php artisan reservations:process-pending`
- `php artisan payment:check-pending-inquiry`
- `php artisan post-fiscalization:retry`
- `php artisan reservations:expire-pending`
- `php artisan reservations:assign-late-success`
- `php artisan parking:sync-days`
- `php artisan parking:update-availability`
- `php artisan reservations:send-emails`
- `php artisan temp-data:cleanup`
- `php artisan limo:cleanup-temporary-data`
- `php artisan advance:send-yearly-statements`

## Napomena za late_success

- **`reservations:assign-late-success`** je **namjerno no-op stub** — ne kreira automatski rezervaciju.
- **`late_success`** redovi ostaju u **`temp_data`** za **ručni** staff pregled: **`/staff/late-success`** (force / reject). V. **`payment-state-machine.md`** §4b, **`payment-states.md`**.

## Dodatno (nije scheduled task)

- `routes/console.php` sadrži i **local safe** schedule + “advance yearly statement” komandu.
