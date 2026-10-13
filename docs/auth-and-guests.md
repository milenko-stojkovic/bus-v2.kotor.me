# Autentifikovani vs. guest korisnici

**Konvencije (prevodi, mail, panel):** `docs/project-conventions.md`.

## User model (autentifikovani)

- Autentifikovani korisnici imaju red u tabeli `users`.
- Važe svi standardni Laravel auth mehanizmi: registracija, verifikacija e-mail-a, reset lozinke, remember token itd.
- User je povezan sa **Reservation** i **Vehicle** preko `user_id` (nullable u rezervacijama).

## Guest korisnici

- Mogu napraviti rezervaciju **bez naloga** – `user_id` je **NULL** u `reservations` i `temp_data`.
- Na **`/guest/reserve`**: **Termini** (slotovi, bez limo putničkih kategorija) ili **Dnevna naknada** (bez slotova, limo kategorija dozvoljena) — samo **kartica** (nema avansa). Izbor datuma, vrste, vozila ili termina osvježava formu GET submitom; scroll pozicija se vraća poslije reloada (**`reservationFormScroll.js`**, v. **`project-conventions.md`**). Poslije **neuspjelog** checkout POST-a (validacija, blokada kategorije, dupli termin itd.) stranica **automatski skroluje** do poruke na vrhu (`#guest-reservation-feedback`, **`data-guest-reservation-feedback`**) da korisnik odmah vidi grešku — samo guest stranica, ne agency/admin. Ispod dugmeta **Rezerviši** / **Reserve** prikazuje se logo prihvaćenih kartica (**`public/images/karticebanka.png`**, partial **`bank-cards-logo`**).
- **Termini — dupli pokušaj:** checkout odbija istu normalizovanu tablicu + isti datum ako se poklapa **dolazak** ili **odlazak** sa postojećom `paid`/`free` rezervacijom ili aktivnim `temp_data` pending (isti smjer; cross-match ne blokira). Dnevna naknada nije obuhvaćena.
- **Niža kategorija od istorije (guest, plaćeno):** prije kreiranja `temp_data` / Bankart sesije, **`GuestPaidLowerCategoryCheckoutGuard`** blokira checkout ako ista normalizovana tablica ima **najnoviju stariju guest** rezervaciju (`status=paid`, `user_id IS NULL`) čija je **`vehicle_types.price`** (preko snapshot **`reservations.vehicle_type_id`**) **viša** od izabrane kategorije. Agencijske rezervacije (`user_id` not null), `free`, `temp_data` i pending plaćanja **ne** ulaze u istoriju. Korisnik vidi poruku sa **potrebnom kategorijom**, linkom na **PDF uputstvo za agencije** (`config/user-guides.php`) i **`bus@kotor.me`**. Blokirani pokušaj → **`admin_alerts`** tip **`guest_lower_category_checkout_blocked`** + email. Agencije koriste panel za upravljanje vozilima/kategorijama — ovo pravilo se **ne** primjenjuje na ulogovan checkout. Safety-net poslije plaćanja: **`GuestPaidLowerCategoryAlertService`** (v. **`admin-panel.md`**).
- Za guest rezervacije svi podaci koji se inače vežu za korisnika ostaju **snapshot** u rezervaciji / `temp_data`:
  - **`user_name`** (kolona u bazi — ime sa forme; u V2 guest formi polje se šalje kao **`name`**, backend mapira u snapshot `user_name`), **`country`** (ISO alpha-2 **država platne kartice** za Bankart `billingCountry` — ne država kompanije/vozila), `email`, `license_plate`, `vehicle_type_id`

### Država platne kartice (`country`)

Polje označava **državu izdavanja platne kartice** kojom se plaća (Bankart `customer.billingCountry`), ne sjedište agencije niti registraciju vozila.

| | CG | EN |
|---|----|-----|
| **Label** | Država naplatne adrese kartice | Card billing country |
| **Pomoćni tekst** | Odaberite državu u kojoj je izdata platna kartica kojom će biti izvršeno plaćanje. | Select the billing country of the payment card you will use. |

- **Gde se prikazuje:** guest **`/guest/reserve`**, agencijska **registracija**, **profil** (`/panel/user`, Breeze profile), **admin pretraga/izmena rezervacija** (`/admin/rezervacije`).
- **Izvor liste:** `config/countries.php` — kanonski ISO 3166-1 alpha-2 (+ **XK**); fajl se **ne** reorder-uje ručno.
- **Redosled u dropdown-u:** `App\Support\BankartBillingCountry::selectableCountries($locale)` — prvo fiksni prioritet (**ME, RS, HR, MK, BA, AL, HU, GR, TR, SI, UA, LT, BG, PL, RO, MD, DE, FR, XK, SE, CZ, NL, SK**; nedostajući kodovi se preskaču), zatim ostale države **A–Z** po lokalizovanom nazivu (`cg` / `en`).
- **Validacija:** samo kodovi iz `config/countries.php`; **`OTHER`** nije dozvoljen; checkout blokira nevažeće vrednosti **prije** `temp_data` / Bankart sesije (`BankartBillingCountryAlertService`).
- **Bankart payload:** `BankartBillingCountry::resolveForPayload()` — **nema** fallback države; prazan ili nevažeći kod → blokada.
- **Prevodi:** `ui_translations` grupe **`reservation`** (guest), **`auth`** (registracija), **`user`** (panel profil); ključevi `country`, `country_help`, `country_select_required` / `country_invalid_stored` gde je primjenjivo. Blade fallback stringovi moraju ostati usklađeni sa seederom.
- **Testovi:** `CardBillingCountryWordingTest`, `BankartBillingCountryCheckoutTest`, `BankartBillingCountryOrderTest`, `AgencyCountryRegistrationTest`, `AgencyCountryProfileTest`, `AdminPanelReservationCountryOrderTest`.
- Kada guest plati, podaci se čuvaju u tabeli, ali **nema veze sa `users`**.

## Besplatne rezervacije — ulogovani korisnik (panel)

- Javni “Učenici/humanitarci” ulaz je ukinut.
- **Zahtjev za besplatnu rezervaciju** podnosi se isključivo kroz panel: **`/panel/fzbr`**, kartica **Besplatne rezervacije** / **Free reservations**. `bus@kotor.me` je opšti kontakt podrške, ne kanal za podnošenje. Stari V1 korak preko `prihodi@kotor.me` više nije uputstvo.
- Slanje forme **nije** checkout i **ne** kreira odobrenu rezervaciju (`reservations` / `temp_data` nastaju tek kada administrator odobri zahtjev).
- Forma upisuje **`free_reservation_requests`** (jedan datum), segmente, vozila iz voznog parka i priloge. Operater dobija email; agencija pri slanju ne dobija potvrdu.
- Ako termine treba usaglasiti ili dokumentacija nije dovoljna, administrator piše agenciji; dodatni dokumenti idu odgovorom na taj email. Panel ne nudi naknadni upload.
- Odbijanje mora imati obrazloženje administratora. Lista u panelu prikazuje status **Odbijeno** / **Rejected** i ne prikazuje razlog. Kod trenutno ne čuva razlog i ne šalje email pri odbijanju — v. **[agency-panel.md](./agency-panel.md)**.

## Pravila u kodu

1. **Gde se koristi `user_id`** – uvek proveriti da li je korisnik autentifikovan.
2. **Guest rezervacije** – tretirati kao standalone; nema FK ka `users`.
3. **Metode koje rade sa rezervacijama** moraju podržavati oba slučaja:
   - `$reservation->user?->name` → `null` ako je guest
   - ili `$reservation->isGuest()` pa prikaz iz `$reservation->user_name` (snapshot).

## Istorija plaćanja

- **Autentifikovani** – rezervacije su vezane preko `user_id`. U panelu nema kartice „Istorija plaćanja”. `GET /profile/payments` (`profile.payments`) radi redirect na **`/panel/statistics`**. Pregled prošlih rezervacija je **Realizovane rezervacije** (`/panel/realized`); zbirni pregled je **Statistika**.
- **Guest** – nema istoriju (osim ako se kasnije ne uvede način da napravi nalog i poveže stari `user_id`).

## Eloquent relacije

- **User** `hasMany` **Reservation** (samo rezervacije gde je `user_id` = taj user).
- **Reservation** `belongsTo` **User** (nullable) – kada je guest, `user_id` je null.
- Isto važi za **TempData** i **User**.

U metodama koje manipulišu rezervacijama uvek koristiti **nullable check** na `user_id` / `$reservation->user` (npr. optional chaining `$reservation->user?->name` ili `$reservation->isGuest()`).
