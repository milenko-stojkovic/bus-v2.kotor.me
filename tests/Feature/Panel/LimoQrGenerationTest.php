<?php

namespace Tests\Feature\Panel;

use App\Models\AgencyAdvanceTransaction;
use App\Models\LimoQrToken;
use App\Models\User;
use App\Services\Limo\LimoPickupService;
use App\Services\Limo\LimoQrService;
use Carbon\Carbon;
use Illuminate\Foundation\Http\Middleware\ValidateCsrfToken;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

final class LimoQrGenerationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow(Carbon::parse('2026-05-10 12:00:00', 'Europe/Podgorica'));
        config(['features.advance_payments' => true]);
        config(['features.limo_service' => true]);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    public function test_informational_limo_page_has_no_qr_actions(): void
    {
        $user = User::factory()->create(['email_verified_at' => now()]);

        $html = $this->actingAs($user)
            ->get(route('panel.limo.index'))
            ->assertOk()
            ->getContent();

        $this->assertStringContainsString('Limo', $html);
        $this->assertStringNotContainsString('Generiši QR', $html);
        $this->assertStringNotContainsString('Generate QR', $html);
        $this->assertStringNotContainsString('Aktivni QR', $html);
        $this->assertStringNotContainsString('Active QR', $html);
        $this->assertStringNotContainsString('/panel/limo/qr/', $html);
    }

    public function test_informational_limo_page_shows_regulation_wording_in_cg(): void
    {
        $user = User::factory()->create(['email_verified_at' => now()]);

        $this->actingAs($user)
            ->get('/locale/cg')
            ->assertRedirect();

        $html = $this->get(route('panel.limo.index'))
            ->assertOk()
            ->getContent();

        $this->assertStringContainsString('Limo usluga', $html);
        $this->assertStringContainsString('dnevna naknada', $html);
        $this->assertStringContainsString('užem centru grada', $html);
        $this->assertStringContainsString('autobusko stajalište pored pozorišta', $html);
        $this->assertStringContainsString('izlaz iz parking prostora Riva', $html);
        $this->assertStringContainsString('https://maps.app.goo.gl/BkmeQ1ZAo8XG1FDb6', $html);
        $this->assertStringContainsString('https://maps.app.goo.gl/qdy9YmTLRBsggPCD6', $html);
        $this->assertStringContainsString('preko puta gradske pijace', $html);
        $this->assertStringContainsString('Putničko vozilo (4+1, 5+1, 6+1 i 7+1 mjesta)', $html);
        $this->assertStringContainsString('nije dozvoljen ukrcaj niti iskrcaj putnika na lokaciji Benovo', $html);
        $this->assertStringNotContainsString('Autoboka', $html);
        $this->assertStringNotContainsString('Puč', $html);
    }

    public function test_qr_endpoints_return_404_when_workflow_disabled(): void
    {
        $user = User::factory()->create();
        AgencyAdvanceTransaction::query()->create([
            'agency_user_id' => $user->id,
            'amount' => '100.00',
            'type' => AgencyAdvanceTransaction::TYPE_TOPUP,
            'reference_type' => null,
            'reference_id' => null,
            'merchant_transaction_id' => null,
            'note' => 'test',
        ]);

        $this->withoutMiddleware(ValidateCsrfToken::class);

        $this->actingAs($user)
            ->post(route('panel.limo.qr.generate'))
            ->assertNotFound();

        $created = app(LimoQrService::class)->generateForAgency($user->id)['token'];

        $this->actingAs($user)
            ->get(route('panel.limo.qr.show', ['limoQrToken' => $created->id]))
            ->assertNotFound();

        $this->actingAs($user)
            ->get(route('panel.limo.qr.pdf', ['limoQrToken' => $created->id]))
            ->assertNotFound();
    }

    public function test_generate_token_success_creates_row_and_flashes_raw_once(): void
    {
        config(['features.limo_qr_workflow' => true]);

        $user = User::factory()->create();
        AgencyAdvanceTransaction::query()->create([
            'agency_user_id' => $user->id,
            'amount' => '100.00',
            'type' => AgencyAdvanceTransaction::TYPE_TOPUP,
            'reference_type' => null,
            'reference_id' => null,
            'merchant_transaction_id' => null,
            'note' => 'test',
        ]);

        $this->withoutMiddleware(ValidateCsrfToken::class);

        $response = $this->actingAs($user)
            ->post(route('panel.limo.qr.generate'));

        $response->assertRedirect(route('panel.limo.index'))
            ->assertSessionHas('limo_new_qr_token');

        $raw = session('limo_new_qr_token');
        $this->assertIsString($raw);
        $this->assertSame(64, strlen($raw));

        $this->assertDatabaseCount('limo_qr_tokens', 1);
        $this->assertDatabaseHas('limo_qr_tokens', [
            'agency_user_id' => $user->id,
            'token_hash' => LimoPickupService::hashToken($raw),
        ]);

        $row = LimoQrToken::query()->first();
        $this->assertNotNull($row);
        $this->assertNotEmpty($row->encrypted_token);
    }

    public function test_daily_limit_returns_error_after_twentieth_slot(): void
    {
        config(['features.limo_qr_workflow' => true]);

        $user = User::factory()->create();
        AgencyAdvanceTransaction::query()->create([
            'agency_user_id' => $user->id,
            'amount' => '500.00',
            'type' => AgencyAdvanceTransaction::TYPE_TOPUP,
            'reference_type' => null,
            'reference_id' => null,
            'merchant_transaction_id' => null,
            'note' => 'test',
        ]);

        $svc = app(LimoQrService::class);
        for ($i = 0; $i < 20; $i++) {
            $svc->generateForAgency($user->id);
        }

        $this->withoutMiddleware(ValidateCsrfToken::class);

        $this->actingAs($user)
            ->post(route('panel.limo.qr.generate'))
            ->assertRedirect(route('panel.limo.index'))
            ->assertSessionHasErrors('generate');

        $this->assertDatabaseCount('limo_qr_tokens', 20);
    }

    public function test_insufficient_advance_returns_error(): void
    {
        config(['features.limo_qr_workflow' => true]);

        $user = User::factory()->create();
        AgencyAdvanceTransaction::query()->create([
            'agency_user_id' => $user->id,
            'amount' => '10.00',
            'type' => AgencyAdvanceTransaction::TYPE_TOPUP,
            'reference_type' => null,
            'reference_id' => null,
            'merchant_transaction_id' => null,
            'note' => 'test',
        ]);

        $this->withoutMiddleware(ValidateCsrfToken::class);

        $this->actingAs($user)
            ->post(route('panel.limo.qr.generate'))
            ->assertRedirect(route('panel.limo.index'))
            ->assertSessionHasErrors('generate');

        $this->assertDatabaseCount('limo_qr_tokens', 0);
    }

    public function test_advance_feature_off_returns_404(): void
    {
        config(['features.advance_payments' => false]);
        config(['features.limo_service' => true]);

        $user = User::factory()->create();

        $this->actingAs($user)
            ->get(route('panel.limo.index'))
            ->assertNotFound();
    }

    public function test_advance_on_limo_off_returns_404_and_nav_shows_disabled_item(): void
    {
        config(['features.advance_payments' => true]);
        config(['features.limo_service' => false]);

        $user = User::factory()->create(['email_verified_at' => now()]);
        $this->actingAs($user);

        $html = $this->get(route('panel.reservations', [], false))->assertOk()->getContent();
        $this->assertStringContainsString('Limo', $html);
        $this->assertStringNotContainsString('/panel/limo', $html);

        $this->get(route('panel.limo.index', [], false))->assertNotFound();
    }
}
