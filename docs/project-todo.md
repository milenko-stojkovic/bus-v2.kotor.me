# Project TODO (otvoreno)

**Poslednje ažuriranje:** 2026-07-01

Stavke su prioritetne grupe. Kada nešto **završiš**, premesti opis u `docs/project-done.md` i ukloni odavde.

**Produkcija V2 je u hodu** na `https://bus.kotor.me` (folder `bus-v2.kotor.me`, baza `bus`). V1 rezerva: `https://bus-v1.kotor.me`. Cut-over rezime: `production-runbook.md`. Ovaj fajl sadrži **samo preostalo**.

---

## 1. Operativno / audit — post-production hardening

Ove stavke **nisu blokada za produkciju**. Predviđene su za **doradu nakon realnog rada V2** — formalne politike, audit granice i fino podešavanje po iskustvu, ne za prvi deploy baseline-a.

**Baseline hardening već u produkciji (2026-06 — 2026-07, v. `project-done.md`):** scheduler/queue **watchdog** heartbeat + **Sistem status**; `alerts:system-health` i operativni alert tipovi; Bankart callback **200 OK** + duplicate processed ACK; **checkout pending** reuse (`payment_redirect_url`); payment init failure → `canceled`; email pipeline audit/resend (`mail:audit-reservation-documents`, `mail:resend-reservation-document`); post-fiskalizacija retry + info alert **`post_fiscalization_started`**; recovery (`failed_jobs`, MEGA retry, fiskal retry); payment/fiskal tok; **`late_success`** staff workflow.

**Ovdje ostaje:**

- [ ] Politika retencije `temp_data` i povezanih operativnih podataka (cleanup / audit granice; fizičko brisanje starih ne-pending redova vs trajni audit trail).
- [ ] Dodatno fino podešavanje alerting politike (`admin_alerts`, severity, kanali, pragovi) po realnom produkcijskom iskustvu — iznad postojećeg baseline-a (watchdog, queue stale confirmation, dnevni rollup).
- [ ] Preostali audit lifecycle-a `temp_data` / `retry_token` u rubnim slučajevima (npr. `late_success`, dugoročna retencija, usklađivanje `RESERVATIONS_PENDING_EXPIRE_MINUTES` sa produkcijskom praksom 15–30 min). Osnovni rubni slučajevi su adresirani (init failure, duplicate callback, pending redirect reuse, `expire-pending`).
- [ ] Gde trajno čuvati fiskalnu klasifikaciju za audit (bez nepotrebnog dupliranja podataka).

---

## 2. Tehničke optimizacije (opciono)

- [ ] Migracija **`createSession`** sa sync web zahtjeva na async init preko queue-a.

---

## 3. Future mobile platform readiness (plan / bez izmjena koda sada)

- [ ] **Android (agencije):** planirati Android aplikaciju za agency tokove (ulogovani `/panel`).
- [ ] **Android (admin + control):** planirati Android aplikaciju za admin panel i control funkcionalnosti.
- [ ] **Control panel:** plan je da control panel bude **samo na Android-u**.
- [ ] **iPhone (opciono):** eventualno samo za agencije i samo ako bude traženo (nije prioritet sada).
- [ ] **Prije mobile produkcije:** poseban audit backend-a (kontroleri/Blade/session/redirect) i identifikacija mjesta koja su previše web-specifična ili nisu API-friendly; definisati šta izdvojiti u servise/API tokove za mobile klijente.
- [ ] **Ograničenje ovog TODO-a:** u ovoj fazi **ne** mijenjati PHP kod, rute, validaciju, poslovnu logiku niti uvoditi API — samo plan i evidencija.
