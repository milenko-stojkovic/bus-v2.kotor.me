<?php

namespace Tests\Unit\Support;

use App\Support\AdvanceLedgerNote;
use Tests\TestCase;

final class AdvanceLedgerNoteTest extends TestCase
{
    public function test_legacy_cg_notes_translate_to_english(): void
    {
        $this->assertSame(
            'Advance top-up',
            AdvanceLedgerNote::label('Avansna uplata', 'en')
        );
        $this->assertSame(
            'Reservation paid from advance',
            AdvanceLedgerNote::label('Plaćanje rezervacije iz avansa', 'en')
        );
    }

    public function test_stored_keys_translate_by_locale(): void
    {
        $this->assertSame(
            'Avansna uplata',
            AdvanceLedgerNote::label(AdvanceLedgerNote::KEY_TOPUP, 'cg')
        );
        $this->assertSame(
            'Advance top-up',
            AdvanceLedgerNote::label(AdvanceLedgerNote::KEY_TOPUP, 'en')
        );
    }

    public function test_freeform_admin_note_is_passed_through(): void
    {
        $this->assertSame(
            'Manual correction by admin',
            AdvanceLedgerNote::label('Manual correction by admin', 'en')
        );
    }
}
