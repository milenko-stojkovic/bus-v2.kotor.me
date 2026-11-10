@php
    $locale = app()->getLocale();
    $showCardBlock = (bool) ($card_payment_blocked ?? false);
    $showAdvanceWarning = (bool) ($advance_warning ?? false);
@endphp
@if ($showCardBlock || $showAdvanceWarning)
    <div class="rounded-md border border-red-200 bg-red-50 p-3 text-sm text-red-900 space-y-2">
        @if ($showCardBlock)
            <p>{{ \App\Support\UiText::t(
                'reservation',
                'tariff_card_blackout',
                $locale === 'en'
                    ? 'Card payment is unavailable until midnight because a price change takes effect at 00:00. You can try again after the new prices apply. Advance payment and free reservations are not affected.'
                    : 'Plaćanje karticom nije dostupno do ponoći jer nova tarifa počinje u 00:00. Nakon toga možete pokušati ponovo po novim cijenama. Plaćanje iz avansa i besplatne rezervacije nisu obustavljeni.',
                $locale,
            ) }}</p>
        @endif
        @if ($showAdvanceWarning)
            <p>{{ \App\Support\UiText::t(
                'reservation',
                'tariff_advance_warning',
                $locale === 'en'
                    ? 'Prices change at midnight (00:00). The amount charged is the price in effect when the payment is completed. Until midnight the current price is used. Your advance balance must also cover the price that applies from midnight.'
                    : 'Cijene se mijenjaju u ponoć (00:00). Naplaćuje se cijena koja važi u trenutku plaćanja. Do ponoći važi trenutna cijena. Raspoloživi avans mora pokrivati i cijenu koja važi od ponoći.',
                $locale,
            ) }}</p>
            @if (($paid_amount ?? null) && ($tariff_incoming_amount ?? null) && $paid_amount !== $tariff_incoming_amount)
                <p>
                    {{ $locale === 'en' ? 'This category' : 'Ova kategorija' }}:
                    {{ $paid_amount }} EUR
                    → {{ $tariff_incoming_amount }} EUR
                </p>
            @endif
        @endif
    </div>
@endif
