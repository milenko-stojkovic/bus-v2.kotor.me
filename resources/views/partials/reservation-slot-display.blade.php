@php
    /** @var \App\Models\Reservation $reservation */
    $locale = $locale ?? app()->getLocale();
    $dailyLabel = \App\Support\UiText::t(
        'panel',
        'daily_ticket_label',
        $locale === 'cg' ? 'Dnevna karta' : 'Daily ticket',
        $locale,
    );
@endphp
@if ($reservation->isDailyTicket())
    {{ $dailyLabel }}
@else
    {{ $slot?->time_slot ?? '—' }}
@endif
