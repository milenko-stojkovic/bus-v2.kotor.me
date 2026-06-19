# Dokumentacija (`docs/`)

**Namena:** Jedan folder kao izvor istine za projekat **bus.kotor.me** (Laravel 12, rezervacije, plaćanje, fiskal, admin, Limo, itd.). U **novom Cursor chatu** možeš priložiti ovaj folder (`@docs`) ili pojedinačne fajlove — agent nema istoriju prethodnog četa.

---

## Brzi start (redosled)

1. **[handoff-new-chat.md](./handoff-new-chat.md)** — šablon prve poruke, TODO/DONE pravilo, napomena o dugim sesijama Cursora.
2. **[project-todo.md](./project-todo.md)** — šta je otvoreno.
3. **[project-done.md](./project-done.md)** — šta je završeno (ne ponavljaj).
4. **[project-conventions.md](./project-conventions.md)** — konvencije, queue, `laragon-artisan`, §0 (dok = istina).

**Mapa svih fajlova:** [project-status-next-steps.md](./project-status-next-steps.md).

---

## Česti domeni (samo ako treba)

| Tema | Fajl |
|------|------|
| Payment / stanja / callback | [payment-state-machine.md](./payment-state-machine.md) (canonical), ostalo v. mapu iznad |
| Admin panel | [admin-panel.md](./admin-panel.md) (Uvid: rezervacije §8.1 + avans §8.2) |
| Limo (stanje u kodu) | [limo-service.md](./limo-service.md) |
| Agencijski panel | [agency-panel.md](./agency-panel.md) |
| Korisničko uputstvo za agencije (CG+EN) | [agency-user-guide.txt](./agency-user-guide.txt) |
| Šalter / dolasci | [control-panel.md](./control-panel.md) |
| Zakazani zadaci | [scheduled-tasks-overview.md](./scheduled-tasks-overview.md) |
| Operativna spremnost, backup, DR | [production-readiness-and-disaster-recovery.md](./production-readiness-and-disaster-recovery.md) |
| Testovi uz MySQL (opciono) | [testing-mysql.md](./testing-mysql.md) |

---

## Novi chat nakon dugog rada

Duga sesija u Cursoru često poveća memoriju i može usporiti odgovore. **Novi chat** je normalan korak; kontekst za zadatak preuzmi iz ovog foldera (`handoff` + TODO/DONE), ne iz starog četa.

**Operativni model (2026-06):** Agencije kupuju **Dnevnu naknadu** kroz Rezervacije; terenska provjera tablice: Control **`/control/dnevna-naknada`** (dnevna naknada ili Termini za danas). Limo QR/OCR/evidentičar su legacy (isključeni po defaultu). V. `project-done.md` (2026-06).

**Produkcija V2 (2026-06-19):** **`https://bus.kotor.me`** (app `bus-v2.kotor.me`, baza `bus`). V1 rezerva: **`https://bus-v1.kotor.me`**. Cut-over detalji: `production-runbook.md`, `project-done.md`.

**Poslednje ažuriranje:** 2026-06-19
