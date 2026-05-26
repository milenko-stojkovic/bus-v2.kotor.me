<?php

namespace Tests\Feature\Panel;

use App\Models\AgencyAdvanceTransaction;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

final class AdvanceLedgerNoteLocaleTest extends TestCase
{
    use RefreshDatabase;

    public function test_advance_history_shows_english_ledger_notes_when_locale_is_en(): void
    {
        config(['features.advance_payments' => true]);

        $user = User::factory()->create([
            'email_verified_at' => now(),
            'lang' => 'en',
        ]);
        $this->actingAs($user)
            ->withSession(['locale' => 'en']);

        AgencyAdvanceTransaction::query()->create([
            'agency_user_id' => $user->id,
            'amount' => '100.00',
            'type' => AgencyAdvanceTransaction::TYPE_TOPUP,
            'note' => 'Avansna uplata',
        ]);
        AgencyAdvanceTransaction::query()->create([
            'agency_user_id' => $user->id,
            'amount' => '-10.00',
            'type' => AgencyAdvanceTransaction::TYPE_USAGE,
            'note' => 'Plaćanje rezervacije iz avansa',
        ]);

        $html = $this->get(route('panel.advance.index', [], false))
            ->assertOk()
            ->getContent();

        $this->assertStringContainsString('Advance top-up', $html);
        $this->assertStringContainsString('Reservation paid from advance', $html);
        $this->assertStringNotContainsString('Plaćanje rezervacije iz avansa', $html);
    }
}
