<?php

namespace Tests\Unit\Pricing;

use App\Models\PriceChangeSchedule;
use App\Models\PriceChangeScheduleLine;
use App\Models\VehicleType;
use App\Services\Pricing\VehicleTariff;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

final class VehicleTariffTest extends TestCase
{
    use RefreshDatabase;

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    public function test_due_schedule_applies_at_midnight_and_repeat_is_a_noop(): void
    {
        $type = VehicleType::query()->create(['price' => 15]);
        $unchanged = VehicleType::query()->create(['price' => 40]);
        $this->schedule($type, '2026-12-02', '15.00', '18.00');

        Carbon::setTestNow(Carbon::parse('2026-12-01 23:59:59', 'Europe/Podgorica'));
        $this->assertSame(0, app(VehicleTariff::class)->applyDue());
        $this->assertSame('15.00', (string) $type->fresh()->price);

        Carbon::setTestNow(Carbon::parse('2026-12-02 00:00:00', 'Europe/Podgorica'));
        $this->assertSame(1, app(VehicleTariff::class)->applyDue());
        $this->assertSame(0, app(VehicleTariff::class)->applyDue());
        $this->assertSame('18.00', (string) $type->fresh()->price);
        $this->assertSame('40.00', (string) $unchanged->fresh()->price);
        $this->assertSame(PriceChangeSchedule::STATUS_EFFECTIVE, PriceChangeSchedule::query()->first()->status);
        $this->assertNull(PriceChangeSchedule::query()->first()->pending_slot);
    }

    public function test_card_blackout_is_thirty_minutes_and_advance_warning_is_the_last_fifteen(): void
    {
        $type = VehicleType::query()->create(['price' => 15]);
        $this->schedule($type, '2026-12-02', '15.00', '20.00');
        $tariff = app(VehicleTariff::class);

        Carbon::setTestNow(Carbon::parse('2026-12-01 23:29:59', 'Europe/Podgorica'));
        $before = $tariff->quote($type->id);
        $this->assertFalse($before->cardBlocked);
        $this->assertFalse($before->advanceWarning);
        $this->assertSame('15.00', $before->payable);

        Carbon::setTestNow(Carbon::parse('2026-12-01 23:30:00', 'Europe/Podgorica'));
        $blackout = $tariff->quote($type->id);
        $this->assertTrue($blackout->cardBlocked);
        $this->assertFalse($blackout->advanceWarning);
        $this->assertSame('15.00', $blackout->payable);

        Carbon::setTestNow(Carbon::parse('2026-12-01 23:45:00', 'Europe/Podgorica'));
        $warning = $tariff->quote($type->id);
        $this->assertTrue($warning->cardBlocked);
        $this->assertTrue($warning->advanceWarning);
        $this->assertSame('15.00', $warning->payable);
        $this->assertSame('20.00', $warning->incoming);
        $this->assertSame('20.00', $warning->balanceRequired());

        Carbon::setTestNow(Carbon::parse('2026-12-02 00:00:00', 'Europe/Podgorica'));
        $after = $tariff->quote($type->id);
        $this->assertFalse($after->cardBlocked);
        $this->assertFalse($after->advanceWarning);
        $this->assertSame('20.00', $after->payable);
    }

    public function test_cancelled_schedule_is_not_applied(): void
    {
        $type = VehicleType::query()->create(['price' => 15]);
        $schedule = $this->schedule($type, '2026-12-02', '15.00', '20.00');
        $schedule->update([
            'status' => PriceChangeSchedule::STATUS_CANCELLED,
            'pending_slot' => null,
        ]);

        Carbon::setTestNow(Carbon::parse('2026-12-02 00:05:00', 'Europe/Podgorica'));
        $this->assertSame(0, app(VehicleTariff::class)->applyDue());
        $this->assertSame('15.00', (string) $type->fresh()->price);
    }

    private function schedule(VehicleType $type, string $date, string $before, string $after): PriceChangeSchedule
    {
        $schedule = PriceChangeSchedule::query()->create([
            'effective_on' => $date,
            'status' => PriceChangeSchedule::STATUS_SCHEDULED,
            'pending_slot' => PriceChangeSchedule::PENDING_SLOT,
        ]);
        PriceChangeScheduleLine::query()->create([
            'price_change_schedule_id' => $schedule->id,
            'vehicle_type_id' => $type->id,
            'price_before' => $before,
            'price_after' => $after,
        ]);

        return $schedule;
    }
}
