# Project TODO (otvoreno)

**Poslednje ažuriranje:** 2026-06-19

Stavke su prioritetne grupe. Kada nešto **završiš**, premesti opis u `docs/project-done.md` i ukloni odavde.

**Produkcija V2 je u hodu** na `https://bus.kotor.me` (folder `bus-v2.kotor.me`, baza `bus`). V1 rezerva: `https://bus-v1.kotor.me`. Cut-over rezime: `production-runbook.md`. Ovaj fajl sadrži **samo preostalo**.

---

## 1. Operativno / audit — post-production hardening

Ove stavke **nisu blokada za produkciju**. Predviđene su za **doradu nakon prvih dana ili sedmica** realnog rada V2, kada bude jasno šta predstavlja normalan produkcijski šum, koje operativne podatke treba trajno čuvati, a koje arhivirati ili uklanjati.

**Već postoji u produkciji (ne čeka ove stavke):** monitoring i alerti (`admin-panel.md`, `alerts:system-health`), recovery (`failed_jobs`, MEGA retry, fiskal retry), scheduler i queue, payment/fiskal tok, **`late_success`** staff workflow. Ovdje su samo **operativno fino podešavanje** i **production hardening** po iskustvu.

- [ ] Politika retencije `temp_data` i povezanih operativnih podataka (cleanup / audit granice).
- [ ] Dodatno fino podešavanje alerting politike (`admin_alerts`, severity, kanali, pragovi) po realnom produkcijskom iskustvu.
- [ ] Verifikacija lifecycle-a `temp_data` / `retry_token` u rubnim slučajevima.
- [ ] Gde trajno čuvati fiskalnu klasifikaciju za audit (bez nepotrebnog dupliranja podataka).

---

## 2. Fiskalni račun posle retry-a (ne žuriti implementaciju)

- [ ] Posle uspešne **naknadne fiskalizacije:** generisati **kompletan fiskalizovani PDF**, poslati korisniku; jasno razdvojiti od ranije poslatog **nefiskalnog** fallbacka; ne blokirati fiskal ako je nefiskal već poslat.

---

## 3. Tehničke optimizacije (opciono)

- [ ] Migracija **`createSession`** sa sync web zahtjeva na async init preko queue-a.

---

## 4. Future mobile platform readiness (plan / bez izmjena koda sada)

- [ ] **Android (agencije):** planirati Android aplikaciju za agency tokove (ulogovani `/panel`).
- [ ] **Android (admin + control):** planirati Android aplikaciju za admin panel i control funkcionalnosti.
- [ ] **Control panel:** plan je da control panel bude **samo na Android-u**.
- [ ] **iPhone (opciono):** eventualno samo za agencije i samo ako bude traženo (nije prioritet sada).
- [ ] **Prije mobile produkcije:** poseban audit backend-a (kontroleri/Blade/session/redirect) i identifikacija mjesta koja su previše web-specifična ili nisu API-friendly; definisati šta izdvojiti u servise/API tokove za mobile klijente.
- [ ] **Ograničenje ovog TODO-a:** u ovoj fazi **ne** mijenjati PHP kod, rute, validaciju, poslovnu logiku niti uvoditi API — samo plan i evidencija.
