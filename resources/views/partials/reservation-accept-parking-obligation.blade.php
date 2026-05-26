@php
    $t = fn (string $key, string $fallbackCg, string $fallbackEn) => \App\Support\UiText::t(
        'reservation',
        $key,
        app()->getLocale() === 'en' ? $fallbackEn : $fallbackCg,
    );
    $autobokaUrl = 'https://maps.app.goo.gl/oXD6SEzjyXtm4c586';
    $pucUrl = 'https://maps.app.goo.gl/kPAD6mipzZTjCCYE7';
    $linkClass = 'underline text-red-700 hover:text-red-900';
@endphp
{{ $t(
    'accept_parking_obligation_before',
    'U slučaju da prevoznik nije u mogućnosti da izvrši iskrcaj i ukucaj putnika u terminu za koji je plaćena naknada, isti navedenu radnju mora izvršiti na parkingu ',
    'If the carrier is unable to drop off and pick up passengers at the time slot for which the fee was paid, this action must be performed at the ',
) }}<a href="{{ $autobokaUrl }}" target="_blank" rel="noopener" class="{{ $linkClass }}">Autoboka</a>{{ $t(
    'accept_parking_obligation_mid',
    ' i pakringu ',
    ' parking and the ',
) }}<a href="{{ $pucUrl }}" target="_blank" rel="noopener" class="{{ $linkClass }}">Puč</a>{{ $t(
    'accept_parking_obligation_after',
    '.',
    ' parking.',
) }}
