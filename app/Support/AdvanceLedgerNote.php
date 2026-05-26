<?php

namespace App\Support;

use Illuminate\Support\Facades\Schema;

/**
 * System advance-ledger notes are stored as translation keys (note_*).
 * Legacy rows may still contain fixed CG/EN strings — map those at display time.
 *
 * Canonical copy for all locales: ui_translations (group advance, keys note_*).
 * Edit new languages only there (seeder / admin tooling). FALLBACKS below are a
 * safety net when a row is missing or the table is absent (e.g. tests); they never
 * override a value returned from ui_translations.
 */
final class AdvanceLedgerNote
{
    public const KEY_TOPUP = 'note_topup';

    public const KEY_RESERVATION_PAYMENT = 'note_reservation_payment';

    public const KEY_LATE_SUCCESS_CONVERSION = 'note_late_success_conversion';

    public const KEY_LIMO_PICKUP_QR = 'note_limo_pickup_qr';

    public const KEY_LIMO_PICKUP_PLATE = 'note_limo_pickup_plate';

    /** @var array<string, string> */
    private const LEGACY_TO_KEY = [
        'Avansna uplata' => self::KEY_TOPUP,
        'Plaćanje rezervacije iz avansa' => self::KEY_RESERVATION_PAYMENT,
        'Late success konvertovan u avans' => self::KEY_LATE_SUCCESS_CONVERSION,
        'Limo pickup via QR' => self::KEY_LIMO_PICKUP_QR,
        'Limo pickup via plate' => self::KEY_LIMO_PICKUP_PLATE,
    ];

    /** @var array<string, array{cg: string, en: string}> */
    private const FALLBACKS = [
        self::KEY_TOPUP => [
            'cg' => 'Avansna uplata',
            'en' => 'Advance top-up',
        ],
        self::KEY_RESERVATION_PAYMENT => [
            'cg' => 'Plaćanje rezervacije iz avansa',
            'en' => 'Reservation paid from advance',
        ],
        self::KEY_LATE_SUCCESS_CONVERSION => [
            'cg' => 'Late success konvertovan u avans',
            'en' => 'Late success converted to advance',
        ],
        self::KEY_LIMO_PICKUP_QR => [
            'cg' => 'Limo preuzimanje (QR)',
            'en' => 'Limo pickup (QR)',
        ],
        self::KEY_LIMO_PICKUP_PLATE => [
            'cg' => 'Limo preuzimanje (tablica)',
            'en' => 'Limo pickup (plate)',
        ],
    ];

    public static function label(?string $stored, ?string $locale = null): string
    {
        $stored = trim((string) $stored);
        if ($stored === '') {
            return '';
        }

        $key = self::LEGACY_TO_KEY[$stored] ?? $stored;
        if (! str_starts_with($key, 'note_')) {
            return $stored;
        }

        $locale = is_string($locale) && $locale !== '' ? $locale : app()->getLocale();
        $fallback = self::FALLBACKS[$key][$locale] ?? self::FALLBACKS[$key]['cg'] ?? $stored;

        if (Schema::hasTable('ui_translations')) {
            return UiText::t('advance', $key, $fallback, $locale);
        }

        return $fallback;
    }
}
