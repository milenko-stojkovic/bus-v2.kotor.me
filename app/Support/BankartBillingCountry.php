<?php

namespace App\Support;

use App\Support\UiText;

/**
 * Bankart customer.billingCountry must be ISO 3166-1 alpha-2 (/^[A-Z]{2}$/)
 * and present in config/countries.php.
 */
final class BankartBillingCountry
{
    /**
     * Frequently used billing countries shown first (fixed order).
     *
     * @var list<string>
     */
    private const PREFERRED_ORDER = [
        'ME', 'RS', 'HR', 'MK', 'BA', 'AL', 'HU', 'GR', 'TR', 'SI', 'UA', 'LT',
        'BG', 'PL', 'RO', 'MD', 'DE', 'FR', 'XK', 'SE', 'CZ', 'NL', 'SK',
    ];

    public static function normalize(?string $country): ?string
    {
        $code = strtoupper(trim((string) $country));

        if ($code === '' || ! preg_match('/^[A-Z]{2}$/', $code)) {
            return null;
        }

        return $code;
    }

    public static function isValidForBankart(?string $country): bool
    {
        return self::isSelectablePaymentCountry($country);
    }

    /**
     * Countries for billing-country dropdowns: preferred codes first, then A–Z by localized label.
     *
     * @return array<string, array{cg: string, en: string}>
     */
    public static function selectableCountries(?string $locale = null): array
    {
        $locale ??= app()->getLocale();
        if (! in_array($locale, ['cg', 'en'], true)) {
            $locale = 'en';
        }

        /** @var array<string, array{cg?: string, en?: string}> $all */
        $all = (array) config('countries', []);

        $preferred = [];
        foreach (self::PREFERRED_ORDER as $code) {
            if (isset($all[$code])) {
                $preferred[$code] = $all[$code];
            }
        }

        $remaining = array_diff_key($all, $preferred);
        uasort($remaining, static function (array $a, array $b) use ($locale): int {
            $nameA = self::localizedLabel($a, $locale);
            $nameB = self::localizedLabel($b, $locale);

            return strcasecmp($nameA, $nameB);
        });

        return $preferred + $remaining;
    }

    /**
     * @param  array{cg?: string, en?: string}|string  $labels
     */
    private static function localizedLabel(array|string $labels, string $locale): string
    {
        if (is_array($labels)) {
            return (string) ($labels[$locale] ?? $labels['en'] ?? '');
        }

        return (string) $labels;
    }

    /**
     * @return list<string>
     */
    public static function selectableCountryCodes(?string $locale = null): array
    {
        return array_keys(self::selectableCountries($locale));
    }

    public static function isSelectablePaymentCountry(?string $country): bool
    {
        $normalized = self::normalize($country);
        if ($normalized === null) {
            return false;
        }

        /** @var array<string, mixed> $all */
        $all = (array) config('countries', []);

        return array_key_exists($normalized, $all);
    }

    public static function selectionValidationMessage(?string $locale = null): string
    {
        $locale ??= app()->getLocale();

        return UiText::t(
            'auth',
            'country_select_required',
            $locale === 'cg'
                ? 'Ako Vaša država nije na spisku, kontaktirajte administratora na bus@kotor.me.'
                : 'If your country is not listed, contact the administrator at bus@kotor.me.',
            $locale,
        );
    }

    /**
     * Resolve billingCountry for Bankart JSON. No fallback — invalid/empty returns null.
     */
    public static function resolveForPayload(?string $country): ?string
    {
        if (! self::isSelectablePaymentCountry($country)) {
            return null;
        }

        return self::normalize($country);
    }
}
