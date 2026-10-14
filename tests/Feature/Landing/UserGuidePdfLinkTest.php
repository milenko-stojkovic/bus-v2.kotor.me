<?php

namespace Tests\Feature\Landing;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

final class UserGuidePdfLinkTest extends TestCase
{
    use IsolatesUserGuidePdfFixtures;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->isolateUserGuidePdfFixtures();
    }

    protected function tearDown(): void
    {
        $this->removeUserGuidePdfFixtures();

        parent::tearDown();
    }

    public function test_landing_shows_cg_pdf_link_when_file_exists(): void
    {
        $relativePath = $this->writeUserGuideFixture('cg');

        $this->withSession(['locale' => 'cg'])
            ->get(route('landing', [], false))
            ->assertOk()
            ->assertSee($relativePath, false)
            ->assertSee('target="_blank"', false)
            ->assertSee('rel="noopener noreferrer"', false)
            ->assertDontSee('download', false);
    }

    public function test_landing_hides_pdf_link_when_file_missing(): void
    {
        $this->withSession(['locale' => 'cg'])
            ->get(route('landing', [], false))
            ->assertOk()
            ->assertDontSee($this->userGuideRelativePath('cg'), false)
            ->assertDontSee('docs/cgbuskotor.pdf', false);
    }

    public function test_agency_panel_shows_en_pdf_link_when_file_exists(): void
    {
        $relativePath = $this->writeUserGuideFixture('en');

        $user = User::factory()->create();
        app()->setLocale('en');

        $this->actingAs($user)
            ->get(route('panel.reservations', [], false))
            ->assertOk()
            ->assertSee($relativePath, false);
    }
}
