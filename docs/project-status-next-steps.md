# Project status — indeks

**Poslednje ažuriranje:** 2026-06-19

**Završena tranzicija (2026-06):** Dnevna naknada / Daily fee (korisnički naziv), Limo QR workflow ukinut, Control provjera dnevne naknade, Termini bez limo putničkih kategorija 4+1–7+1, **Promjena tablica** (bivše Predstojeće rezervacije). Sažetak: `project-done.md` sekcija 2026-06.

**Produkcija V2 (2026-06-19):** **`https://bus.kotor.me`** (folder `bus-v2.kotor.me`, baza `bus`). V1 rezerva: **`https://bus-v1.kotor.me`**. Cut-over + migracija 21.342 rezervacija: **`production-runbook.md`**. Otvoreno: **`project-todo.md`**.

Ovaj fajl je **kratak smerač**. Detalji su podeljeni da bi novi chat mogao da učita samo ono što treba. Ako prilažeš samo folder **`docs/`**, počni od **[README.md](./README.md)** pa ovde.

**Canonical payment state machine (prelazi, terminalna stanja, invarijante):** [payment-state-machine.md](./payment-state-machine.md) — pročitaj **pre** izmene u `PaymentCallbackJob`, `TempData`, checkout/callback/inquiry toku.

**Princip:** dokumentacija treba da bude **izvor istine, ne zabune** — v. [project-conventions.md](./project-conventions.md) **§ 0**. Tematski fajlovi ispod moraju da prate kod; ako nešto ne štima, ispravi doc ili kod.

| Dokument | Svrha |
|----------|--------|
| **[README.md](./README.md)** | **Ulaz u `docs/`** za novi chat (`@docs`) — redosled čitanja + linkovi |
| **[handoff-new-chat.md](./handoff-new-chat.md)** | Tekst za **kopiranje u prvu poruku** novog Cursor chata + kako održavati liste + napomena o dugim sesijama |
| **[project-todo.md](./project-todo.md)** | Šta je **otvoreno** (čekiraj / briši kad završiš) |
| **[project-done.md](./project-done.md)** | Šta je **urađeno** (dodaj datum kad premestiš iz TODO) |
| **[project-conventions.md](./project-conventions.md)** | Pravila: prevodi, mail, queue, rute, parking |

**Radni tok:** završi zadatak → stavku iz `project-todo.md` prebaci u `project-done.md` (sa datumom) → ukloni iz TODO → po potrebi ažuriraj **tematski** dokument ispod (da ostane izvor istine sa kodom).

**Napomena (future plan):** planirana je mobile ekspanzija (Android za agencije i Android za admin/control; control panel samo Android; iPhone eventualno samo agencije). Detalji su u `docs/project-todo.md` (sekcija „Future mobile platform readiness“) — trenutno bez izmena koda.

---

## Brzi test (lokalno)

- `.env`: `BANK_DRIVER=fake`, `FISCALIZATION_DRIVER=fake`, **`QUEUE_CONNECTION=sync`** (ili `queue:work`).
- Detalji: [fake-payment-and-fiscal-qa-checklist.md](./fake-payment-and-fiscal-qa-checklist.md).
- Opciono — **puni suite na MySQL** (`phpunit.mysql.xml`, baza `*_test`, na Windowsu **`mysql.exe` u PATH**): [testing-mysql.md](./testing-mysql.md).

---

## Real Bankart E2E

**Produkcija (2026-06-19):** HMAC callback potpis i header-i verifikovani sa **pravim** Bankart okruženjem na hostovanom domenu — v. `project-done.md`.

**Staging (historija):** E2E validacija na **`https://bus-v2.kotor.me`** (simulacija, odvojena baza) završena prije produkcijskog starta.

**Status inquiry:** implementiran je **GET** `getByMerchantTransactionId` (`RealPaymentStatusInquiryService`) → **`PaymentCallbackJob`**. U produkciji proveriti da **HMAC za GET** (prazno telo / `Content-Type` u potpisu) odgovara Bankart „Signature testing“ alatu ako gateway odbije zahtev.

---

## Ostala dokumentacija (dublje u teme)

Ovi fajlovi treba da prate trenutni kod; v. `project-conventions.md` § 8.

| Oblast | Fajl |
|--------|------|
| **Payment state machine (canonical)** | **[payment-state-machine.md](./payment-state-machine.md)** |
| Callback, idempotentnost, return/result | [payment-callback-handling.md](./payment-callback-handling.md) |
| Arhitektura plaćanja, queue | [payment-architecture.md](./payment-architecture.md) |
| Konkurentnost | [payment-concurrency.md](./payment-concurrency.md) |
| Stanja plaćanja / fiskal | [payment-states.md](./payment-states.md) |
| temp_data workflow | [workflow-placanje-temp-data.md](./workflow-placanje-temp-data.md) |
| Uspešan tok (job, PDF) | [success-payment-pipeline.md](./success-payment-pipeline.md) |
| Jezik, PDF, callback | [language-and-invoice-rules.md](./language-and-invoice-rules.md) |
| Auth vs guest | [auth-and-guests.md](./auth-and-guests.md) |
| Agency panel (rezervacije, Promjena tablica, realized, user) | [agency-panel.md](./agency-panel.md) |
| Korisničko uputstvo agencija (CG+EN) | [agency-user-guide.txt](./agency-user-guide.txt) |
| Limo service (legacy QR + informativna stranica) | [limo-service.md](./limo-service.md) |
| Control panel (dolasci, **dnevna naknada**, guard `control`) | [control-panel.md](./control-panel.md) |
| Admin (spec + šta je urađeno) | [admin-panel.md](./admin-panel.md) |
| Cron detalji | [cron-commands.md](./cron-commands.md) |
| Deploy / produkcija (checklist) | [production-runbook.md](./production-runbook.md) |
| Timeout, queue, log eventi, stuck | [production-hardening.md](./production-hardening.md) |
| Fake vs real | [fake-vs-real-contract-parity.md](./fake-vs-real-contract-parity.md) |
| Raspored scheduler-a | [scheduled-tasks-overview.md](./scheduled-tasks-overview.md) |
| Ručni QA payment | [payment-manual-qa-checklist.md](./payment-manual-qa-checklist.md) |
| Fake QA | [fake-payment-and-fiscal-qa-checklist.md](./fake-payment-and-fiscal-qa-checklist.md) |
| PHPUnit na MySQL (opciono, Laragon) | [testing-mysql.md](./testing-mysql.md) |

Još: [payment-v1-production-audit.md](./payment-v1-production-audit.md), [eloquent-relationships.md](./eloquent-relationships.md), [provera-modela.md](./provera-modela.md).
