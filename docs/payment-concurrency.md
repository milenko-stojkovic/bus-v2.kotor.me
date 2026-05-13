# Payment concurrency rules

Pravila za bezbedno paralelno plaćanje i obrada callback-a.

---

## Jedinstven merchant_transaction_id

- **Svaki pokušaj plaćanja** mora imati **jedinstven** `merchant_transaction_id`.
- Enforcment:
  - **Checkout:** dupli klik → backend vrati postojeći payment link; baza enforcuje unique.
  - **Baza:** `temp_data.merchant_transaction_id` UNIQUE; `reservations.merchant_transaction_id` UNIQUE.
- `merchant_transaction_id` se šalje gatewayu (npr. Bankart) pri kreiranju sesije i vraća u callback-u – koristi se kao idempotency key.

---

## Callback endpoint

Callback (**`POST /api/payment/callback`**, API ruta; **samo machine-to-machine**, frontend ga nikad ne poziva) mora:

1. **Validirati potpis** – pre bilo kakve obrade. Ako potpis nije validan → **HTTP 400**, job se **ne** dispatch-uje (v. `PaymentCallbackController`; opciono audit u `temp_data`).
   - Implementacija: `CallbackSignatureValidator` (Fake = uvek prolazi; Real = Bankart HMAC prema `RealCallbackSignatureValidator` kada je `BANK_DRIVER=bankart`).
   - Config: `services.bankart.shared_secret` (env `BANKART_SHARED_SECRET`) za real gateway.

2. **Dispatch queue job-a sa merchant_transaction_id** – payload koji se šalje job-u sadrži `merchant_transaction_id` i `status`. Nema obrade rezultata u HTTP request-u.

3. **Biti idempotentan na nivou sistema** – sam endpoint samo validira i dispatch-uje; idempotentnost je u job-u: dupli callback-i vode u isti job (ShouldBeUnique) koji rezervaciju kreira samo jednom.

---

## Queue job (PaymentCallbackJob)

Job mora:

- **Pronaći temp_data po merchant_transaction_id** – jedini lookup po ovom ključu; nema globalnog stanja.
- **Kreirati rezervaciju samo jednom** – pre kreiranja provera `Reservation::where('merchant_transaction_id', $txId)->exists()`; dodatno DB UNIQUE na `reservations.merchant_transaction_id` sprečava duplikate.
- **Ignorisati duple callback-e** – ako rezervacija već postoji ili `temp_data.status !== pending`, job odmah izlazi (bez kreiranja, bez ažuriranja).

Idempotency key za queue: `merchant_transaction_id` (ShouldBeUnique → samo jedna instanca job-a po tom ID-u u toku).

---

## Nema deljenog globalnog stanja

- Svako plaćanje je identifikovano samo sa **merchant_transaction_id**.
- Nema globalnih lock-ova, nema deljenih promenljivih između plaćanja.
- temp_data i reservations su po slogu; sve operacije su keyed by merchant_transaction_id.

---

## Race condition: dva korisnika isti slot u istoj sekundi

- **Problem:** dva korisnika istovremeno kupuju poslednji slobodan (date, time_slot) → oba vide kapacitet 1, oba kreiraju temp_data i increment pending → overbooking.
- **Rešenje: atomic check + lock**
  - U **checkout** se koristi **transakcija** sa **SELECT ... FOR UPDATE** na red `daily_parking_data` (Laravel: `lockForUpdate()`).
  - Sekvenca: lock red za (date, time_slot_id) → provera `availableCapacity() >= 1` → kreiranje temp_data → increment pending → commit. Drugi request čeka lock, zatim vidi kapacitet 0 → 422.
  - **Rezervacija** se i dalje pravi **tek nakon SUCCESS** plaćanja (callback). **Hold** slot-a je u temp_data sa TTL-om (cron expire pending).
- Unique index na (slot_id + date + drop_off + pick_up) ne ograničava kapacitet (više rezervacija po slotu je dozvoljeno); kapacitet se enforcuje preko `daily_parking_data.pending + reserved <= capacity` uz lock.

---

## Paralelna plaćanja

Sistem podržava **više paralelnih plaćanja** bez konflikata:

- Različiti korisnici / isti korisnik – različiti `merchant_transaction_id` za svaki pokušaj.
- Callback-i se obrađuju nezavisno (jedan job po merchant_transaction_id).
- Nema međusobnog blokiranja između plaćanja (osim kratkog locka na daily_parking_data pri claim-u slota).

---

## Provera (checklist)

- [x] Svaki pokušaj plaćanja ima jedinstven merchant_transaction_id (DB unique; dupli klik → postojeći link).
- [x] Race condition isti slot: atomic lock (SELECT FOR UPDATE) na daily_parking_data u checkout transakciji.
- [x] merchant_transaction_id se šalje gatewayu i vraća u callback-u.
- [x] Callback: validacija potpisa pre dispatch-a; invalid → 400, job se ne šalje.
- [x] Callback: dispatch job-a sa merchant_transaction_id (u payload-u).
- [x] Callback je idempotentan (posledica idempotentnog job-a).
- [x] Job: find temp_data by merchant_transaction_id; create reservation only once; ignore duplicate callbacks.
- [x] Nema deljenog globalnog stanja između plaćanja.
- [x] Sistem podržava više paralelnih plaćanja bezbedno.
