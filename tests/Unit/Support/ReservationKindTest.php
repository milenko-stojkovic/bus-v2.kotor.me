<?php

namespace Tests\Unit\Support;

use App\Support\ReservationKind;
use PHPUnit\Framework\TestCase;

final class ReservationKindTest extends TestCase
{
    public function test_all_kinds_are_defined(): void
    {
        $this->assertContains(ReservationKind::TIME_SLOTS, ReservationKind::ALL);
        $this->assertContains(ReservationKind::DAILY_TICKET, ReservationKind::ALL);
        $this->assertCount(2, ReservationKind::ALL);
    }
}
