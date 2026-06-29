<?php

namespace App\Support;

use App\Support\UiText;

/**
 * Bankart customer.billingCountry must be ISO 3166-1 alpha-2 (/^[A-Z]{2}$/).
 * UX value "OTHER" is not valid for the gateway.
 */
final class BankartBillingCountry
{
    public const INVALID_PLACEHOLDER = 'OTHER';

    public static function normalize(?string $country): ?string
    {
        $code = strtoupper(trim((string) $country));

        if ($code === '' || $code === self::INVALID_PLACEHOLDER) {
            return null;
        }

        if (! preg_match('/^[A-Z]{2}$/', $code)) {
            return null;
        }

        return $code;
    }

    public static function isValidForBankart(?string $country): bool
    {
        return self::normalize($country) !== null;
    }

    /**
     * Countries offered on registration/profile/guest checkout selectors (excludes OTHER).
     *
     * @return array<string, array{cg?: string, en?: string}|string>
     */
    public static function selectableCountries(): array
    {
        $all = (array) config('countries', []);

        return array_filter(
            $all,
            static fn (mixed $labels, string $code): bool => $code !== self::INVALID_PLACEHOLDER,
            ARRAY_FILTER_USE_BOTH,
        );
    }

    /**
     * @return list<string>
     */
    public static function selectableCountryCodes(): array
    {
        return array_keys(self::selectableCountries());
    }

    public static function isSelectablePaymentCountry(?string $country): bool
    {
        $normalized = self::normalize($country);

        return $normalized !== null && array_key_exists($normalized, self::selectableCountries());
    }

    public static function selectionValidationMessage(?string $locale = null): string
    {
        $locale ??= app()->getLocale();

        return UiText::t(
            'auth',
            'country_select_required',
            $locale === 'cg'
                ? 'Molimo izaberite državu. Ako Vaša država nije na spisku, kontaktirajte bus@kotor.me.'
                : 'Please select your country. If your country is not listed, contact bus@kotor.me.',
            $locale,
        );
    }

    /**
     * Resolve billingCountry for Bankart JSON. Empty input defaults to ME; invalid returns null.
     */
    public static function resolveForPayload(?string $country, string $defaultWhenEmpty = 'ME'): ?string
    {
        $raw = trim((string) $country);
        if ($raw === '') {
            return $defaultWhenEmpty;
        }

        return self::normalize($raw);
    }
}
