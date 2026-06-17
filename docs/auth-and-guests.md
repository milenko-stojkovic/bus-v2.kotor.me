# Autentifikovani vs. guest korisnici

**Konvencije (prevodi, mail, panel):** `docs/project-conventions.md`.

## User model (autentifikovani)

- Autentifikovani korisnici imaju red u tabeli `users`.
- Važe svi standardni Laravel auth mehanizmi: registracija, verifikacija e-mail-a, reset lozinke, remember token itd.
- User je povezan sa **Reservation** i **Vehicle** preko `user_id` (nullable u rezervacijama).

## Guest korisnici

- Mogu napraviti rezervaciju **bez naloga** – `user_id` je **NULL** u `reservations` i `temp_data`.
- Na **`/guest/reserve`**: **Termini** (slotovi, bez limo putničkih kategorija) ili **Dnevna naknada** (bez slotova, limo kategorija dozvoljena) — samo **kartica** (nema avansa). Izbor datuma, vrste, vozila ili termina osvježava formu GET submitom; scroll pozicija se vraća poslije reloada (**`reservationFormScroll.js`**, v. **`project-conventions.md`**).
- Za guest rezervacije svi podaci koji se inače vežu za korisnika ostaju **snapshot** u rezervaciji / `temp_data`:
  - **`user_name`** (kolona u bazi — ime sa forme; u V2 guest formi polje se šalje kao **`name`**, backend mapira u snapshot `user_name`), `country`, `email`, `license_plate`, `vehicle_type_id`
- Kada guest plati, podaci se čuvaju u tabeli, ali **nema veze sa `users`**.

## Besplatne rezervacije — ulogovani korisnik (panel)

- Javni “Učenici/humanitarci” ulaz je ukinut.
- Agencije (ulogovani korisnici) podnose **zahtjev** kroz panel: **`/panel/fzbr`** (stavka navigacije: **Besplatne rezervacije**).
- Ovo **nije** checkout i **ne kreira** `reservations` niti `temp_data` u trenutku slanja forme.
- Forma upisuje trajni zapis u tabelu **`free_reservation_requests`** (jedan datum po zahtjevu), zatim segmente u **`free_reservation_request_segments`** (drop/pick po segmentu), vozila u **`free_reservation_request_vehicles`** (snapshot po segmentu), i dokumenta u **`free_reservation_request_attachments`** (na nivou zahtjeva). Zatim šalje admin email i kreira admin warning kao pointer.

## Pravila u kodu

1. **Gde se koristi `user_id`** – uvek proveriti da li je korisnik autentifikovan.
2. **Guest rezervacije** – tretirati kao standalone; nema FK ka `users`.
3. **Metode koje rade sa rezervacijama** moraju podržavati oba slučaja:
   - `$reservation->user?->name` → `null` ako je guest
   - ili `$reservation->isGuest()` pa prikaz iz `$reservation->user_name` (snapshot).

## Istorija plaćanja

- **Autentifikovani** – vidi svoje rezervacije prema `user_id` (npr. `$user->reservations`); u panelu postoji tab **Istorija plaćanja** (`/profile/payments`) — trenutno placeholder koji upućuje na rezervacije dok se ne uvede poseban pregled transakcija.
- **Guest** – nema istoriju (osim ako se kasnije ne uvede način da napravi nalog i poveže stari `user_id`).

## Eloquent relacije

- **User** `hasMany` **Reservation** (samo rezervacije gde je `user_id` = taj user).
- **Reservation** `belongsTo` **User** (nullable) – kada je guest, `user_id` je null.
- Isto važi za **TempData** i **User**.

U metodama koje manipulišu rezervacijama uvek koristiti **nullable check** na `user_id` / `$reservation->user` (npr. optional chaining `$reservation->user?->name` ili `$reservation->isGuest()`).
