@php
    $fallbackCg = 'Rezervacije koje sadrže BAREM JEDAN termin u periodu 07:00 - 20:00 se plaćaju. Rezervacije čija se OBA termina (ukrcaj i iskrcaj) nalaze u periodima 00:00 - 07:00, odnosno 20:00 - 24:00 sistem tretira kao besplatne rezervacije.';
    $fallbackEn = 'Reservations that include AT LEAST ONE time slot in the 07:00 - 20:00 period are paid. Reservations where BOTH time slots (embarkation and disembarkation) fall within 00:00 - 07:00 or 20:00 - 24:00 are treated as free reservations by the system.';
    $locale = app()->getLocale();
    $text = \App\Support\UiText::t(
        'reservation',
        'pricing_notice',
        $locale === 'en' ? $fallbackEn : $fallbackCg,
    );
@endphp

<div class="rounded-md border border-red-100 bg-red-50 p-3 text-sm text-gray-700 leading-relaxed">
    {{ $text }}
</div>
