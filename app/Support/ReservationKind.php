<?php

namespace App\Support;

/**
 * Reservation product kind (agency paid checkout and persisted reservations).
 *
 * Invariant (enforced in checkout and admin flows from Phase 2 onward):
 *
 * - {@see self::TIME_SLOTS}: drop_off_time_slot_id and pick_up_time_slot_id must both be set.
 * - {@see self::DAILY_TICKET}: drop_off_time_slot_id and pick_up_time_slot_id must both be null.
 *
 * Daily ticket does not use daily_parking_data and does not consume time-slot capacity.
 */
final class ReservationKind
{
    public const TIME_SLOTS = 'time_slots';

    public const DAILY_TICKET = 'daily_ticket';

    /** @var list<string> */
    public const ALL = [
        self::TIME_SLOTS,
        self::DAILY_TICKET,
    ];
}
