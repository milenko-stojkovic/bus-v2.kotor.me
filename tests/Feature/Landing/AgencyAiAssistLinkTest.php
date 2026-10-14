<?php

namespace Tests\Feature\Landing;

use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

final class AgencyAiAssistLinkTest extends TestCase
{
    use IsolatesUserGuidePdfFixtures;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->isolateUserGuidePdfFixtures();
        $this->writeUserGuideFixture('cg');
        $this->writeUserGuideFixture('en');
    }

    protected function tearDown(): void
    {
        $this->removeUserGuidePdfFixtures();

        parent::tearDown();
    }

    public function test_public_and_agency_pages_show_the_control_before_the_pdf_icon(): void
    {
        $this->assertAssistPrecedesPdf(
            $this->withSession(['locale' => 'cg'])->get(route('landing', [], false))->assertOk()->getContent(),
            $this->userGuideRelativePath('cg'),
            1,
        );

        $this->assertAssistPrecedesPdf(
            $this->withSession(['locale' => 'en'])->get(route('login', [], false))->assertOk()->getContent(),
            $this->userGuideRelativePath('en'),
            1,
        );

        $this->assertAssistPrecedesPdf(
            $this->withSession(['locale' => 'cg'])->get(route('register', [], false))->assertOk()->getContent(),
            $this->userGuideRelativePath('cg'),
            1,
        );

        $user = User::factory()->create();
        $this->assertAssistPrecedesPdf(
            $this->actingAs($user)->withSession(['locale' => 'en'])->get(route('panel.reservations', [], false))->assertOk()->getContent(),
            $this->userGuideRelativePath('en'),
            2,
        );
    }

    public function test_hidden_surfaces_do_not_show_the_control(): void
    {
        $englishGuide = $this->userGuideRelativePath('en');

        $this->get(route('guest.reserve', [], false))
            ->assertOk()
            ->assertDontSee('data-agency-ai-assist', false)
            ->assertSee($englishGuide, false);

        $this->get(route('password.request', [], false))
            ->assertOk()
            ->assertDontSee('data-agency-ai-assist', false);

        $unverified = User::factory()->unverified()->create();
        $this->actingAs($unverified)
            ->get(route('verification.notice', [], false))
            ->assertOk()
            ->assertDontSee('data-agency-ai-assist', false);

        $this->get(route('panel_admin.login', [], false))
            ->assertOk()
            ->assertDontSee('data-agency-ai-assist', false);

        $this->get(route('control.login', [], false))
            ->assertOk()
            ->assertDontSee('data-agency-ai-assist', false);

        $staff = User::factory()->create();
        $role = Role::query()->create(['name' => 'admin', 'guard_name' => 'web']);
        $staff->roles()->attach($role->id);

        $this->actingAs($staff)
            ->get(route('staff.reservations.index', [], false))
            ->assertOk()
            ->assertDontSee('data-agency-ai-assist', false)
            ->assertSee($englishGuide, false);
    }

    public function test_prompt_uses_the_browser_language_and_leaves_the_pdf_link_intact(): void
    {
        $html = $this->withSession(['locale' => 'cg'])
            ->get(route('landing', [], false))
            ->assertOk()
            ->assertSee('ChatGPT će se otvoriti u novom tabu.', false)
            ->assertSee('Pritisnite Enter ili Pošalji da razgovor počne.', false)
            ->assertSee('>AI<', false)
            ->assertSee('Otvori ChatGPT', false)
            ->assertSee('https://bus.kotor.me/llms.txt', false)
            ->assertSee('https://chatgpt.com/?q=', false)
            ->assertSee('encodeURIComponent(prompt)', false)
            ->assertSee('navigator.languages?.[0] || navigator.language || \'en\'', false)
            ->assertSee('noopener,noreferrer', false)
            ->assertSee('__BROWSER_LANGUAGE__', false)
            ->assertSee($this->userGuideRelativePath('cg'), false)
            ->assertSee('target="_blank"', false)
            ->assertSee('rel="noopener noreferrer"', false)
            ->assertSee('Uputstvo (PDF)', false)
            ->getContent();

        $this->assertStringNotContainsString('Start in this browser language: cg', $html);
        $this->assertStringNotContainsString('Start in this browser language: en', $html);
    }

    private function assertAssistPrecedesPdf(string $html, string $pdfPath, int $expectedPairs): void
    {
        preg_match_all('/data-agency-ai-assist|'.preg_quote($pdfPath, '/').'/', $html, $matches);
        $tokens = $matches[0];
        $expected = [];
        for ($i = 0; $i < $expectedPairs; $i++) {
            $expected[] = 'data-agency-ai-assist';
            $expected[] = $pdfPath;
        }

        $this->assertSame($expected, $tokens);
    }
}
