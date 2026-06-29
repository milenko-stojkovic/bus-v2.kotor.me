<?php

namespace App\Support;

use App\Support\UiText;

/**
 * Bankart customer.billingCountry must be ISO 3166-1 alpha-2 (/^[A-Z]{2}$/)
 * and present in config/countries.php.
 */
final class BankartBillingCountry
{
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
     * @return array<string, array{cg: string, en: string}>
     */
    public static function selectableCountries(): array
    {
        /** @var array<string, array{cg?: string, en?: string}> $all */
        $all = (array) config('countries', []);

        return $all;
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
