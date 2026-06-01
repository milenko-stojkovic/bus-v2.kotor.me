# Project TODO (otvoreno)

**Poslednje ažuriranje:** 2026-05-28

Stavke su prioritetne grupe. Kada nešto **završiš**, premesti opis u `docs/project-done.md` i ukloni odavde.

---

## Dnevna karta (agency)

- [x] **Phase 1 — šema/model:** `reservation_kind`, nullable slot FK, `ReservationKind` + testovi (v. `project-done.md` 2026-05-28).
- [x] **Phase 2 — agency UI + checkout:** izbor Termini / Dnevna karta, validacija, checkout bez `daily_parking_data` za daily (v. `project-done.md` 2026-05-28).
- [x] **Phase 3A — PDF/email + panel liste:** fiskalni PDF, email, upcoming/realized lifecycle, admin prikaz (v. `project-done.md` 2026-05-28).
- [x] **Phase 3B — admin analitika:** odvojeni brojači/prihod Termini vs Dnevna karta; zauzetost slotova samo time_slots (v. `project-done.md` 2026-05-28).
- [x] **Phase 5 — admin edit:** izmena rezervacija u admin panelu (termini + dnevna karta); v. `project-done.md` 2026-05-28.

**Napomena:** Operativni sloj (zakazane komande, `alerts:system-health`, heartbeat keš, **Sistem status** u adminu, MEGA retry/arhiva, queue stale signalizacija) je **uvezen u kod i dokumentaciju** — v. `docs/admin-panel.md`, `docs/cron-commands.md`, `docs/production-readiness-and-disaster-recovery.md`. Ovaj fajl ne navodi ponovo te zadatke; fokus je na **preostalom** poslu, roadmapu i otvorenim poslovnim/ E2E pitanjima.

---

## Limo service

Stanje implementacije vs detalji: **[limo-service.md](./limo-service.md)**.

Osnovni operativni tok, OCR, eksterna arhiva relevantnih dokaza i minimalni incident workflow su pokriveni u produkcijskom kodu; **preostaje širenje** UX-a, procesa i eventualno deduplikacije/štireg admin toka po realnom terenskom iskustvu.

- [ ] **Incident workflow — šire:** statusi (reported/closed), administrativna rešenja, eventualno deduplikacija šire od `incident_uuid`; minimalni tok (evidencija + email KP + `admin_alerts`) je urađen — v. `limo-service.md`.
- [ ] **PWA / instalabilni** shell i napredniji terenski UX (osnovni mobilni web na `GET /limo` postoji).
- [ ] **Native Android:** odluka nakon PWA field testa da li je potreban poseban klijent.

---

## 1. Produkcija / Bankart

- [ ] **Operativna verifikacija** callback potpisa i header-a sa **pravim** Bankart okruženjem na **hostovanom** domenu (HMAC je u kodu — `RealCallbackSignatureValidator`; v. `docs/payment-callback-handling.md`, `docs/payment-state-machine.md`; ovde ostaje E2E / bankin režim).
- [ ] **E2E** sa realnim callback scenarijima na **hostovanom** okruženju — v. `docs/project-status-next-steps.md` § Real E2E.

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
