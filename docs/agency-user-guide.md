# Korisničko uputstvo za agencije (PDF)

**Poslednje ažuriranje:** 2026-06-19

Javno uputstvo za agencije (rezervacije, dnevna naknada, panel, avans, FZBR, itd.) distribuira se kao **PDF**, ne kao tekst u `docs/`.

---

## Fajlovi (izvor istine u repou)

| Jezik | Fajl na disku | Javni URL (produkcija) |
|-------|---------------|-------------------------|
| **Crnogorski (CG)** | `public/docs/cgbuskotor.pdf` | `https://bus.kotor.me/docs/cgbuskotor.pdf` |
| **Engleski (EN)** | `public/docs/engbuskotor.pdf` | `https://bus.kotor.me/docs/engbuskotor.pdf` |

- **Poreklo:** PDF-ovi su napravljeni na osnovu bivšeg `docs/agency-user-guide.txt` (uklonjen **2026-06-19** jer više nije potreban kao radni format).
- **Deploy:** fajlovi su u **git repou** — na Plesku stižu sa `git pull` (nema posebnog ručnog uploada osim ako se PDF mijenja izvan repoa).
- **Ažuriranje sadržaja:** zamijeni odgovarajući PDF u `public/docs/`, commit + push; link u aplikaciji ostaje isti.

---

## Integracija u aplikaciji

| Dio | Detalj |
|-----|--------|
| **Konfiguracija** | `config/user-guides.php` — relativne putanje `docs/cgbuskotor.pdf` / `docs/engbuskotor.pdf` (ispod `public/`) |
| **Partial** | `resources/views/partials/user-guide-pdf-link.blade.php` |
| **Prikaz linka** | Samo ako PDF **postoji** na disku (`is_file(public_path(...))`) |
| **Landing / gost** | `resources/views/layouts/guest.blade.php` |
| **Agencijski panel** | `resources/views/partials/nav-locale-icons.blade.php` (pored jezičkih zastavica) |
| **UI label** | `UiText` ključ `user_guide_pdf` (grupe `landing` / `panel`) |

Jezik linka prati `app()->getLocale()` (`cg` → CG PDF, inače EN).

---

## Povezana dokumentacija

- Panel (funkcionalnost): **[agency-panel.md](./agency-panel.md)**
- Testovi: `tests/Feature/Landing/UserGuidePdfLinkTest.php`

---

## Napomena za developere / AI

Ne tražiti `docs/agency-user-guide.txt` — uklonjen. Za sadržaj uputstva koristiti **PDF** iz `public/docs/` ili opis funkcija u `agency-panel.md` / `auth-and-guests.md`.
