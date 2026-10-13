# bus.kotor.me — agency assistance guide (draft)

This document is the knowledge source for an external assistant helping people use **bus.kotor.me** as a prospective or registered agency. It is not published on the website yet.

Answer in the user's language. Montenegrin, Serbian, Croatian and Bosnian share the site language **cg**. English is **en**.

Labels below are written as **CG** / **EN**. If the words on the user's screen differ, follow the screen and do not argue with it. Live wording can be updated without this draft changing.

This guide describes the public site as agencies use it in production: **Avans** / **Advance** is available, and **Limo** is an information page. There is no separate Limo booking and no QR flow for agencies.

Do not guide guest-only booking except to point a visitor away from the guest card and toward agency registration. Do not describe administrator screens.

## Contents

1. How to assist
2. Device and language
3. What you must not invent
4. Landing page
5. Registration, verification, login, password
6. Agency menu
7. Reservations
8. Advance balance
9. Vehicles
10. Plate change
11. Free-reservation requests
12. Limo information
13. Realized reservations, statistics, profile, account deletion
14. Support

## 1. How to assist

- Ground every step in this document and in what the user says they see.
- Do not ask which device they use when the question does not depend on the layout.
- Before layout-specific steps, if you do not already know, ask which device they are using **to open bus.kotor.me**. That device may be different from the phone or computer they use for this chat. Do not ask again once they have answered.
- Ask which screen they are on, and which buttons or menu names they can see, only when the next step depends on it.
- Prefer a short sequence of steps. Do not paste a whole section of this guide. Give one manageable set of instructions at a time, especially on a phone.
- You cannot see their screen, account, balance, or email. Never say that you can.
- Never invent a button, menu item, status, or email.
- Never ask for a password, a full card number, a card security code, or a one-time bank code. A user may need to type a password into bus.kotor.me themselves. Tell them to do that on the site, not in the chat.
- Do not ask for personal data you do not need to point them to the right screen.
- A **submitted request** is not an approved reservation. Say so whenever someone talks about a free-reservation request.
- If a step is not in this document, say you cannot confirm it. Offer `bus@kotor.me` for account, payment, or operational problems you cannot resolve from this guide.
- You cannot create a booking, take a payment, change a plate, or edit an account. The user does that on bus.kotor.me.

## 2. Device and language

There is one website. There is no separate tablet app and no separate phone app.

The menu changes at a window width of about 640 pixels:

- **Computer or laptop, and a wide tablet:** a red bar across the top. Menu names sit in a row and can wrap onto a second line if the window is narrow. On the right of that bar: a PDF icon (only if the guide file is available), the language flags, then the agency name. The agency name opens a small menu.
- **Phone, and a narrow tablet:** the same red bar shows the logo and a hamburger button (three lines) on the right. Tapping it opens the menu as a vertical list. Language flags and the PDF icon are at the top of that open list. The agency name and email are lower in the list, then **Korisnik** / **User** and **Log Out**.

If the user says they are on a tablet, ask whether they see a row of menu names or a hamburger. Use that answer. Do not describe a third layout.

**Language.** Two flags:

- Montenegrin flag, label **Crnogorski**. Chooses cg.
- UK flag, label **English**. Chooses en.

On a wide screen the flags are in the top bar, just left of the agency name. On a phone they are at the top of the opened hamburger menu. The same flags appear on the public pages before login, next to the PDF icon.

The choice is kept for the browser session. The profile language under **Korisnik** / **User** is the default when no flag has been chosen in that session.

**PDF guide.** A document icon, accessible name **Uputstvo (PDF)** / **User guide (PDF)**. It opens a PDF in a new tab: Montenegrin when the site is cg, English when the site is en. The icon is hidden if that file is missing. If the PDF and the live screen disagree, follow the live screen and this guide.

**Account menu on a wide screen.** Click the agency name at the top right. The menu contains **Korisnik** / **User** and **Log Out**. **Log Out** stays in English even when the site is cg. On the email-verification page the logout control is translated: **Odjava** / **Log out**.

## 3. What you must not invent

- Do not tell the user to email a request to start a free reservation. The only way to submit one is the panel form under **Besplatne rezervacije** / **Free reservations**.
- Do not offer an old email address as a way to submit that request.
- Do not describe a list of failed card attempts on **Avans**. That page shows the balance, the advance ledger, and the message for the top-up the user just returned from. It does not list older attempts, and it is not a history of card payments for reservations.
- Do not invent password complexity rules. The form shows the error under the field.
- Do not claim the printed PDF guide matches every current screen.
- Do not claim you know which database records block account deletion. If deletion fails, the account stays and a message is shown. Support is `bus@kotor.me`.
- Do not send the user to administrator tools, staff tools, or QR tools. Those are not agency functions.
- A grey, non-clickable **Avans** or **Limo** is not the normal production menu. If the user sees that, they should write to `bus@kotor.me` rather than follow a hidden workaround.

## 4. Landing page

Address: `https://bus.kotor.me/`.

The heading is two lines:

- **Plaćanje naknade** / **Payment of the fee**
- **za ekonomsko iskorišćavanje kulturnih dobara** / **for the economic use of cultural assets**

Under it: **Izaberite način nastavka.** / **Choose how you want to continue.**

Two cards, one under the other on every screen. Guest is first. Agencies is second. Scroll down if the second card is below the fold.

| Card | Text | Button | Where it goes |
|---|---|---|---|
| **Guest** / **Guest** (this title is the English word Guest in both languages) | **Za pojedinačne i povremene rezervacije.** / **For one-time and occasional reservations.** | **Nastavi kao guest** / **Continue as guest** | One-time booking without an agency account. Do not use this path to register an agency. |
| **Agencije** / **Agencies** | **Za registrovane korisnike, bržu rezervaciju i pregled rezervacija.** / **For registered users, faster booking and reservation history.** | **Prijava za agencije** / **Agency sign in** | Agency login. |

**I cannot find registration.**

1. Open `https://bus.kotor.me/`.
2. Scroll past the **Guest** card to **Agencije** / **Agencies**.
3. Select **Prijava za agencije** / **Agency sign in**.
4. On the login page, under the form: **Nemate nalog?** / **Don’t have an account?** then **Kreirajte nalog** / **Create an account**.

There is no public “students / humanitarian” card. Free-reservation requests are only inside a verified agency account.

## 5. Registration, verification, login, password

### Create an account

Page title: **Kreirajte nalog** / **Create an account**.

Fields, in order:

1. **Naziv** / **Name**. The agency or organisation name.
2. **Država naplatne adrese kartice** / **Card billing country**. Help text: **Odaberite državu u kojoj je izdata platna kartica kojom će biti izvršeno plaćanje.** / **Select the billing country of the payment card you will use.** This is the country of the card used to pay, not the country where the vehicles are registered and not the agency's office country. The list starts with a fixed set of countries (Montenegro first) and then the rest in alphabetical order. Empty option: **Odaberite državu** / **Select country**. If the country is not listed: **Ako Vaša država nije na spisku, kontaktirajte administratora na bus@kotor.me.** / **If your country is not listed, contact the administrator at bus@kotor.me.**
3. **Email** / **Email**. This becomes the login.
4. **Lozinka** / **Password**. An eye control is **Prikaži** / **Show** and hides again with the matching hide label.
5. **Potvrdite lozinku** / **Confirm password**.

Button: **Kreiraj nalog** / **Create account**.

Links back to login: **Već imate nalog?** / **Already registered?** and **Prijavite se** / **Log in**.

The site checks the fields and shows the message under the field that failed. Do not guess a minimum password length. If the two passwords differ, the confirmation field shows an error. If the email is already used, the email field shows an error; the person should use **Prijavite se** / **Log in** or reset the password.

### Verify email

After registration the user is not yet in the agency panel. The page explains, in cg: **Hvala na registraciji! Prije nego što nastavite, molimo vas da verifikujete email adresu klikom na link koji smo vam poslali. Ako niste dobili email, možete zatražiti novi.** In en: **Thanks for signing up! Before getting started, could you verify your email address by clicking on the link we just emailed to you? If you didn’t receive the email, we will gladly send you another.**

Button: **Ponovo pošalji verifikacioni email** / **Resend verification email**.

The email subject is **Verifikujte email adresu** / **Verify Email Address**. The button in the email is **Verifikuj email** / **Verify Email Address**. If the button does not work, copy the link from the email into the browser address bar.

Until the link is opened, **Rezervacije** and the rest of the panel stay unavailable. Check the spam folder before sending a new message. After the link works, the next sign-in opens the agency panel.

Logout on this page is **Odjava** / **Log out**.

### Log in

Title: **Prijava** / **Log in**.

Fields: **Email** / **Email**, **Lozinka** / **Password**, checkbox **Zapamti me** / **Remember me**.

Button: **Prijavite se** / **Log in**.

A wrong email or password shows an English message (`auth.failed`), not a translated sentence. After several failed attempts the login is temporarily blocked and the wait message is also English. Wait and try again. Do not ask the user to dictate the password.

A verified login opens **Rezervacije** / **Reservations**.

### Log out

Wide screen: agency name, then **Log Out** (English). Phone: open the hamburger, then **Log Out** near the bottom. This ends the agency session on bus.kotor.me.

### Forgotten password

On the login page: **Zaboravili ste lozinku?** / **Forgot your password?**

The page says: **Zaboravili ste lozinku? Nema problema. Unesite svoju email adresu i poslaćemo vam link za reset lozinke pomoću kojeg možete odabrati novu.** / **Forgot your password? No problem. Just let us know your email address and we will email you a password reset link that will allow you to choose a new one.**

Field: **Email** / **Email**. Button: **Pošalji link za reset lozinke** / **Email Password Reset Link**.

The following reset page keeps two labels in English even on cg: the field **Email** and the button **Reset Password**. Enter the same email, the new password, and the confirmation, then use **Reset Password**. Open the link in the same browser if the reset page says the link is invalid, and request a new link from the login page if it has expired.

## 6. Agency menu

After a verified login the red bar is the whole navigation. Order, left to right on a wide screen, and top to bottom in the phone menu:

1. **Rezervacije** / **Reservations**. New booking.
2. **Avans** / **Advance**. Balance and top-up. In production this is a real link.
3. **Limo** / **Limo**. Information only. In production this is a real link, immediately after Advance.
4. **Korisnik** / **User**. Profile.
5. **Vozila** / **Vehicles**. Fleet.
6. **Besplatne rezervacije** / **Free reservations**. Request a free reservation. It does not create one by itself.
7. **Promjena tablica** / **Plate change**. Change the plate on an upcoming reservation.
8. **Realizovane rezervacije** / **Realized reservations**. Past reservations and PDFs.
9. **Statistika** / **Statistic**. The menu word in English is **Statistic**. The page title is **Statistics** / **Statistika**.

**I am on my phone. Where is Avans?** Open bus.kotor.me, sign in, tap the hamburger (three lines, top right), and look near the top of the list for **Avans** / **Advance**, directly under **Rezervacije** / **Reservations**. **Limo** is the next row.

**I am on a computer. Where is Avans?** It is the second name in the red bar, after **Rezervacije** / **Reservations**.

There is no menu item called payment history. Past reservations are **Realizovane rezervacije** / **Realized reservations**. Totals are **Statistika** / **Statistic**.

## 7. Reservations

Open **Rezervacije** / **Reservations**.

The section title is **Nova rezervacija** / **New reservation**.

If the fleet is empty, the page says **Prije rezervacije dodajte bar jedno vozilo u sekciji Vozila.** / **Add at least one vehicle in the Vehicles tab before booking.** The link is **Otvori vozila** / **Open Vehicles**.

The page asks for a **Vrsta rezervacije** / **Reservation type**. Two choices. The explanation above them is the text on the page, with place names as map links:

- **Termini** / **Time slots**. **Termini — ako želite unaprijed rezervisano vrijeme dolaska i odlaska na lokaciji Benovo (obavezna lokacija). Ako Vam nisu na raspolaganju željeni termini odaberite dnevnu naknadu.** / **Time slots — if you want reserved arrival and departure times at Benovo (mandatory location). If your preferred time slots are unavailable, choose the daily fee.** Benovo opens https://maps.app.goo.gl/5Mp6LFS1gNLYFrSQA
- **Dnevna naknada** / **Daily fee**. **Dnevna naknada — ako vam nije bitan tačan termin ili planirate obilazak više lokacija tokom dana, npr. Perast, Risan, žičara Kotor - Lovćen... U slučaju da želite da posjetite Stari grad, za iskrcaj i ukrcaj putnika koriste se lokacije parkinga Autoboka i Puč.** / **Daily fee — if an exact time is not important or you plan to visit several locations during the day, e.g. Perast, Risan, the Kotor–Lovćen cable car… When visiting the Old Town, passenger pick-up and drop-off use the Autoboka and Puč parking areas.** Autoboka in that sentence opens https://maps.app.goo.gl/BqfQWnYqy8mjTo1D8 and Puč opens https://maps.app.goo.gl/1XKocEMgyYi7YoD99. Both Autoboka links open the same Google place, Parking Autoboka. This Puč link and the Puč link in the time-slot parking box both refer to **parking Puč kod stadiona** / **Puč parking beside the stadium**.

Dates run from today through 90 days ahead. The date control is a day/month/year field plus a calendar button.

### Time slots

1. Choose **Termini** / **Time slots**.
2. Choose **Datum** / **Date**. The page reloads available times and keeps the scroll position.
3. Choose **Vrijeme dolaska** / **Arrival time**. A slot that cannot be used is not selectable. **Vrijeme odlaska** / **Departure time** stays disabled until an arrival is chosen. Hint: **Vrijeme odlaska je dostupno tek nakon izbora vremena dolaska.** / **Departure is disabled until you choose arrival.**
4. Choose **Vrijeme odlaska** / **Departure time**. Options that start before the chosen arrival cannot be selected. The empty option in both time lists is **Izaberite termin** / **Select a time slot**.
5. Choose **Vozilo** / **Vehicle**. The empty option is **Izaberite vozilo** / **Select vehicle**. Each vehicle option shows the plate, then the category and its price in EUR.
6. When the date, both times, and a vehicle are set, **Način plaćanja** / **Payment method** appears, unless the pair of slots is free (see below).
7. Tick **Saglasan/a sam sa** / **I agree to the** and open **uslovima korišćenja** / **terms and conditions** if the user needs to read them. This box is required. For time slots there is a second required box. It is hidden for the daily fee. The time-slot box says, with the spellings that are on the screen: **U slučaju da prevoznik nije u mogućnosti da izvrši iskrcaj i ukucaj putnika u terminu za koji je plaćena naknada, isti navedenu radnju mora izvršiti na parkingu Autoboka i pakringu Puč.** / **If the carrier is unable to drop off and pick up passengers at the time slot for which the fee was paid, this action must be performed at the Autoboka parking and the Puč parking.** Those two names are map links: Autoboka https://maps.app.goo.gl/oXD6SEzjyXtm4c586 and Puč https://maps.app.goo.gl/kPAD6mipzZTjCCYE7. This Autoboka link opens the same Google place as the daily-fee Autoboka link. This Puč link, like the daily-fee Puč link, refers to **parking Puč kod stadiona** / **Puč parking beside the stadium**. Then **Rezerviši** / **Reserve**. The card-logo image under the button shows which cards are accepted. It is not itself a button.

**Why a slot cannot be selected.** A slot that is grey cannot be chosen. Each option shows the time and a count: **(N slobodna mjesta)** / **(N spots left)**. A paid slot is turned off when that count is not enough, when the slot is blocked, when it is already past on today's date, or, for departure, when it starts before the chosen arrival. A slot inside the free night or early-morning window is not turned off only because the count is low. It can still be turned off if it is blocked or already past. Pick another time or another date. If the needed times are gone, use **Dnevna naknada** / **Daily fee** when that product matches the trip. Do not promise that a full slot will open.

**The same plate on the same day.** For time slots, the same plate and the same date cannot already have a paid or free reservation, or a payment still in progress, with the **same arrival** or the **same departure**. A booking whose arrival matches another booking's departure is allowed. Vehicles that would break this rule disappear from the vehicle list after the date and times are chosen. The note is **Neka vozila nisu prikazana jer za odabrani datum već imaju rezervaciju sa istim vremenom dolaska ili odlaska.** / **Some vehicles are not shown because they already have a reservation on the selected date with the same arrival or departure time.** The daily fee does not use this slot rule. One plate can have a time-slot reservation and a daily fee on the same date.

**Passenger cars 4+1 through 7+1** do not appear in the vehicle list for new time-slot reservations. They do appear for the daily fee. If a time-slot booking is refused for that reason, the message is **Ova kategorija vozila nije dostupna za rezervacije po terminima. Za putnička vozila (4+1–7+1) koristite dnevnu naknadu.** / **This vehicle category is not available for time-slot bookings. For passenger vehicles (4+1–7+1), use Daily fee.** Those vehicles also must not board or leave passengers at Benovo. See the Limo section.

**Free night and early-morning slots.** The time list does not print the word free on each row. After both times are chosen, the summary box shows either **Ova rezervacija je besplatna** / **This reservation is free** or **Ukupno za plaćanje** / **Total to pay** with an amount in EUR. Trust that box. It is the price the user is shown.

The list has one morning block, **00:00 - 07:00**, and one evening block, **20:00 - 24:00**. The other rows are paid 20-minute slots from **07:00 - 07:20** through **19:40 - 20:00**.

A free slot is one of those two blocks. A free reservation is a pair of chosen times that the site prices at zero. These pairs are free:

- Arrival **00:00 - 07:00** and departure **00:00 - 07:00**.
- Arrival **00:00 - 07:00** and departure **20:00 - 24:00**. This pair is free even though the hours between the two blocks are paid slots. The price follows the two chosen times.
- Arrival **20:00 - 24:00** and departure **20:00 - 24:00**.

Arrival **20:00 - 24:00** and departure **00:00 - 07:00** is not free, even though each time is a free slot. On the form, a departure that starts before the chosen arrival cannot be selected, so this is not a normal same-day choice. If that pair is submitted anyway, the reservation is paid.

Any pair that includes a 20-minute daytime slot is paid.

If the box says the reservation is free, there is no card payment and no advance payment. The confirmation is emailed. It is not a fiscal invoice. If the box shows a price, the reservation is paid.

This is not a request under **Besplatne rezervacije** / **Free reservations**. That form only submits a request. It does not create this free reservation.

### Daily fee

1. Choose **Dnevna naknada** / **Daily fee**.
2. Choose the date. There is no arrival or departure list.
3. Choose the vehicle, including a limo passenger car when that is the service.
4. Choose payment, then **Rezerviši** / **Reserve**.

The price is the category price for one vehicle and one calendar day. There is no slot capacity. Some dates can be closed for new daily fees. If the chosen date is closed, the page says **Prodaja dnevne naknade nije dostupna za izabrani datum. Izaberite drugi datum.** / **Daily fee sales are not available for the selected date. Please choose another date.** Pick another date. Do not describe an administrator screen.

A daily fee is “upcoming” for the whole validity day and becomes realized on the next calendar day (Europe/Podgorica).

### Payment

**Plati karticom** / **Pay by card** is the default. The user is sent to the card page. They enter the card there, not in this chat. When the bank confirms payment, bus.kotor.me creates the reservation and sends the confirmation by email. Back on the site they can see **Rezervacija je uspješno kreirana.** / **Your reservation has been created successfully.** and **Plaćanje je uspješno evidentirano.** / **Payment was processed successfully.** The confirmation follows by email.

If the fiscal invoice is not ready at that moment, the site can say the reservation is confirmed and the invoice will follow. That still means the reservation exists.

**Plati iz avansa** / **Pay from advance** is available when Advance is enabled and the balance covers the price. The line **Raspoloživi avans** / **Available advance** shows the amount in EUR. If the balance is too low, the advance choice is grey and the text is **Raspoloživi avans nije dovoljan za ovu rezervaciju.** / **Your advance balance is not sufficient for this reservation.** Add funds under **Avans** / **Advance**, then start the reservation again. Paying from advance does not open the bank page. The reservation is created immediately and the amount is deducted from the balance.

**If Reserve does nothing or payment fails.**

Read the sentence under the title. The same title covers more than one outcome. None of these messages shows the card number or a bank code. Do not collect the card number in chat.

- If **Rezerviši** / **Reserve** stays disabled, a required choice is missing: date, times (for time slots), vehicle, or payment when payment is required.
- If the bank page never opens: **Trenutno nije moguće otvoriti prozor za plaćanje. Molimo pokušajte ponovo za nekoliko minuta. Ako se problem ponovi, pišite na bus@kotor.me.** / **The payment window cannot be opened at the moment. Please try again in a few minutes. If the problem continues, contact bus@kotor.me.** No reservation was created. They may try again later.
- While the bank has not yet answered, the processing screen says **Plaćanje se obrađuje** / **Your payment is being processed**, then **Ako ste završili plaćanje na banci, sačekajte trenutak i osvježite stranicu.** / **If you already completed payment at the bank, wait a moment and refresh this page.** **Osvježi stranicu** / **Refresh page** reloads it. The screen checks the result on its own. This is not a failure. **Nazad na rezervaciju** / **Back to booking** leaves the screen and does not cancel the payment.
- **Plaćanje je otkazano.** / **Payment was cancelled.** Then **Rezervacija nije kreirana.** / **The reservation was not created.** They can start again.
- **Plaćanje nije odobreno.** / **Payment was not approved.** Read the next sentence. **Na računu nema dovoljno sredstava.** / **There are insufficient funds on the card.** Or **Banka je odbila autorizaciju kartice.** / **The bank declined the card authorization.** The reservation was not created. They can try again or use another card.
- **Plaćanje nije završeno.** / **Payment was not completed.** This title is not, by itself, rejected card details. Read the next sentence. **Vrijeme za plaćanje je isteklo.** / **The payment session has expired.** Or **Potvrda kartice nije uspjela.** / **Card authentication failed.** Or **Podaci kartice nijesu prihvaćeni.** / **The card details were not accepted.** Only the last of those three is rejected card details. The reservation was not created. They can try again.
- **Došlo je do tehničkog problema.** / **A technical issue occurred.** Then **Plaćanje trenutno nije bilo moguće završiti.** / **The payment could not be completed at the moment.** The reservation was not created. Try again in a few minutes.
- **Rezultat plaćanja nije bilo moguće potvrditi.** / **The payment result could not be confirmed.** Then **Molimo provjerite status rezervacije ili pokušajte ponovo kasnije.** / **Please check the reservation status or try again later.** Do not treat this as a known failure or a known success.
- **Plaćanje je potvrđeno kasnije** / **Payment confirmed after the window closed.** Then **Plaćanje je potvrđeno nakon isteka standardnog roka.** / **Payment was confirmed after the reservation window closed.** The reservation was not created automatically. With Advance available, that payment can be added to the advance balance instead. In **Istorija avansa** / **Advance history** the row type is **Uplata avansa** / **Advance top-up**, and the note can read **Late success konvertovan u avans** / **Late success converted to advance**. Write to `bus@kotor.me`. Do not pay again for the same trip.
- A free time-slot reservation that cannot be finished says **Rezervacija nije kreirana** / **Reservation could not be created**, then **Nismo mogli da završimo rezervaciju. Pokušajte ponovo.** / **We could not complete your reservation. Please try again.** No card was charged.

**Do not start a second payment while the first result is unknown.**

- If the processing screen is still open, stay on it and use **Osvježi stranicu** / **Refresh page**. Do not go back and click **Rezerviši** / **Reserve** again.
- For time slots, **Rezerviši** / **Reserve** with the same date and the same arrival and departure, while that payment is still open, returns to the same bank page. It does not start a second charge for that pair of times. Changing the date or either time can start a different payment. Do not change them until the first result is known.
- A daily fee does not reuse the open payment. A second **Rezerviši** / **Reserve** for a daily fee can start another card payment. Do not press it again until the first result is known.
- If the page says **Prozor za plaćanje je već otvoren ili je sesija već kreirana. Nastavite postojeće plaćanje ili pokušajte ponovo za nekoliko minuta.** / **The payment window is already open or a session was already created. Please continue the existing payment or try again in a few minutes.** Continue the payment already open. Do not start another one.

**How to tell whether a card payment became a reservation.**

- Read the message on **Rezervacije** / **Reservations** after returning from the bank.
- Check email for the confirmation.
- **Promjena tablica** / **Plate change** lists upcoming reservations that already exist, paid or free, and each has a PDF. Finding that plate and date there means the reservation exists. A payment that is still processing, cancelled, declined, or otherwise unfinished does not appear there. Not finding it does not prove the card was not charged while the result is still open, or after the late-confirmation message above. A time-slot reservation whose departure has already ended today is under **Realizovane rezervacije** / **Realized reservations**, not under **Promjena tablica**. A daily fee stays on **Promjena tablica** for the whole validity day.
- Do not use **Avans** / **Advance** as a history of card payments for reservations. A card payment for a reservation does not appear there. **Plati iz avansa** / **Pay from advance** appears in **Istorija avansa** only after it succeeds, as **Korišćenje avansa** / **Advance usage**. The late-confirmation case above is the exception that can add an **Uplata avansa** row without creating the reservation.
- If the card was charged, there is no confirmation email, and **Promjena tablica** has no matching reservation, write to `bus@kotor.me`. Do not pay again for the same trip. A message that says the reservation was not created, the payment was not approved, the payment was not completed, or a technical issue occurred means they may start again.

The invoice or free confirmation PDF is Montenegrin even when the site is English. Paid reservations produce an invoice. Free slot reservations produce a free confirmation, not a fiscal invoice. Advance top-ups are not invoices. See Advance.

## 8. Advance balance

Open **Avans** / **Advance**.

At the top of this page the heading is **Trenutno stanje avansa** / **Current advance balance**, then the amount in EUR. This is the money available for **Plati iz avansa** / **Pay from advance**.

On **Rezervacije** / **Reservations** the same balance is labeled **Ukupan iznos avansa:** / **Total advance balance:**. The colon is part of that label. Next to the payment choice the label is **Raspoloživi avans** / **Available advance**. These three labels are the same balance in different places. They are not three different amounts.

**Istorija avansa** / **Advance history** is the ledger. It shows the latest 50 entries, not every older row and not a list of every time someone pressed the top-up button. Columns: **Datum** / **Date**, **Tip** / **Type**, **Iznos** / **Amount**, **Napomena / referenca** / **Note / reference**. Types:

- **Uplata avansa** / **Advance top-up**. Money added. Positive amount.
- **Korišćenje avansa** / **Advance usage**. Money spent on a reservation or daily fee. Negative amount.
- **Korekcija** / **Correction**. An adjustment. The agency does not enter corrections on this page.

Empty ledger: **Nema transakcija.** / **No transactions.**

### Add funds

Section **Avansna uplata** / **Advance top-up**.

Field **Iznos avansne uplate** / **Top-up amount**. Hint: **Dozvoljeni su samo cijeli iznosi eura koji se završavaju na 0 ili 5 (npr. 15, 100, 105).** / **Only whole-euro amounts ending in 0 or 5 are allowed (e.g. 15, 100, 105).** Amounts such as 12, 10.50, or 7 are rejected with **Iznos avansne uplate mora biti cio broj eura i završavati se na 0 ili 5.** / **Top-up amount must be a whole number of euros and end with 0 or 5.**

Button: **Pokreni avansnu uplatu** / **Start advance top-up**. The card logos under it are informational. The user then pays on the bank page.

After the bank, the site does not trust the return address alone. The user first sees a waiting screen while the payment is still open:

- Title **Avansna uplata se obrađuje** / **Advance top-up is being processed**
- The same sentence continues: **Avansna uplata se obrađuje. Ako je potrebno, osvježite stranicu za nekoliko trenutaka.** / **Advance top-up is being processed. If needed, refresh the page in a few moments.**
- **Osvježi stranicu** / **Refresh page** reloads this waiting screen.
- **Nazad na avans** / **Back to advance** leaves it immediately. This does not decide the payment.

The waiting screen checks the result on its own, every few seconds, including after the notice below. When the payment is finished, **Avans** / **Advance** opens with one message. The user does not need to guess from the balance alone.

If the user has been on this waiting screen for 60 seconds and the result is still open, a notice appears. It does not mean the payment failed, was cancelled, or succeeded. The 60 seconds start when this screen opens, not when the top-up was created.

- CG: **Rezultat avansne uplate još nije potvrđen. Možete se vratiti na Avans. Ako banka naknadno potvrdi uplatu, sredstva će biti automatski evidentirana.**
- EN: **The advance top-up result has not yet been confirmed. You can return to Advance. If the bank confirms the payment later, the funds will be credited automatically.**
- Button **Povratak na Avans** / **Return to Advance**. It opens **Avans** / **Advance** and does not change the payment.

If they use that button while the payment is still open, **Avans** does not show a success or failure message. The balance stays as it was. If the bank confirms the payment later, the amount is added automatically and appears in **Istorija avansa** / **Advance history**. If they are still on the waiting screen when the bank answers, the screen changes to the success or failure message by itself.

- Success: **Avansna uplata je uspješno evidentirana.** / **Advance top-up was successfully recorded.** Then **Iznos je dodat na raspoloživi avans.** / **The amount has been added to your available advance.** The balance increases and **Istorija avansa** / **Advance history** gains an **Uplata avansa** / **Advance top-up** row. An email arrives with a PDF. That PDF is a confirmation that the advance was recorded. It is not a fiscal invoice and not a reservation.
- Cancelled, when the bank recorded a cancellation: **Avansna uplata je otkazana.** / **The advance top-up was cancelled.** Then **Sredstva nisu dodata na avans. Možete pokušati ponovo.** / **No funds were added to your advance. You can try again.** The balance does not change.
- Declined authorization: **Avansna uplata nije odobrena.** / **The advance top-up was not approved.** Then **Banka je odbila autorizaciju kartice. Sredstva nisu dodata na avans.** / **The bank declined the card authorization. No funds were added to your advance.**
- Insufficient funds: the same not-approved title, then **Na računu nema dovoljno sredstava. Sredstva nisu dodata na avans.** / **There are insufficient funds on the card. No funds were added to your advance.**
- Card authentication failed: **Avansna uplata nije završena.** / **The advance top-up was not completed.** Then **Potvrda kartice nije uspjela. Sredstva nisu dodata na avans.** / **Card authentication failed. No funds were added to your advance.**
- Card details rejected: the same not-completed title, then **Podaci kartice nijesu prihvaćeni. Sredstva nisu dodata na avans.** / **The card details were not accepted. No funds were added to your advance.**
- Expired payment: the same not-completed title, then **Vrijeme za plaćanje je isteklo. Sredstva nisu dodata na avans.** / **The payment session has expired. No funds were added to your advance.**
- Any other recorded failure: **Avansna uplata nije uspješno završena.** / **Advance top-up was not completed successfully.** Then **Sredstva nisu dodata na avans. Pokušajte ponovo za nekoliko minuta.** / **No funds were added to your advance. Please try again in a few minutes.**
- The return cannot be matched to this agency: **Rezultat avansne uplate nije bilo moguće potvrditi.** / **The advance top-up result could not be confirmed.** Then **Provjerite stanje avansa. Ako je kartica zadužena, a iznos se ne pojavi, pišite na bus@kotor.me.** / **Check your advance balance. If the card was charged and the amount does not appear, write to bus@kotor.me.**

If the bank page never opens, **Avans** shows **Avansna uplata nije mogla biti pokrenuta.** / **Advance top-up could not be started.** No funds were added.

Do not read a bank error code or card number out to the user. The sentences above are the whole explanation.

**My top-up did not appear.**

1. If the waiting screen is still open, stay on it. It updates by itself. **Osvježi stranicu** / **Refresh page** is the manual version. After 60 seconds they may use **Povratak na Avans** / **Return to Advance**. That leaves the wait. It does not mean the payment failed.
2. When **Avans** / **Advance** opens, read the message at the top before looking at the balance.
3. Compare **Trenutno stanje avansa** / **Current advance balance** with what it was before.
4. Look in **Istorija avansa** / **Advance history** for a new **Uplata avansa** / **Advance top-up** row.
5. Check email for the confirmation PDF.
6. A cancelled or failed message means the balance should be unchanged. The user can start a new top-up.
7. If the card was charged, the waiting screen has gone, and neither the message nor a new ledger row appears, write to `bus@kotor.me`. Do not start a second top-up for the same money until that is checked.

Spending advance is done on **Rezervacije** / **Reservations**, not by typing a reservation on the Avans page. After a successful advance payment, **Istorija avansa** gains a **Korišćenje avansa** / **Advance usage** row and the balance drops by the reservation amount.

## 9. Vehicles

Open **Vozila** / **Vehicles**.

### Add a vehicle

1. **Registarske tablice** / **Registration plates**. Letters and digits only, shown in capitals. Spaces and symbols are removed as the user types.
2. **Kategorija vozila** / **Vehicle category**. Choose from the list. The label includes the name, description, and price in EUR.
3. **Dodaj vozilo** / **Add vehicle**. **Otkaži** / **Cancel** clears the form.

Success: **Vozilo je dodato.** / **Vehicle added.** The vehicle can then be chosen on **Rezervacije**, **Promjena tablica**, and **Besplatne rezervacije**.

Only **active** vehicles appear. A removed vehicle is hidden and cannot be selected.

### Same plate again

- Same plate and the **same** category as a previously removed vehicle: the vehicle is activated again. Success: **Vozilo je ponovo aktivirano.** / **Vehicle reactivated.** If a category-change request for that plate is still waiting, it stays removed and the page says **Zahtjev za promjenu kategorije za ovu tablicu čeka odluku administratora. Vozilo se ne može reaktivirati dok zahtjev nije odobren ili odbijen.** / **A category change request for this plate is awaiting admin decision. The vehicle cannot be reactivated until the request is approved or rejected.**
- Same plate and a **different** category: the plain add is blocked. The message is **Ova tablica već postoji u drugoj kategoriji. Pošaljite zahtjev za promjenu kategorije uz dokument.** / **This vehicle plate exists with a different category. Please submit a category change request with a document.** A box opens titled **Zahtjev za promjenu kategorije** / **Category change request**. Help text: **Molimo priložite fotografiju ili PDF dokumenta.** / **Please attach a photo or PDF document.** Field **Dokument (slika ili PDF)** / **Document (image or PDF)**. Hint: **Max 10MB. Obavezno.** / **Max 10MB. Required.** The control accepts more than one file: from 1 to 5 files, each a JPEG, PNG, or PDF, each up to 10 MB. Send **Pošalji zahtjev** / **Send request** or leave with **Odustani** / **Cancel**. Success: **Zahtjev je poslat administratoru.** / **The request has been sent to the administrator.** If the same request is already waiting: **Zahtjev je već poslat administratoru.** / **The request has already been sent to the administrator.**

The new category cannot be used until an administrator approves the request. The agency receives an email when the request is approved or rejected. The rejection email includes the reason. The **Vozila** page does not keep a separate list of those reasons. If the user cannot find the reason in the panel, they should read that email. If the email is missing, write to `bus@kotor.me`. Do not tell them to look for **Razlog odbijanja** on **Vozila**. That label belongs to free-reservation requests, not to vehicle categories.

### Remove a vehicle

On the vehicle row, **Ukloni vozilo** / **Remove vehicle**. The confirm box asks **Ukloniti ovo vozilo iz voznog parka?** / **Remove this vehicle from your fleet?** and explains that an upcoming reservation will lead through a replacement step. Confirm with **Ukloni** / **Remove** or leave with **Otkaži** / **Cancel**.

If the vehicle was never used on a reservation, it is deleted. If it was used, it is hidden from the fleet and the history is kept. Success: **Vozilo je uklonjeno.** / **Vehicle removed.**

If the vehicle is on an upcoming reservation, the site opens **Ukloni vozilo** / **Remove vehicle**. It names the plate as **Ciljno vozilo:** / **Target vehicle:** and says **Ovo vozilo je vezano za predstojeće rezervacije. Prije uklanjanja morate zamijeniti vozilo na tim rezervacijama.** / **This vehicle is used in upcoming reservations. You must replace it on those reservations before it can be removed.**

The list is **Predstojeće rezervacije za zamjenu** / **Upcoming reservations to replace**. For each reservation, choose **Zamjensko vozilo** / **Replacement vehicle**. The empty option is **Izaberite vozilo** / **Select vehicle**. If only one replacement exists, the page says **Za ovu rezervaciju postoji samo jedno odgovarajuće zamjensko vozilo.** / **Only one eligible replacement exists for this reservation.** A replacement must belong to the same agency, be an equal or lower category than the category paid on that reservation, and must not create a time-slot clash.

If any upcoming reservation has no eligible replacement, the page says **Ovo vozilo se ne može ukloniti jer barem jedna predstojeća rezervacija nema dostupnog zamjenskog vozila. Dodajte drugo vozilo iste kategorije u vozni park.** / **This vehicle cannot be removed because at least one upcoming reservation has no eligible replacement. Please add another vehicle of the same category to your fleet.** The confirm button stays disabled. Add a suitable vehicle first, then start removal again.

When every reservation has a choice, **Primijeni i ukloni** / **Apply & remove** asks **Primijeniti zamjene i ukloniti ovo vozilo?** / **Apply replacements and remove this vehicle?** **Otkaži** / **Cancel** returns to the vehicle list. If a required choice is missing: **Izaberite zamjensko vozilo za svaku predstojeću rezervaciju.** / **Select a replacement vehicle for each upcoming reservation.** If the combination conflicts: **Vozilo nije moguće ukloniti zbog konflikta. Pokušajte ponovo.** / **Vehicle could not be removed due to a conflict. Please try again.**

## 10. Plate change

Open **Promjena tablica** / **Plate change**.

This list is upcoming reservations only. Empty state: **Nema rezervacija za promjenu tablice.** / **No eligible reservations for plate change.**

Each paid or free row has **PDF**. That opens the current invoice or free confirmation in the browser. The button stays even when the plate cannot be changed.

To change a plate: **Promijeni tablicu** / **Change plate**, choose another active vehicle, then **Potvrdi** / **Confirm**. **Otkaži** / **Cancel** closes the choice.

Rules:

- The new vehicle must belong to this agency.
- Its category price must be **less than or equal to** the category price already paid on that reservation. The price and the invoice amount do not change. A more expensive category cannot be selected here.
- **Time slots:** the reservation must still be upcoming (the date is in the future, or it is today and the departure slot has not ended). The new plate must not already use the same arrival or the same departure that day.
- **Daily fee:** the plate can be changed only **before** the validity day. On the validity day the row shows **Promjena tablice za dnevnu naknadu nije dostupna za tekući dan.** / **Plate change for Daily fee is not available on the current day.** The general note is **Promjena tablice za dnevnu naknadu moguća je samo za buduće datume.** / **Plate change for Daily fee is only available for future dates.** A daily fee dated today cannot be changed. A daily fee dated tomorrow can, until that calendar day starts in Europe/Podgorica.

**Can I change the plate for today's daily fee?** No. Wait is not an option on that day. The reservation stays on the original plate. For a later date, open **Promjena tablica** / **Plate change** and use **Promijeni tablicu** / **Change plate**.

After a successful change the user receives an updated PDF by email.

- A **paid** reservation sends an updated invoice. This is not a new charge and not a new card payment.
- A **free** reservation sends an updated free confirmation. There is no new fiscal invoice. The on-screen success text says the email was sent. If the email does not arrive, the **PDF** button on **Promjena tablica** or **Realizovane rezervacije** still opens the current file. A failed email does not undo the plate change.

## 11. Free-reservation requests

Open **Besplatne rezervacije** / **Free reservations**.

This form submits a **request**. It does not create an approved free reservation and it does not charge a card. An administrator reviews it later.

Do not tell the user to send the first request by email. If they already wrote to `bus@kotor.me` asking for a free reservation, tell them to submit the form in the panel. `bus@kotor.me` is support, not the submission form.

### What to enter

The page explains the legal basis and how documents work. Read that text on the page. Then:

**Part 1**

- One **Datum** / **Date** for the whole request (day/month/year and a calendar button). From today up to the maximum the form allows.
- One or more arrival/departure pairs, up to five. Button to add a pair: **Dodaj dolazak i odlazak** / **Add arrival and departure**. Each pair has **Vrijeme dolaska** / **Arrival time** and **Vrijeme odlaska** / **Departure time**. A time that cannot hold the number of vehicles already chosen for that pair cannot be selected. On this form, that includes night and early-morning times.
- For each pair, at least one vehicle from the fleet. Button **Dodaj naredno vozilo** / **Add next vehicle**. The same vehicle cannot be chosen twice in the same pair. The form stops adding vehicles when the pair reaches its limit. That limit is the number of places the page allows for one pair. Do not invent a fixed number. If the button no longer adds a row, use another pair if one is still allowed, or reduce the vehicles in this pair. To remove one vehicle from a pair: **Ukloni vozilo** / **Remove vehicle**. To remove a whole pair: **Ukloni segment** / **Remove segment**.

**Part 2**

- **Osnov za zahtjev za besplatnu rezervaciju** / **Basis for a free reservation request**. At least one image or PDF. All files together must be at most 10 MB. Hint: **Ukupna veličina svih fajlova: do 10 MB. Dozvoljene slike i PDF.** / **Total size of all files: up to 10 MB. Images and PDF allowed.** The help text says the files should show why the visit is exempt (contract, invoice, agreement, or similar). If some papers are not ready, the user may attach what they have. There is no second upload button later in the panel.
- Checkbox **Saglasan/a sam sa politikom privatnosti** / **I agree to the privacy policy**.
- **Podnesi zahtjev** / **Submit request**. The button stays disabled until the required parts, including the checkbox and at least one file, are filled.

The agency name, email, and card-billing country are taken from the account. The user does not type a separate institution email on this form.

### After submit

The page returns to the same screen and shows **Zahtjev je uspješno poslat. Administrator će Vas kontaktirati.** / **Request submitted successfully. An administrator will contact you.**

The agency does not get a confirmation email for the submission itself. An operator is notified so the request can be reviewed. That is not a reservation.

At the bottom, **Moji poslati zahtjevi** / **My submitted requests**. On a phone the table can be scrolled sideways. Columns:

- **Datum zahtjeva** / **Request date**
- **Vrijeme dolaska** / **Arrival time**
- **Vrijeme odlaska** / **Departure time**
- **Vozila / tablice** / **Vehicles / plates**
- **Status** / **Status**
- **Datum slanja** / **Submitted at**

Statuses:

| Situation | CG | EN |
|---|---|---|
| Submitted, waiting | Čeka se obrada | Awaiting processing |
| Approved and reservations exist | Odobreno | Approved |
| Rejected | Odbijeno | Rejected |

Waiting requests stay on the list. A rejected request stays while its departure is still in the future. An approved request stays while at least one linked free reservation is still upcoming, and disappears from this list when those reservations are realized. They then belong under **Realizovane rezervacije** / **Realized reservations** if they are in the past.

Empty list: **Nemate poslatih zahtjeva za besplatne rezervacije.** / **You have no submitted free reservation requests.**

### If the administrator needs more information

They email the agency. The user replies to that email and attaches any extra documents the message asks for. They cannot upload those extra files from the panel.

### If the request is approved

Status becomes **Odobreno** / **Approved**. The agency receives an email that the request was processed, with one confirmation PDF per reservation. Those PDFs are free confirmations, not fiscal invoices. The reservations then follow the normal upcoming and plate-change rules.

### If the request is rejected

The administrator must enter a reason. The agency receives an email in the language of the request.

- cg subject: **Zahtjev za besplatnu rezervaciju je odbijen**
- en subject: **Free reservation request was rejected**

The email contains the request number, the date, the full reason, and tells the reader to review it on bus.kotor.me under **Besplatne rezervacije** / **Free reservations**.

**My request was rejected. Where can I read why?**

1. Sign in on the same device they use for bus.kotor.me.
2. Open **Besplatne rezervacije** / **Free reservations**. On a phone, use the hamburger and choose that item.
3. Scroll to **Moji poslati zahtjevi** / **My submitted requests**.
4. Find the row with **Odbijeno** / **Rejected**.
5. Under that row, open **Razlog odbijanja** / **Rejection reason**. The full text is there, including line breaks. On a phone, start at the left of the table. The reason block wraps to the screen width.
6. The same text is in the rejection email. The panel does not show the administrator's name or email.

If the status is **Odbijeno** / **Rejected** but no **Razlog odbijanja** / **Rejection reason** control appears, the request was rejected before reasons were stored. Use the email if it arrived, or write to `bus@kotor.me`. Do not invent a reason.

A rejected request is not a reservation. To try again, submit a new request with the corrected documents or times. There is no “edit and resubmit” button on a rejected row.

If the rejection email is late, the reason in the panel is still valid once the status is **Odbijeno** / **Rejected**. A mail problem does not undo the rejection.

## 12. Limo information

Open **Limo** / **Limo**. The page title is **Limo**. The heading is **Limo usluga** / **Limo service**.

The page is information only. It does not sell a special Limo product and it does not generate a QR code.

What it says:

- Each limo vehicle needs a paid **Dnevna naknada** / **Daily fee** for each calendar day of service. Buy that on **Rezervacije** / **Reservations**, one vehicle, one day, by card or from advance.
- In the narrow centre, from the bus station to the Kamelija shopping centre, pick-up and drop-off are allowed only at two places. Both are links that open Google Maps:
  - **autobusko stajalište pored pozorišta, kod gradske pijace** / **bus stop beside the theatre, near the city market** — https://maps.app.goo.gl/BkmeQ1ZAo8XG1FDb6
  - **izlaz iz parking prostora Riva, preko puta gradske pijace** / **exit from Riva parking area, across from the city market** — https://maps.app.goo.gl/qdy9YmTLRBsggPCD6
- Outside that zone, other places may be used if traffic rules and safety are respected.
- Vehicles of 4+1, 5+1, 6+1 and 7+1 must not pick up or drop off passengers at Benovo.
- Municipal police and other authorized persons may check.
- The driver should keep the daily-fee proof from the email or the PDF.

**Where can my limo vehicle pick up passengers in the centre?** Only the two map links above, between the bus station and Kamelija. Do not send them to Benovo.

To pay for the day: **Rezervacije** / **Reservations**, **Dnevna naknada** / **Daily fee**, the date, the limo vehicle, then **Plati karticom** / **Pay by card** or **Plati iz avansa** / **Pay from advance**, then **Rezerviši** / **Reserve**.

## 13. Realized reservations, statistics, profile, account deletion

### Realized reservations

Open **Realizovane rezervacije** / **Realized reservations**.

A time-slot reservation is here after its date, or later on the same day once the departure time has passed. A daily fee is here from the day after its validity date.

Empty: **Nema realizovanih rezervacija.** / **No realized reservations.**

**PDF** opens the invoice or free confirmation. The file name and the document body are the official Montenegrin documents.

### Statistics

Open **Statistika** / **Statistic**. Page title **Statistika** / **Statistics**.

Filters **Od** / **From** and **Do** / **To**, day/month/year. The figures include only this agency's realized reservations in that date range.

- **Ukupno plaćeno** / **Total paid**
- **Posjete** / **Visits**
- A table by plate and category

**PDF** on this page downloads an informational export in the agency's language. It is not the fiscal invoice. If the range is empty, the export says **Nema podataka za izabrani period.** / **No data for the selected period.**

There is no menu called payment history. An old address that used to point at payments opens **Statistika**.

### Profile

Open **Korisnik** / **User** from the red bar, or from the agency-name menu.

One form:

- **Ime** / **Name**
- Language: **Crnogorski / Srpski / Hrvatski / Bošnjački (cg)** or **Engleski (en)** / **Croatian / Serbian / Bosnian / Montenegrin (cg)** or **English (en)**. Only one code, cg, is stored for the South Slavic group.
- **Država - platna kartica** / **Country - payment card**, with the same card-country meaning as registration. If a stored value is no longer valid, the page says **Država naplatne adrese kartice na profilu nije ispravna. Molimo odaberite državu iz liste.** / **The card billing country on your profile is not valid. Please select a country from the list.**
- **Email** / **Email**. Changing it changes the login. Warning: **Promjena emaila mijenja prijavu. Novu adresu morate ponovo verifikovati.** / **Changing your email changes your login. You must verify the new address before verification applies.** The new address must be verified before the panel is available again.
- **Lozinka** / **Password**: **Trenutna lozinka** / **Current password**, **Nova lozinka** / **New password**, **Potvrdi novu lozinku** / **Confirm new password**. Leave these blank to keep the current password.

**Sačuvaj** / **Save** and **Otkaži** / **Cancel** become active only after a change. Cancel restores the values that were on the page.

### Delete the account

On the same **Korisnik** / **User** page, lower down: **Brisanje naloga** / **Delete account**.

Intro: **Kada obrišete nalog, svi resursi i podaci biće trajno uklonjeni. Prije brisanja preuzmite sve podatke ili informacije koje želite da sačuvate.** / **Once your account is deleted, all of its resources and data will be permanently deleted. Before deleting your account, please download any data or information that you wish to retain.**

Button **Obriši nalog** / **Delete account**. The confirm question is **Jeste li sigurni da želite da obrišete nalog?** / **Are you sure you want to delete your account?** The body is **Brisanjem naloga trajno uklanjate sve podatke. Unesite lozinku da potvrdite da želite trajno obrisati nalog.** / **Once your account is deleted, all of its resources and data will be permanently deleted. Please enter your password to confirm you would like to permanently delete your account.** The user must enter the current password. Field **Lozinka** / **Password**. Confirm, or **Otkaži** / **Cancel**.

If the site cannot delete the account, the person stays signed in. The password field shows **Nalog se trenutno ne može obrisati zbog povezanih podataka. Kontaktirajte podršku.** There is no separate English sentence for that message. Do not guess which reservation or vehicle caused it. They should write to `bus@kotor.me`.

## 14. Support

For anything this guide cannot settle — a card charge without a reservation, an advance balance that does not match the bank, a missing verification email after several resends, a rejection email that never arrived and no reason in the panel, or a menu item that is grey and cannot be opened — write to **bus@kotor.me**.

Do not invent a second support address. Do not tell the user that this chat submitted, paid, or changed anything.
