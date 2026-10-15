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
        $this->assertLanguageNoteFollowsEnterInstruction(
            $html,
            'Pritisnite Enter ili Pošalji da razgovor počne.',
            'Možete postavljati pitanja i dobijati odgovore na svom jeziku.',
            'Otvori ChatGPT',
        );
    }

    public function test_language_note_is_stored_and_rendered_in_both_portal_languages(): void
    {
        foreach ([
            ['landing', 'cg', 'Možete postavljati pitanja i dobijati odgovore na svom jeziku.'],
            ['landing', 'en', 'You can ask questions and receive answers in your preferred language.'],
            ['panel', 'cg', 'Možete postavljati pitanja i dobijati odgovore na svom jeziku.'],
            ['panel', 'en', 'You can ask questions and receive answers in your preferred language.'],
        ] as [$group, $locale, $text]) {
            $this->assertDatabaseHas('ui_translations', [
                'group' => $group,
                'key' => 'ai_assist_language',
                'locale' => $locale,
                'text' => $text,
            ]);
        }

        $english = $this->withSession(['locale' => 'en'])
            ->get(route('login', [], false))
            ->assertOk()
            ->assertSee('You can ask questions and receive answers in your preferred language.', false)
            ->getContent();

        $this->assertLanguageNoteFollowsEnterInstruction(
            $english,
            'Press Enter or Send to start the conversation.',
            'You can ask questions and receive answers in your preferred language.',
            'Open ChatGPT',
        );
    }

    public function test_anonymous_fallback_reuses_the_prompt_and_plain_chatgpt_url(): void
    {
        $texts = [
            'ai_assist_anonymous_hint' => [
                'cg' => 'Ako ChatGPT prijavi grešku provjere, koristite ovu opciju.',
                'en' => 'If ChatGPT shows a verification error, use this option.',
            ],
            'ai_assist_anonymous_open' => [
                'cg' => 'Kopiraj i otvori ChatGPT',
                'en' => 'Copy and open ChatGPT',
            ],
            'ai_assist_anonymous_paste' => [
                'cg' => 'U ChatGPT-u nalijepite tekst (Ctrl+V ili Zalijepi) i pritisnite Enter.',
                'en' => 'In ChatGPT, paste the text (Ctrl+V or Paste) and press Enter.',
            ],
            'ai_assist_anonymous_copied' => [
                'cg' => 'Tekst je kopiran.',
                'en' => 'Text copied.',
            ],
            'ai_assist_anonymous_copy_failed' => [
                'cg' => 'Automatsko kopiranje nije uspjelo. Kopirajte tekst iz polja ispod.',
                'en' => 'Automatic copying failed. Copy the text from the box below.',
            ],
        ];

        foreach (['landing', 'panel'] as $group) {
            foreach ($texts as $key => $byLocale) {
                foreach ($byLocale as $locale => $text) {
                    $this->assertDatabaseHas('ui_translations', [
                        'group' => $group,
                        'key' => $key,
                        'locale' => $locale,
                        'text' => $text,
                    ]);
                }
            }
        }

        $html = $this->withSession(['locale' => 'cg'])
            ->get(route('landing', [], false))
            ->assertOk()
            ->assertSee('Ako ChatGPT prijavi grešku provjere, koristite ovu opciju.', false)
            ->assertSee('Kopiraj i otvori ChatGPT', false)
            ->assertSee('Tekst je kopiran.', false)
            ->assertSee('Automatsko kopiranje nije uspjelo. Kopirajte tekst iz polja ispod.', false)
            ->assertSee('U ChatGPT-u nalijepite tekst (Ctrl+V ili Zalijepi) i pritisnite Enter.', false)
            ->assertSee('type="button"', false)
            ->assertSee('readonly', false)
            ->assertSee('https://chatgpt.com/?q=', false)
            ->assertSee("window.open('https://chatgpt.com/', '_blank', 'noopener,noreferrer')", false)
            ->assertSee('navigator.clipboard.writeText', false)
            ->assertSee('promptText()', false)
            ->assertSee('navigator.languages?.[0] || navigator.language || \'en\'', false)
            ->assertSee($this->userGuideRelativePath('cg'), false)
            ->getContent();

        $plainOpenAt = strpos($html, "window.open('https://chatgpt.com/', '_blank', 'noopener,noreferrer')");
        $copyAt = strpos($html, 'navigator.clipboard.writeText');
        $copiedAt = strpos($html, "this.fallbackState = 'copied'");
        $this->assertNotFalse($plainOpenAt);
        $this->assertNotFalse($copyAt);
        $this->assertNotFalse($copiedAt);
        $this->assertLessThan($copyAt, $plainOpenAt);
        $this->assertLessThan($copiedAt, $copyAt);
        $this->assertSame(1, substr_count($html, 'Before answering, read https://bus.kotor.me/llms.txt'));
        $this->assertSame(1, substr_count($html, 'promptText() {'));
        $this->assertSame(2, substr_count($html, 'this.promptText()'));
        $hintAt = strpos($html, 'Ako ChatGPT prijavi grešku provjere, koristite ovu opciju.');
        $buttonAt = strpos($html, 'Kopiraj i otvori ChatGPT');
        $failureAt = strpos($html, 'Automatsko kopiranje nije uspjelo. Kopirajte tekst iz polja ispod.');
        $this->assertNotFalse($hintAt);
        $this->assertNotFalse($buttonAt);
        $this->assertNotFalse($failureAt);
        $this->assertLessThan($buttonAt, $hintAt);
        $this->assertLessThan($failureAt, $buttonAt);

        $this->withSession(['locale' => 'en'])
            ->get(route('login', [], false))
            ->assertOk()
            ->assertSee('If ChatGPT shows a verification error, use this option.', false)
            ->assertSee('Copy and open ChatGPT', false)
            ->assertSee('Text copied.', false)
            ->assertSee('Automatic copying failed. Copy the text from the box below.', false)
            ->assertSee('In ChatGPT, paste the text (Ctrl+V or Paste) and press Enter.', false);
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

    private function assertLanguageNoteFollowsEnterInstruction(string $html, string $enter, string $note, string $open): void
    {
        $enterAt = strpos($html, $enter);
        $noteAt = strpos($html, $note);
        $openAt = strpos($html, $open);

        $this->assertNotFalse($enterAt);
        $this->assertNotFalse($noteAt);
        $this->assertNotFalse($openAt);
        $this->assertLessThan($noteAt, $enterAt);
        $this->assertLessThan($openAt, $noteAt);
    }
}
