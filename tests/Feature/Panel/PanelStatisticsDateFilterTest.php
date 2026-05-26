<?php

namespace Tests\Feature\Panel;

use App\Models\ListOfTimeSlot;
use App\Models\Reservation;
use App\Models\User;
use App\Models\VehicleType;
use App\Models\VehicleTypeTranslation;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PanelStatisticsDateFilterTest extends TestCase
{
    use RefreshDatabase;

    public function test_statistics_filters_by_closed_interval_and_only_current_user(): void
    {
        $slot = ListOfTimeSlot::query()->create(['time_slot' => '10:00 - 10:20']);
        $vt = VehicleType::query()->create(['price' => 10]);
        VehicleTypeTranslation::query()->create([
            'vehicle_type_id' => $vt->id,
            'locale' => 'cg',
            'name' => 'VT1',
            'description' => 'd1',
        ]);

        $u1 = User::query()->create([
            'name' => 'A',
            'email' => 'a@example.com',
            'password' => bcrypt('secret'),
            'country' => 'ME',
            'lang' => 'cg',
            'email_verified_at' => now(),
        ]);
        $u2 = User::query()->create([
            'name' => 'B',
            'email' => 'b@example.com',
            'password' => bcrypt('secret'),
            'country' => 'ME',
            'lang' => 'cg',
            'email_verified_at' => now(),
        ]);

        // Realized reservations (past days).
        Reservation::query()->create([
            'user_id' => $u1->id,
            'merchant_transaction_id' => 'mt-stat-1',
            'drop_off_time_slot_id' => $slot->id,
            'pick_up_time_slot_id' => $slot->id,
            'reservation_date' => '2026-04-10',
            'user_name' => 'A',
            'country' => 'ME',
            'license_plate' => 'KO1',
            'vehicle_type_id' => $vt->id,
            'email' => 'a@example.com',
            'status' => 'paid',
            'invoice_amount' => '10.00',
            'email_sent' => Reservation::EMAIL_NOT_SENT,
        ]);
        Reservation::query()->create([
            'user_id' => $u1->id,
            'merchant_transaction_id' => 'mt-stat-2',
            'drop_off_time_slot_id' => $slot->id,
            'pick_up_time_slot_id' => $slot->id,
            'reservation_date' => '2026-04-15',
            'user_name' => 'A',
            'country' => 'ME',
            'license_plate' => 'KO1',
            'vehicle_type_id' => $vt->id,
            'email' => 'a@example.com',
            'status' => 'paid',
            'invoice_amount' => '20.00',
            'email_sent' => Reservation::EMAIL_NOT_SENT,
        ]);
        Reservation::query()->create([
            'user_id' => $u2->id,
            'merchant_transaction_id' => 'mt-stat-other',
            'drop_off_time_slot_id' => $slot->id,
            'pick_up_time_slot_id' => $slot->id,
            'reservation_date' => '2026-04-15',
            'user_name' => 'B',
            'country' => 'ME',
            'license_plate' => 'KO9',
            'vehicle_type_id' => $vt->id,
            'email' => 'b@example.com',
            'status' => 'paid',
            'invoice_amount' => '999.00',
            'email_sent' => Reservation::EMAIL_NOT_SENT,
        ]);

        Carbon::setTestNow(Carbon::parse('2026-04-20 12:00:00', 'Europe/Podgorica'));

        $this->actingAs($u1);

        $resp = $this->get(route('panel.statistics', [
            'date_from' => '2026-04-15',
            'date_to' => '2026-04-15',
        ], false));
        $resp->assertOk();

        // Only the 2026-04-15 reservation for u1 should count.
        $resp->assertSee('20.00');
        $resp->assertSee('1'); // visit_count
        $resp->assertDontSee('999.00');
    }

    public function test_statistics_page_shows_english_filter_labels_when_locale_is_en(): void
    {
        $u = User::factory()->create([
            'email_verified_at' => now(),
            'lang' => 'en',
        ]);

        $this->actingAs($u)
            ->withSession(['locale' => 'en']);

        $resp = $this->get(route('panel.statistics', [], false))->assertOk();

        $resp->assertSee('From', false);
        $resp->assertSee('To', false);
        $resp->assertSee('Apply', false);
        $resp->assertDontSee('Primijeni', false);
        $resp->assertDontSee('Od', false);
        $resp->assertDontSee('Do', false);
    }

    public function test_statistics_page_uses_localized_date_text_inputs(): void
    {
        $u = User::query()->create([
            'name' => 'A',
            'email' => 'a@example.com',
            'password' => bcrypt('secret'),
            'country' => 'ME',
            'lang' => 'cg',
            'email_verified_at' => now(),
        ]);

        $this->actingAs($u);

        $html = $this->get(route('panel.statistics', [], false))->assertOk()->getContent();

        $this->assertStringContainsString('placeholder="dd/mm/yyyy"', $html);
        $this->assertStringNotContainsString('type="date"', $html);
    }

    public function test_validation_rejects_from_after_to(): void
    {
        $u = User::query()->create([
            'name' => 'A',
            'email' => 'a@example.com',
            'password' => bcrypt('secret'),
            'country' => 'ME',
            'lang' => 'cg',
            'email_verified_at' => now(),
        ]);

        $this->actingAs($u);

        $resp = $this->get(route('panel.statistics', [
            'date_from' => '2026-05-10',
            'date_to' => '2026-05-01',
        ], false));
        $resp->assertSessionHasErrors(['date_to']);
    }
}

