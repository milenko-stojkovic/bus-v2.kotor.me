# Autentifikovani vs. guest korisnici

**Konvencije (prevodi, mail, panel):** `docs/project-conventions.md`.

## User model (autentifikovani)

- Autentifikovani korisnici imaju red u tabeli `users`.
- Važe svi standardni Laravel auth mehanizmi: registracija, verifikacija e-mail-a, reset lozinke, remember token itd.
- User je povezan sa **Reservation** i **Vehicle** preko `user_id` (nullable u rezervacijama).

## Guest korisnici

- Mogu napraviti rezervaciju **bez naloga** – `user_id` je **NULL** u `reservations` i `temp_data`.
- Na **`/guest/reserve`**: **Termini** (slotovi, bez limo putničkih kategorija) ili **Dnevna naknada** (bez slotova, limo kategorija dozvoljena) — samo **kartica** (nema avansa). Izbor datuma, vrste, vozila ili termina osvježava formu GET submitom; scroll pozicija se vraća poslije reloada (**`reservationFormScroll.js`**, v. **`project-conventions.md`**). Poslije **neuspjelog** checkout POST-a (validacija, blokada kategorije, dupli termin itd.) stranica **automatski skroluje** do poruke na vrhu (`#guest-reservation-feedback`, **`data-guest-reservation-feedback`**) da korisnik odmah vidi grešku — samo guest stranica, ne agency/admin.
- **Termini — dupli pokušaj:** checkout odbija istu normalizovanu tablicu + isti datum ako se poklapa **dolazak** ili **odlazak** sa postojećom `paid`/`free` rezervacijom ili aktivnim `temp_data` pending (isti smjer; cross-match ne blokira). Dnevna naknada nije obuhvaćena.
- **Niža kategorija od historije (guest, plaćeno):** prije kreiranja `temp_data` / Bankart sesije, **`GuestPaidLowerCategoryCheckoutGuard`** blokira checkout ako ista normalizovana tablica ima **najnoviju stariju guest** rezervaciju (`status=paid`, `user_id IS NULL`) čija je **`vehicle_types.price`** (preko snapshot **`reservations.vehicle_type_id`**) **viša** od izabrane kategorije. Agencijske rezervacije (`user_id` not null), `free`, `temp_data` i pending plaćanja **ne** ulaze u historiju. Korisnik vidi poruku sa **potrebnom kategorijom**, linkom na **PDF uputstvo za agencije** (`config/user-guides.php`) i **`bus@kotor.me`**. Blokirani pokušaj → **`admin_alerts`** tip **`guest_lower_category_checkout_blocked`** + email. Agencije koriste panel za upravljanje vozilima/kategorijama — ovo pravilo se **ne** primjenjuje na ulogovan checkout. Safety-net poslije plaćanja: **`GuestPaidLowerCategoryAlertService`** (v. **`admin-panel.md`**).
- Za guest rezervacije svi podaci koji se inače vežu za korisnika ostaju **snapshot** u rezervaciji / `temp_data`:
  - **`user_name`** (kolona u bazi — ime sa forme; u V2 guest formi polje se šalje kao **`name`**, backend mapira u snapshot `user_name`), **`country`** (ISO alpha-2 **država naplatne adrese kartice** za Bankart — ne država kompanije/vozila), `email`, `license_plate`, `vehicle_type_id`
- **`country`** na guest formi, agencijskoj registraciji i profilu: label **„Država naplatne adrese kartice“** / **„Card billing country“**; lista iz **`config/countries.php`** (puni ISO 3166-1 alpha-2 + XK); **`OTHER`** nije dozvoljen; nema fallback države pri slanju u Bankart.
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
