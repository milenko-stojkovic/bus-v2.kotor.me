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
| Admin panel | [admin-panel.md](./admin-panel.md) |
| Limo (stanje u kodu) | [limo-service.md](./limo-service.md) |
| Agencijski panel | [agency-panel.md](./agency-panel.md) |
| Šalter / dolasci | [control-panel.md](./control-panel.md) |
| Zakazani zadaci | [scheduled-tasks-overview.md](./scheduled-tasks-overview.md) |

---

## Novi chat nakon dugog rada

Duga sesija u Cursoru često poveća memoriju i može usporiti odgovore. **Novi chat** je normalan korak; kontekst za zadatak preuzmi iz ovog foldera (`handoff` + TODO/DONE), ne iz starog četa.

**Poslednje ažuriranje:** 2026-05-13
