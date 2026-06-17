# Project TODO (otvoreno)

**Poslednje ažuriranje:** 2026-06-17

Stavke su prioritetne grupe. Kada nešto **završiš**, premesti opis u `docs/project-done.md` i ukloni odavde.

---

## STAGING VALIDATION PHASE (`https://bus-v2.kotor.me`)

**Kontekst:** V2 je deployovan na staging na produkcijskom serveru (odvojena baza). V1 (`https://bus.kotor.me`) ostaje aktivna produkcija. Cut-over na V2 **nije** urađen — v. **`docs/production-runbook.md`** § Planirani cut-over.

### Infrastruktura i deploy (završeno)

- [x] Deploy aplikacije na **`https://bus-v2.kotor.me`** (odvojeno od V1).
- [x] Zasebna staging baza (nema dijeljenja podataka sa V1 produkcijom).
- [x] Bankart **simulaciono** test okruženje konfigurisano na serveru.
- [x] Fiskalizacija **simulaciono** test okruženje konfigurisano na serveru.
- [x] Plesk queue worker: **`queue-worker.php`** svake minute (bez `--stop-when-empty`, `--max-time=55`, lock).
- [x] Scroll restore na rezervacionim formama (guest + agency panel).
- [x] **Dnevna naknada / Daily fee** rollout (kod + staging deploy; v. `project-done.md` 2026-06).
- [x] **Limo QR workflow** ukinut kao operativni model; legacy kod očuvan (`LIMO_QR_WORKFLOW_ENABLED=false`).

### Preostale staging provjere (E2E na serveru)

- [ ] **Rezervacija — plaćanje:** guest i agency checkout (Termini + Dnevna naknada), callback, rezervacija u bazi, `payments.log`.
- [ ] **Avans — top-up:** `POST /panel/avans/topup` (ako je `ADVANCE_PAYMENTS_ENABLED=true`), povratak sa banke, ledger saldo.
- [ ] **Bankart simulacija:** pun tok (create session → redirect → callback → `PaymentCallbackJob`); `APP_URL` / return URL na `bus-v2.kotor.me`; HMAC callback (v. `payment-callback-handling.md`).
- [x] **Fiskalizacija simulacija:** uspješan `fiscalReceipt` + QR na stagingu (test seller/PIB); retry put preko `post-fiscalization:retry` po potrebi.
- [ ] **PDF generisanje:** plaćeni fiskalni/ne-fiskalni račun, besplatna potvrda, admin PDF-ovi — pregled u browseru/download.
- [ ] **Email isporuka:** potvrde rezervacije, računi, FZBR/admin obavijesti — stvarni SMTP na stagingu.
- [ ] **Besplatne rezervacije (FZBR):** podnošenje zahtjeva, admin fulfill/reject, povezane `free` rezervacije.
- [ ] **Control panel:** `GET/POST /control/dnevna-naknada` — lookup tablice za današnji `daily_ticket`.
- [ ] **Admin analitika:** `/admin/analitika` — KPI, PDF izvještaj, Termini vs Dnevna naknada.
- [ ] **Performanse / queue:** job obrada u roku ~1–2 s dok `queue-worker.php` radi; nema gomilanja u `jobs`; `failed_jobs` prazan poslije testova; scheduler (`schedule-run.php`) radi.

---

## Dnevna naknada (agency + guest)

- [x] **Phase 6 — guest UI + checkout:** `/guest/reserve` Termini / Dnevna naknada; kartica-only; validacija + testovi `GuestDailyFeeCheckoutTest` (v. `project-done.md` 2026-06-10).
- [x] **Phase 1 — šema/model:** `reservation_kind`, nullable slot FK, `ReservationKind` + testovi (v. `project-done.md` 2026-05-28).
- [x] **Phase 2 — agency UI + checkout:** izbor Termini / Dnevna naknada, validacija, checkout bez `daily_parking_data` za daily (v. `project-done.md` 2026-05-28).
- [x] **Phase 3A — PDF/email + panel liste:** fiskalni PDF, email, upcoming/realized lifecycle, admin prikaz (v. `project-done.md` 2026-05-28).
- [x] **Phase 3B — admin analitika:** odvojeni brojači/prihod Termini vs Dnevna naknada; zauzetost slotova samo time_slots (v. `project-done.md` 2026-05-28).
- [x] **Phase 5 — Promjena tablica:** rename menija/stranice; daily fee bez promjene tablice; testovi `PlateChangePageTest` (v. `project-done.md` 2026-06-10).
- [x] **Phase 4 — Termini bez limo putničkih kategorija (4+1–7+1):** `ReservationVehicleEligibilityService`; UI + `CheckoutReservationRequest`; historija/admin/PDF ne dirani (v. `project-done.md` 2026-06-10).
- [x] **Admin edit (dnevna naknada + termini):** izmena rezervacija u admin panelu; v. `project-done.md` 2026-05-28.

**Napomena:** Operativni sloj (zakazane komande, `alerts:system-health`, heartbeat keš, **Sistem status** u adminu, MEGA retry/arhiva, queue stale signalizacija) je **uvezen u kod i dokumentaciju** — v. `docs/admin-panel.md`, `docs/cron-commands.md`, `docs/production-readiness-and-disaster-recovery.md`. Ovaj fajl ne navodi ponovo te zadatke; fokus je na **preostalom** poslu, roadmapu i otvorenim poslovnim/ E2E pitanjima.

---

## Limo service

Stanje implementacije vs detalji: **[limo-service.md](./limo-service.md)**.

**Operativni model (2026-06):** agencije kupuju **dnevnu naknadu** kroz Rezervacije; terenska provjera tablice: **Control** `GET /control/dnevna-naknada`. QR/OCR/evidentičar (`/limo/*`, `/panel/limo/qr/*`) su **legacy** — isključeni po defaultu (`LIMO_QR_WORKFLOW_ENABLED=false`). Kod, tabele i admin istorija očuvani.

- [ ] **Incident workflow — šire:** statusi (reported/closed), administrativna rešenja, eventualno deduplikacija šire od `incident_uuid`; minimalni legacy tok (evidencija + email KP + `admin_alerts`) je urađen — v. `limo-service.md`.
- [ ] **PWA / instalabilni** shell (opciono) — legacy mobilni web na `GET /limo` dostupan samo uz `LIMO_QR_WORKFLOW_ENABLED=true`.
- [ ] **Native Android:** odluka nakon PWA field testa da li je potreban poseban klijent.

---

## 1. Produkcija / Bankart

- [ ] **Operativna verifikacija** callback potpisa i header-a sa **pravim** Bankart okruženjem na **hostovanom** domenu (HMAC je u kodu — `RealCallbackSignatureValidator`; v. `docs/payment-callback-handling.md`, `docs/payment-state-machine.md`; **staging** `https://bus-v2.kotor.me` — v. STAGING VALIDATION PHASE gore).
- [ ] **E2E** sa realnim callback scenarijima na **hostovanom** okruženju — v. `docs/project-status-next-steps.md` § Real E2E i staging checklist.

---

## 2. `late_success` automatska obrada

- [ ] Komanda `reservations:assign-late-success` je **stub** — definisati pravila: kada automatski kreirati rezervaciju iz `temp_data`, kada ostaviti incident / admin (`AssignLateSuccessReservations.php`).

---

## 3. Operativno / audit

*(Nadogradnja politika i rubnih slučajeva — **ne** podrazumijeva da monitoring/recovery nedostaje; v. gornju napomenu.)*

- [ ] Politika retencije `temp_data` i povezanih operativnih podataka (cleanup / audit granice).
- [ ] Dodatno fino podešavanje alerting politike (`admin_alerts`, severity, kanali, pragovi) po realnom produkcijskom iskustvu.
- [ ] Verifikacija lifecycle-a `temp_data` / `retry_token` u rubnim slučajevima.
- [ ] Gde trajno čuvati fiskalnu klasifikaciju za audit (bez nepotrebnog dupliranja podataka).

---

## 4. Fiskalni račun posle retry-a (ne žuriti implementaciju)

- [ ] Posle uspešne **naknadne fiskalizacije:** generisati **kompletan fiskalizovani PDF**, poslati korisniku; jasno razdvojiti od ranije poslatog **nefiskalnog** fallbacka; ne blokirati fiskal ako je nefiskal već poslat.

---

## 5. Tehničke optimizacije (opciono)

- [ ] Migracija **`createSession`** sa sync web zahtjeva na async init preko queue-a.

---

## 6. Future mobile platform readiness (plan / bez izmjena koda sada)

- [ ] **Android (agencije):** planirati Android aplikaciju za agency tokove (ulogovani `/panel`).
- [ ] **Android (admin + control):** planirati Android aplikaciju za admin panel i control funkcionalnosti.
- [ ] **Control panel:** plan je da control panel bude **samo na Android-u**.
- [ ] **iPhone (opciono):** eventualno samo za agencije i samo ako bude traženo (nije prioritet sada).
- [ ] **Prije mobile produkcije:** poseban audit backend-a (kontroleri/Blade/session/redirect) i identifikacija mjesta koja su previše web-specifična ili nisu API-friendly; definisati šta izdvojiti u servise/API tokove za mobile klijente.
- [ ] **Ograničenje ovog TODO-a:** u ovoj fazi **ne** mijenjati PHP kod, rute, validaciju, poslovnu logiku niti uvoditi API — samo plan i evidencija.
