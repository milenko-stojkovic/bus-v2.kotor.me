<?php

namespace Tests\Feature\AdminPanel;

use App\Models\Admin;
use App\Models\AgencyAdvanceTopup;
use App\Models\AgencyAdvanceTransaction;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

final class AdminPanelAdvanceInsightTest extends TestCase
{
    use RefreshDatabase;

    private function seedAdmin(): Admin
    {
        return Admin::query()->create([
            'username' => 'advinsightadmin',
            'email' => 'adv-insight@example.com',
            'password' => bcrypt('secret-password-adv-ins'),
            'control_access' => false,
            'admin_access' => true,
        ]);
    }

    public function test_advance_insight_returns_404_when_feature_off(): void
    {
        config(['features.advance_payments' => false]);

        $admin = $this->seedAdmin();
        $this->actingAs($admin, 'panel_admin');

        $this->get(route('panel_admin.insight.advance', [], false))->assertNotFound();
    }

    public function test_advance_insight_page_renders_with_tabs(): void
    {
        config(['features.advance_payments' => true]);

        $admin = $this->seedAdmin();
        $this->actingAs($admin, 'panel_admin');

        $this->get(route('panel_admin.insight.advance', [], false))
            ->assertOk()
            ->assertSee('Avansna uplata', false)
            ->assertSee('Plaćanje rezervacije', false);
    }

    public function test_reservation_insight_shows_advance_tab_when_feature_on(): void
    {
        config(['features.advance_payments' => true]);

        $admin = $this->seedAdmin();
        $this->actingAs($admin, 'panel_admin');

        $this->get(route('panel_admin.insight', [], false))
            ->assertOk()
            ->assertSee('Avansna uplata', false);
    }

    public function test_advance_insight_search_lists_topup_and_links_to_detail(): void
    {
        config(['features.advance_payments' => true]);

        $admin = $this->seedAdmin();
        $this->actingAs($admin, 'panel_admin');

        $agency = User::factory()->create([
            'name' => 'Agencija Alpha',
            'email' => 'alpha-adv@example.com',
        ]);

        AgencyAdvanceTopup::query()->create([
            'agency_user_id' => $agency->id,
            'merchant_transaction_id' => 'mt-adv-ins-1',
            'amount' => '150.00',
            'status' => AgencyAdvanceTopup::STATUS_PAID,
            'paid_at' => now(),
        ]);

        $this->get(route('panel_admin.insight.advance', [
            'search' => 1,
            'merchant_transaction_id' => 'mt-adv-ins-1',
        ], false))
            ->assertOk()
            ->assertSee('mt-adv-ins-1', false)
            ->assertSee('Agencija Alpha', false)
            ->assertSee('Detalji', false);
    }

    public function test_advance_insight_detail_shows_topup_ledger_and_timeline(): void
    {
        config(['features.advance_payments' => true]);

        $admin = $this->seedAdmin();
        $this->actingAs($admin, 'panel_admin');

        $agency = User::factory()->create([
            'name' => 'Beta Agency',
            'email' => 'beta-adv@example.com',
        ]);

        $topup = AgencyAdvanceTopup::query()->create([
            'agency_user_id' => $agency->id,
            'merchant_transaction_id' => 'mt-adv-ins-2',
            'amount' => '200.00',
            'status' => AgencyAdvanceTopup::STATUS_PAID,
            'paid_at' => now(),
            'bank_payload' => ['driver' => 'test'],
        ]);

        AgencyAdvanceTransaction::query()->create([
            'agency_user_id' => $agency->id,
            'amount' => '200.00',
            'type' => AgencyAdvanceTransaction::TYPE_TOPUP,
            'reference_type' => 'advance_topup',
            'reference_id' => $topup->id,
            'merchant_transaction_id' => 'mt-adv-ins-2',
            'note' => 'topup',
        ]);

        $logDir = storage_path('logs');
        @mkdir($logDir, 0777, true);
        $today = Carbon::now()->format('Y-m-d');
        $path = $logDir.DIRECTORY_SEPARATOR.'payments-'.$today.'.log';
        file_put_contents(
            $path,
            '[2026-06-19 12:00:00] production.INFO: advance_topup_paid {"merchant_transaction_id":"mt-adv-ins-2","topup_id":'.$topup->id.'}'."\n",
            FILE_APPEND
        );

        $this->get(route('panel_admin.insight.advance.show', ['merchantTransactionId' => 'mt-adv-ins-2'], false))
            ->assertOk()
            ->assertSee('mt-adv-ins-2', false)
            ->assertSee('Beta Agency', false)
            ->assertSee('agency_advance_topups', false)
            ->assertSee('agency_advance_transactions', false)
            ->assertSee('advance_topup_paid', false)
            ->assertSee('Otvori detalj agencije', false);
    }

    public function test_advance_insight_detail_back_link_keeps_search_query(): void
    {
        config(['features.advance_payments' => true]);

        $admin = $this->seedAdmin();
        $this->actingAs($admin, 'panel_admin');

        $agency = User::factory()->create();

        AgencyAdvanceTopup::query()->create([
            'agency_user_id' => $agency->id,
            'merchant_transaction_id' => 'mt-adv-back-1',
            'amount' => '50.00',
            'status' => AgencyAdvanceTopup::STATUS_PENDING,
        ]);

        $rq = http_build_query(['search' => 1, 'merchant_transaction_id' => 'mt-adv-back-1']);
        $this->get(route('panel_admin.insight.advance.show', [
            'merchantTransactionId' => 'mt-adv-back-1',
            'rq' => $rq,
        ], false))
            ->assertOk()
            ->assertSee('href="'.route('panel_admin.insight.advance', [], false).'?'.e($rq).'"', false);
    }
}
