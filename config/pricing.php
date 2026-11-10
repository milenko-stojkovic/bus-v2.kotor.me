<?php

return [
    /*
    | Tariff instants are calendar times in this zone. Effective time is 00:00
    | on the scheduled date. Do not follow APP_TIMEZONE if it is overridden.
    */
    'timezone' => 'Europe/Podgorica',

    /*
    | No new Bankart reservation attempts in this window before a scheduled tariff.
    |
    | Production (verified): RESERVATIONS_PENDING_EXPIRE_MINUTES=15, Plesk runs
    | schedule:run every minute, and reservations:expire-pending is every five
    | minutes. A new attempt can therefore stay pending for about 20 minutes
    | (15 + one scheduler interval) and still complete as a normal success if
    | the bank responds before the expire command sets status=expired.
    |
    | 30 minutes covers that window with margin. It does not expire pending rows,
    | cancel Bankart sessions, or change the payment state machine. A longer
    | expire threshold or a slower expire command needs a longer blackout.
    */
    'card_blackout_minutes' => (int) env('PRICE_CHANGE_CARD_BLACKOUT_MINUTES', 30),

    /*
    | Advance reservations stay available during the card blackout. During this
    | shorter window the booking page warns that the price changes at midnight,
    | and the balance must cover both the amount that will be debited and the
    | incoming tariff. The debit itself is still the tariff in force at execution.
    */
    'advance_warning_minutes' => (int) env('PRICE_CHANGE_ADVANCE_WARNING_MINUTES', 15),
];
