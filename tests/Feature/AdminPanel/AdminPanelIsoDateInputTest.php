<?php

namespace Tests\Feature\AdminPanel;

use App\Models\Admin;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

final class AdminPanelIsoDateInputTest extends TestCase
{
    use RefreshDatabase;

    private function seedAdmin(): Admin
    {
        return Admin::query()->create([
            'username' => 'isodateadmin',
            'email' => 'iso-date-admin@example.com',
            'password' => bcrypt('secret-password-iso'),
            'control_access' => false,
            'admin_access' => true,
        ]);
    }

    private function assertIsoDateInputMarkup(string $html, string $fieldName): void
    {
        $this->assertStringContainsString('name="'.$fieldName.'"', $html);
        $this->assertStringContainsString('data-iso-date-display', $html);
        $this->assertStringContainsString('data-iso-date-hidden', $html);
        $this->assertStringContainsString('placeholder="dd/mm/yyyy"', $html);
        $this->assertStringNotContainsString('name="'.$fieldName.'" type="date"', $html);
    }

    public function test_admin_reservations_search_uses_iso_date_inputs(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-06-25 12:00:00', 'Europe/Podgorica'));

        $admin = $this->seedAdmin();
        $this->actingAs($admin, 'panel_admin');

        $html = $this->get(route('panel_admin.reservations', [
            'date_single' => '2026-07-01',
        ], false))
            ->assertOk()
            ->getContent();

        $this->assertIsoDateInputMarkup($html, 'date_single');
        $this->assertIsoDateInputMarkup($html, 'date_from');
        $this->assertIsoDateInputMarkup($html, 'date_to');
        $this->assertStringContainsString('value="2026-07-01"', $html);
        $this->assertStringContainsString('01/07/2026', $html);

        Carbon::setTestNow();
    }

    public function test_admin_reservations_date_filter_submits_yyyy_mm_dd(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-06-25 12:00:00', 'Europe/Podgorica'));

        $admin = $this->seedAdmin();
        $this->actingAs($admin, 'panel_admin');

        $this->get(route('panel_admin.reservations', [
            'date_single' => '2026-07-20',
            'merchant_transaction_id' => 'mt-nonexistent-iso-date',
        ], false))
            ->assertOk()
            ->assertSee('value="2026-07-20"', false);

        Carbon::setTestNow();
    }

    public function test_free_reservations_fzbr_review_filters_use_iso_date_inputs(): void
    {
        $admin = $this->seedAdmin();
        $this->actingAs($admin, 'panel_admin');

        $html = $this->get(route('panel_admin.free-reservations', [
            'fzbr_review' => 'approved',
            'fzbr_date_from' => '2026-06-01',
            'fzbr_date_to' => '2026-06-10',
        ], false))
            ->assertOk()
            ->getContent();

        $this->assertIsoDateInputMarkup($html, 'fzbr_date_from');
        $this->assertIsoDateInputMarkup($html, 'fzbr_date_to');
        $this->assertStringContainsString('01/06/2026', $html);
    }

    public function test_admin_reports_page_uses_iso_date_inputs_for_daily_and_period(): void
    {
        $admin = $this->seedAdmin();
        $this->actingAs($admin, 'panel_admin');

        $html = $this->get(route('panel_admin.reports', [], false))
            ->assertOk()
            ->getContent();

        $this->assertIsoDateInputMarkup($html, 'date');
        $this->assertIsoDateInputMarkup($html, 'date_from');
        $this->assertIsoDateInputMarkup($html, 'date_to');
        $this->assertStringContainsString('isoHiddenValue(id)', $html);
    }

    public function test_admin_analytics_filters_use_iso_date_inputs(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-06-25 12:00:00', 'Europe/Podgorica'));

        $admin = $this->seedAdmin();
        $this->actingAs($admin, 'panel_admin');

        $html = $this->get(route('panel_admin.analytics', [
            'show' => 1,
            'date_from' => '2026-06-25',
            'date_to' => '2026-07-01',
        ], false))
            ->assertOk()
            ->getContent();

        $this->assertIsoDateInputMarkup($html, 'date_from');
        $this->assertIsoDateInputMarkup($html, 'date_to');
        $this->assertStringContainsString('25/06/2026', $html);

        Carbon::setTestNow();
    }

    public function test_fzbr_review_reset_filter_link_points_to_clean_route(): void
    {
        $admin = $this->seedAdmin();
        $this->actingAs($admin, 'panel_admin');

        $this->get(route('panel_admin.free-reservations', [
            'fzbr_review' => 'approved',
            'fzbr_date_from' => '2026-06-01',
            'fzbr_date_to' => '2026-06-01',
        ], false))
            ->assertOk()
            ->assertSee('Reset filter', false)
            ->assertSee(route('panel_admin.free-reservations', [], false), false);
    }

    public function test_guest_and_agency_reservation_forms_remain_unchanged(): void
    {
        $guestHtml = $this->get(route('guest.reserve', [], false))
            ->assertOk()
            ->getContent();
        $this->assertStringContainsString('data-reservation-date', $guestHtml);
        $this->assertStringNotContainsString('data-iso-date-input', $guestHtml);

        $agency = User::factory()->create(['email_verified_at' => now()]);
        $this->actingAs($agency);

        $panelHtml = $this->get(route('panel.reservations', [], false))
            ->assertOk()
            ->getContent();
        $this->assertStringContainsString('data-reservation-date', $panelHtml);
        $this->assertStringNotContainsString('id="date_from_display"', $panelHtml);
    }

    public function test_agency_statistics_still_uses_iso_date_inputs(): void
    {
        $agency = User::factory()->create(['email_verified_at' => now(), 'lang' => 'cg']);
        $this->actingAs($agency);

        $html = $this->get(route('panel.statistics', [
            'date_from' => '2026-05-16',
            'date_to' => '2026-05-20',
        ], false))
            ->assertOk()
            ->getContent();

        $this->assertIsoDateInputMarkup($html, 'date_from');
        $this->assertIsoDateInputMarkup($html, 'date_to');
    }
}
