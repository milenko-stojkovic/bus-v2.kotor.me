<?php

namespace Tests\Feature\Control;

use App\Models\Admin;
use App\Models\DailyParkingData;
use App\Models\ListOfTimeSlot;
use App\Models\SystemConfig;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

final class DailyCapacityChartsRenderTest extends TestCase
{
    use RefreshDatabase;

    public function test_control_dashboard_renders_today_and_tomorrow_capacity_charts_and_uses_daily_parking_data_reserved_pending(): void
    {
        for ($i = 1; $i <= 41; $i++) {
            ListOfTimeSlot::query()->create(['time_slot' => sprintf('%02d:00 - %02d:20', ($i - 1) % 24, ($i - 1) % 24)]);
        }

        SystemConfig::setValue('available_parking_slots', 5);

        $tz = (string) config('reservations.operations_timezone', 'Europe/Podgorica');
        $today = Carbon::now($tz)->startOfDay()->toDateString();

        $slot2 = ListOfTimeSlot::query()->orderBy('id')->skip(1)->firstOrFail();
        DailyParkingData::query()->create([
            'date' => $today,
            'time_slot_id' => $slot2->id,
            'capacity' => 5,
            'reserved' => 1,
            'pending' => 2,
            'is_blocked' => false,
        ]);

        $control = Admin::query()->create([
            'username' => 'controlcap',
            'email' => 'control-cap@example.com',
            'password' => bcrypt('secret-password-ctl'),
            'control_access' => true,
            'admin_access' => false,
        ]);

        $this->actingAs($control, 'control');

        $res = $this->get(route('control.dashboard', [], false));
        $res->assertOk();

        $res->assertSee('capacity-chart-control-today', false);
        $res->assertSee('capacity-chart-control-tomorrow', false);

        $html = $res->getContent();
        $this->assertStringContainsString('"capacity":5', $html);
        $this->assertStringContainsString('"slot_number":41', $html);
        $this->assertStringContainsString('"reserved":1', $html);
        $this->assertStringContainsString('"pending":2', $html);
        $this->assertStringContainsString('"total":3', $html);
        $this->assertStringContainsString('Ukupno rezervacija', $html);
    }
}

