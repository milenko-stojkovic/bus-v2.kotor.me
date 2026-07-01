<?php

namespace Tests\Feature\AdminPanel;

use App\Models\Admin;
use App\Models\ListOfTimeSlot;
use App\Models\Reservation;
use App\Models\User;
use App\Models\VehicleType;
use App\Services\Pdf\PaidInvoicePdfGenerator;
use App\Support\ReservationKind;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;
use Tests\TestCase;

final class AdminPanelReservationShowTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'agency_statistics.v1_cutover_at' => '2099-01-01 00:00:00',
            'agency_statistics.heuristic_cache_ttl' => 0,
        ]);
        Cache::flush();
    }

    private function seedAdmin(): Admin
    {
        return Admin::query()->create([
            'username' => 'showadmin',
            'email' => 'show-admin@example.com',
            'password' => bcrypt('secret-password-show'),
            'control_access' => false,
            'admin_access' => true,
        ]);
    }

    private function seedSlot(): ListOfTimeSlot
    {
        return ListOfTimeSlot::query()->create(['time_slot' => '10:00 - 10:20']);
    }

    /**
     * @param  array<string, mixed>  $overrides
     */
    private function createPastReservation(array $overrides = []): Reservation
    {
        $slot = $this->seedSlot();
        $vt = VehicleType::query()->first() ?? VehicleType::query()->create(['price' => 20]);

        return Reservation::query()->create(array_merge([
            'user_name' => 'Past Guest',
            'country' => 'ME',
            'license_plate' => 'KO'.Str::upper(Str::random(4)).'AA',
            'email' => 'past-guest@example.com',
            'vehicle_type_id' => $vt->id,
            'drop_off_time_slot_id' => $slot->id,
            'pick_up_time_slot_id' => $slot->id,
            'reservation_date' => now()->subMonths(3)->toDateString(),
            'status' => 'paid',
            'invoice_amount' => '20.00',
            'merchant_transaction_id' => 'MTID-'.Str::upper(Str::random(8)),
            'reservation_kind' => ReservationKind::TIME_SLOTS,
            'email_sent' => Reservation::EMAIL_SENT,
            'invoice_sent_at' => now()->subMonths(3),
            'fiscal_jir' => 'JIR-TEST-123',
            'fiscal_ikof' => 'IKOF-TEST-456',
            'fiscal_qr' => 'QR-TEST-789',
            'created_by_admin' => false,
            'created_at' => now()->subYear(),
            'updated_at' => now()->subYear(),
        ], $overrides));
    }

    public function test_a_admin_can_click_reservation_id_from_agency_detail_and_gets_200(): void
    {
        $admin = $this->seedAdmin();
        $agency = User::factory()->create([
            'email' => 'agency-link@montetravel.me',
            'name' => 'Link Agency',
        ]);

        $reservation = $this->createPastReservation([
            'user_id' => null,
            'email' => 'agency-link@montetravel.me',
            'license_plate' => 'KOLINK1',
        ]);

        $agencyUrl = route('panel_admin.agencies.show', $agency, false);
        $showUrl = route('panel_admin.reservations.show', $reservation, false);

        $html = $this->actingAs($admin, 'panel_admin')
            ->get($agencyUrl)
            ->assertOk()
            ->assertSee($showUrl, false)
            ->getContent();

        $this->assertStringContainsString('#'.$reservation->id, $html);

        $this->actingAs($admin, 'panel_admin')
            ->get($showUrl)
            ->assertOk()
            ->assertSee('Rezervacija #'.$reservation->id, false);
    }

    public function test_b_detail_page_is_read_only_and_shows_main_reservation_data(): void
    {
        $admin = $this->seedAdmin();
        $reservation = $this->createPastReservation([
            'license_plate' => 'KODETAIL',
            'merchant_transaction_id' => 'MTID-DETAIL-001',
            'fiscal_jir' => 'JIR-VISIBLE',
        ]);

        $this->actingAs($admin, 'panel_admin')
            ->get(route('panel_admin.reservations.show', $reservation, false))
            ->assertOk()
            ->assertSee('Samo pregled', false)
            ->assertSee('KODETAIL', false)
            ->assertSee('MTID-DETAIL-001', false)
            ->assertSee('JIR-VISIBLE', false)
            ->assertSee('Guest', false)
            ->assertDontSee('method="post"', false)
            ->assertDontSee('reservations.update', false);
    }

    public function test_c_admin_can_view_reservation_from_another_agency(): void
    {
        $admin = $this->seedAdmin();
        $agencyA = User::factory()->create(['name' => 'Agency Alpha']);
        $agencyB = User::factory()->create(['name' => 'Agency Beta']);

        $reservation = $this->createPastReservation([
            'user_id' => $agencyB->id,
            'user_name' => $agencyB->name,
            'email' => $agencyB->email,
            'license_plate' => 'KOOTHER1',
        ]);

        $this->actingAs($admin, 'panel_admin')
            ->get(route('panel_admin.agencies.show', $agencyA, false))
            ->assertOk();

        $this->actingAs($admin, 'panel_admin')
            ->get(route('panel_admin.reservations.show', $reservation, false))
            ->assertOk()
            ->assertSee('Agency Beta', false)
            ->assertSee('KOOTHER1', false);
    }

    public function test_d_agency_user_cannot_access_admin_detail_route(): void
    {
        $agencyUser = User::factory()->create();
        $reservation = $this->createPastReservation([
            'user_id' => $agencyUser->id,
            'email' => $agencyUser->email,
        ]);

        $this->actingAs($agencyUser)
            ->get(route('panel_admin.reservations.show', $reservation, false))
            ->assertRedirect(route('panel_admin.login', [], false));
    }

    public function test_e_pdf_link_still_works_for_admin(): void
    {
        $this->mock(PaidInvoicePdfGenerator::class, function ($mock): void {
            $mock->shouldReceive('renderBinary')->andReturn('%PDF-1.4 test');
        });

        $admin = $this->seedAdmin();
        $reservation = $this->createPastReservation([
            'status' => 'paid',
            'fiscal_jir' => 'JIR-PDF',
        ]);

        $this->actingAs($admin, 'panel_admin')
            ->get(route('panel_admin.reservations.pdf', $reservation, false))
            ->assertOk();
    }

    public function test_f_agency_panel_routes_remain_protected(): void
    {
        $this->mock(PaidInvoicePdfGenerator::class, function ($mock): void {
            $mock->shouldReceive('renderBinary')->andReturn('%PDF-1.4 test');
        });

        $owner = User::factory()->create();
        $other = User::factory()->create();

        $ownReservation = $this->createPastReservation([
            'user_id' => $owner->id,
            'user_name' => $owner->name,
            'email' => $owner->email,
            'license_plate' => 'KOOWN001',
        ]);

        $otherReservation = $this->createPastReservation([
            'user_id' => $other->id,
            'user_name' => $other->name,
            'email' => $other->email,
            'license_plate' => 'KOOTHER2',
        ]);

        $this->actingAs($owner)
            ->get(route('panel_admin.reservations.show', $ownReservation, false))
            ->assertRedirect(route('panel_admin.login', [], false));

        $this->actingAs($owner)
            ->get(route('panel.reservations.invoice.view', ['id' => $ownReservation->id], false))
            ->assertOk();

        $this->actingAs($owner)
            ->get(route('panel.reservations.invoice.view', ['id' => $otherReservation->id], false))
            ->assertNotFound();
    }

    public function test_realized_reservation_edit_still_returns_403(): void
    {
        $admin = $this->seedAdmin();
        $reservation = $this->createPastReservation();

        $this->actingAs($admin, 'panel_admin')
            ->get(route('panel_admin.reservations.edit', $reservation, false))
            ->assertForbidden();
    }
}
