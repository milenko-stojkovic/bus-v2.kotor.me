# Handoff za novi Cursor chat

**Svrha:** U novom tabu nema istorije ovog četa. Ovaj fajl daje **tekst koji možeš zalepiti** kao prvu poruku, plus uputstvo kako držati **TODO / DONE** u dokumentaciji.

**Ulazna tačka foldera:** ako u novom chatu samo priložiš dokumentaciju, koristi **`docs/README.md`** (kratak indeks) ili ceo folder **`docs/`** (`@docs` u Cursoru).

---

## Trenutno stanje projekta (2026-06-19)

| Okruženje | URL | Folder / baza |
|-----------|-----|---------------|
| **V2 produkcija** | `https://bus.kotor.me` | `bus-v2.kotor.me` → docroot `…/public`; MySQL **`bus`** |
| **V1 rezerva** | `https://bus-v1.kotor.me` | stari folder **`bus.kotor.me`**; MySQL **`opstinakotor_busnova`** |
| **V2 staging** | `https://bus-v2.kotor.me` | E2E validacija završena; odvojena baza |
| **Lokalno** | npr. `*.test` | Laragon, PHPUnit |

- **Cut-over završen** — 21.342 rezervacija migrirana iz V1; detalji: `docs/production-runbook.md`, `docs/project-done.md`.
- **Dokumentacija = izvor istine:** produkcijski audit docs↔kod završen **2026-06-19** (`project-done.md`); canonical payment: `payment-state-machine.md`.
- **Otvoreno:** `docs/project-todo.md` (`late_success`, operativni audit, fiskalni PDF poslije retry-a, mobile plan…).

### Nedavno u `main` (2026-06-19, detalji u `project-done.md` § Admin / UX)

- **Admin Uvid → Avansna uplata** — `/admin/uvid/avans` (`agency_advance_topups` + payments log); tab pored Uvida za rezervacije.
- **PDF uputstvo za agencije** — `public/docs/cgbuskotor.pdf` (CG), `engbuskotor.pdf` (EN); v. **`docs/agency-user-guide.md`**; link na landing-u i u panelu (`config/user-guides.php`).
- **`temp_data` pending** — istek nakon **5 min** (`RESERVATIONS_PENDING_EXPIRE_MINUTES`, cron svakih 5 min).
- Admin dashboard: kartice **dnevne naknade** danas/sutra; Control lista: **Ukupno vozila**; heuristička pretraga **agencija**; uppercase **tablica** u admin pretrazi rezervacija.

### Queue / scheduler (produkcija)

Plesk cron na **`bus-v2.kotor.me/`**:

- **`schedule-run.php`** → `schedule:run`
- **`queue-worker.php`** → `queue:work --stop-when-empty` (sprečava gomilanje worker procesa)

Repozitorijumska `queue-worker.php` za staging koristi drugačiju politiku (`--max-time=55`, bez `--stop-when-empty`) — v. `cron-commands.md`.

### Laragon / `php artisan` (Windows — obavezno za agente)

U Cursor terminalu **`php` često nije u PATH-u**; PowerShell starije verzije **ne podržavaju** `&&` za lančanje komandi.

- **Ne koristiti** gol `php artisan ...` osim ako je `php` eksplicitno u PATH-u.
- **Koristiti** iz korena repoa: **`.\laragon-artisan.cmd <arg>...`** (preferirano — radi i uz strogu Execution Policy) ili **`.\laragon-artisan.ps1 <arg>...`**.
- Primjeri: `.\laragon-artisan.cmd test`, `.\laragon-artisan.cmd migrate`, `.\laragon-artisan.cmd queue:work --tries=1`.
- Sintaksa fajla: **`.\laragon-php.cmd -l putanja\do\fajla.php`** (ne gol `php -l`).
- Lančanje u PS: `Set-Location c:\laragon\www\bus.kotor.me; .\laragon-artisan.cmd test` (koristi **`;`**, ne `&&`).

Detalji, Execution Policy i MySQL test suite: **`docs/project-conventions.md` § 3**.

---

## 1) Tekst za prvu poruku u novom chatu (kopiraj–nalepi)

Prilagodi putanju ako radni folder nije isti.

```
Radiš na Laravel 12 (PHP 8.3) projektu bus.kotor.me (rezervacije autobusa, plaćanje, fiskalizacija).

Okruženja: V2 produkcija https://bus.kotor.me (folder bus-v2.kotor.me, baza bus) | V1 rezerva https://bus-v1.kotor.me (folder bus.kotor.me) | staging https://bus-v2.kotor.me | lokalno Laragon.

Možeš priložiti folder docs/ (@docs) ili fajlove pojedinačno. Kratak indeks: docs/README.md

Obavezno prvo pročitaj (redosled):
- docs/handoff-new-chat.md (ovaj fajl — kontekst)
- docs/project-todo.md (šta je otvoreno)
- docs/project-done.md (šta je već urađeno — ne ponavljaj)
- docs/project-conventions.md (pravila + §0: dokumentacija kao izvor istine, ne zabune; §3: Laragon — .\laragon-artisan.cmd, ne php artisan u Cursoru)

Kratki indeks svih docs: docs/project-status-next-steps.md

Ako diraš payment / temp_data / callback / inquiry / late_success: prvo docs/payment-state-machine.md (canonical invarijanti + tabela prelaza), pa ostali payment docs.

Pravilo ažuriranja: kad nešto završiš, premesti stavku iz docs/project-todo.md u docs/project-done.md (sa datumom). Ne ostavljaj zastarele TODO stavke.
Ako promena dira domen (payment, fiskal, auth), ažuriraj i odgovarajući tematski fajl u docs/ (v. docs/project-status-next-steps.md i §3 u handoff-new-chat.md). Za payment state machine ažuriraj docs/payment-state-machine.md ako si promenio pravila u kodu.

Future plan (bez izmene koda sada): postoji TODO stavka „Future mobile platform readiness“ (Android agencije; Android admin/control; control panel samo Android; iPhone eventualno samo agencije). Pre mobile produkcije planiran je poseban audit backend-a za mobile/API-friendly upotrebu.

Trenutni zadatak: [OVDE NAPIŠI ŠTA RADIŠ]
```

Zameni poslednji red konkretnim zadatkom (npr. „implementirati Bankart inquiry“).

---

## 2) Kako održavati TODO i DONE

| Akcija | Gde |
|--------|-----|
| Novi otvoreni posao | Dodaj stavku u `docs/project-todo.md` (prioritet / blok). |
| Završen posao | **Izbriši** iz `project-todo.md`, dodaj u `docs/project-done.md` sa datumom `YYYY-MM-DD` i jednom rečenicom šta je urađeno. |
| Veći milestone | U `project-done.md` možeš dodati podnaslov po mesecu ili temi. |
| Konvencije / pravila | Ažuriraj `docs/project-conventions.md`, ne širi chat. |
| Istorija odluke u istom doc-u („bilo → sada“) | Dozvoljeno po **`project-conventions.md` § 0.1** — uvek jasno označiti šta je **važeće**. |

**Zašto odvojeno od chat-a:** istorija četa se gubi; trajni opis treba da živi u `docs/`.

**Princip:** dokumentacija mora biti **izvor istine, ne zabune** — v. **`docs/project-conventions.md` § 0**. Fajlovi u `docs/` važe kao istina samo dok su **usklađeni sa kodom**; kad odkriješ kontradikciju, popravi doc ili kod, ne ostavljaj oba.

---

## 3) Dublja dokumentacija u `docs/` (domeni)

Tematski fajlovi (payment-states, callback, concurrency, cron, auth/guest, admin spec, itd.) **mora** da prate kod — v. `project-conventions.md` § 0 i § 8. Pri zatvaranju stavke iz `project-todo.md` proveri da li treba ažurirati pogođeni tematski `.md`. Indeks: `docs/project-status-next-steps.md` → „Ostala dokumentacija“.

---

## 4) Gde su detalji (bez dupliranja u chat)

| Tema | Dokument |
|------|----------|
| QA fake plaćanje / fiskal | `docs/fake-payment-and-fiscal-qa-checklist.md` |
| Konvencije (prevod, mail, queue) | `docs/project-conventions.md` |
| **Laragon / artisan (Windows)** | `docs/project-conventions.md` **§ 3** — `.\laragon-artisan.cmd`, ne `php artisan` |
| Usklađenost fake vs real API | `docs/fake-vs-real-contract-parity.md` |
| Zakazani zadaci | `docs/scheduled-tasks-overview.md` |
| Payment / temp_data tok | **`docs/payment-state-machine.md` (canonical)**, `docs/payment-states.md`, `docs/workflow-placanje-temp-data.md` |
| Admin | `docs/admin-panel.md` |

---

## 5) Cursor — sporiji odgovori posle dugog četa (opciono)

Duga jedna sesija (mnogo alata, terminal, kontekst) često **poveća potrošnju RAM-a** i odgovori mogu delovati **sporiji**. To **nije kvar**; često se posle **novog chata** ili restarta Cursora oseća **svježije** — normalno za Electron + veliki kontekst.

**Šta ne gubiš:** kod i git istorija su u repou; **šta radiš kao zadatak** treba da stoji u **`docs/project-todo.md`** / **`docs/project-done.md`**, ne u četu.

**Pre prelaska u novi chat:** sačuvaj izmene (commit ili bar jasno stanje na disku), eventualno dopuni TODO jednom rečenicom šta sledi.

---

Poslednje ažuriranje ovog fajla: 2026-06-19 (Uvid avans, user guide PDF, pending expire 5 min, admin/control UX, Laragon §3 upozorenje za agente)
