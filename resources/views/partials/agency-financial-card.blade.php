@php
    $locale = $locale ?? app()->getLocale();
    $s = fn (string $key, ?string $fallback = null) => \App\Support\UiText::t('statistics', $key, $fallback, $locale);
    $card = $financial_card;
    $money = fn (?string $amount): string => $amount === null || $amount === '' ? '' : $amount.' €';
    $eventLabel = function (array $line) use ($s, $locale): string {
        if ($line['kind'] === 'correction' && is_string($line['note']) && $line['note'] !== '') {
            return $line['note'];
        }

        return match ($line['kind']) {
            'card_purchase' => $s('event_card_purchase', $locale === 'en' ? 'Card payment' : 'Plaćanje karticom'),
            'topup' => $s('event_topup', $locale === 'en' ? 'Advance top-up' : 'Avansna uplata'),
            'service' => $s('event_service', $locale === 'en' ? 'Reservation' : 'Rezervacija'),
            default => $s('event_correction', $locale === 'en' ? 'Advance correction' : 'Korekcija avansa'),
        };
    };
    $undatedTemplate = $s(
        'financial_card_undated_topup',
        $locale === 'en'
            ? 'Paid advance top-ups without a payment date are omitted. The count is for the whole agency, not only the selected period: :count.'
            : 'Plaćene avansne uplate bez datuma uplate nisu uračunate. Broj je za cijelu agenciju, ne samo za izabrani period: :count.',
    );
    $undatedNotice = str_replace(':count', (string) ($card['undated_paid_topup_count'] ?? 0), $undatedTemplate);
@endphp

<div class="bg-white overflow-hidden shadow-sm sm:rounded-lg">
    <div class="p-6 space-y-4">
        <h3 class="text-lg font-medium text-gray-900">
            {{ $s('financial_card_title', $locale === 'en' ? 'Financial card' : 'Finansijska kartica') }}
        </h3>
        <p class="text-sm text-gray-600">
            {{ $s('financial_card_disclaimer', $locale === 'en'
                ? 'This card lists recorded payments, realized services, and advance corrections. It is not the advance balance.'
                : 'Ova kartica prikazuje zabilježene uplate, realizovane usluge i korekcije avansa. Nije stanje avansa.') }}
        </p>

        @if ($card['historical_notice'])
            <div class="rounded-md bg-amber-50 p-3 text-sm text-amber-950">
                {{ $s('financial_card_historical_notice', $locale === 'en'
                    ? 'Some older reservations may not yet appear on this agency financial card. The recorded purchase date may differ from the actual card payment date because of earlier administrative adjustments.'
                    : 'Neke starije rezervacije možda još nisu prikazane na finansijskoj kartici ove agencije. Zabilježeni datum kupovine može se razlikovati od stvarnog datuma plaćanja karticom zbog ranijih administrativnih izmjena.') }}
            </div>
        @endif

        @if (($card['undated_paid_topup_count'] ?? 0) > 0)
            <div class="rounded-md bg-amber-50 p-3 text-sm text-amber-950">{{ $undatedNotice }}</div>
        @endif

        @if ($card['lines'] === [])
            <p class="text-sm text-gray-500">
                {{ $s('financial_card_empty', $locale === 'en' ? 'No entries in the selected period.' : 'Nema stavki u izabranom periodu.') }}
            </p>
        @else
            <div class="overflow-x-auto">
                <table class="min-w-full divide-y divide-gray-200 text-sm">
                    <thead class="bg-red-50">
                        <tr>
                            <th class="px-3 py-2 text-left font-medium text-gray-700">{{ $s('col_date', $locale === 'en' ? 'Date' : 'Datum') }}</th>
                            <th class="px-3 py-2 text-left font-medium text-gray-700">{{ $s('col_event', $locale === 'en' ? 'Description' : 'Opis') }}</th>
                            <th class="px-3 py-2 text-left font-medium text-gray-700">{{ $s('col_mtid', $locale === 'en' ? 'Transaction' : 'Transakcija') }}</th>
                            <th class="px-3 py-2 text-left font-medium text-gray-700">{{ $s('registration_plates', $locale === 'en' ? 'Registration plates' : 'Tablice') }}</th>
                            <th class="px-3 py-2 text-right font-medium text-gray-700">{{ $s('col_incoming', $locale === 'en' ? 'Incoming' : 'Uplate') }}</th>
                            <th class="px-3 py-2 text-right font-medium text-gray-700">{{ $s('col_service', $locale === 'en' ? 'Services' : 'Usluge') }}</th>
                            <th class="px-3 py-2 text-right font-medium text-gray-700">{{ $s('col_correction', $locale === 'en' ? 'Advance correction' : 'Korekcija avansa') }}</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-gray-200">
                        @foreach ($card['lines'] as $line)
                            <tr>
                                <td class="px-3 py-2 whitespace-nowrap text-gray-900">{{ $line['date'] }}</td>
                                <td class="px-3 py-2 text-gray-700">{{ $eventLabel($line) }}</td>
                                <td class="px-3 py-2 text-gray-700">{{ $line['merchant_transaction_id'] ?? '—' }}</td>
                                <td class="px-3 py-2 text-gray-900">{{ $line['license_plate'] ?? '—' }}</td>
                                <td class="px-3 py-2 text-right text-gray-900 tabular-nums">{{ $money($line['incoming']) }}</td>
                                <td class="px-3 py-2 text-right text-gray-900 tabular-nums">{{ $money($line['service']) }}</td>
                                <td class="px-3 py-2 text-right text-gray-900 tabular-nums">{{ $money($line['correction']) }}</td>
                            </tr>
                        @endforeach
                    </tbody>
                    <tfoot>
                        <tr>
                            <th colspan="4" class="px-3 py-2 text-left text-gray-700">{{ $s('total_incoming', $locale === 'en' ? 'Total incoming payments' : 'Ukupno uplata') }}</th>
                            <th class="px-3 py-2 text-right text-gray-900 tabular-nums">{{ $card['total_incoming'] }} €</th>
                            <th></th>
                            <th></th>
                        </tr>
                        <tr>
                            <th colspan="4" class="px-3 py-2 text-left text-gray-700">{{ $s('total_services', $locale === 'en' ? 'Total realized services' : 'Ukupno usluga') }}</th>
                            <th></th>
                            <th class="px-3 py-2 text-right text-gray-900 tabular-nums">{{ $card['total_services'] }} €</th>
                            <th></th>
                        </tr>
                        <tr>
                            <th colspan="4" class="px-3 py-2 text-left text-gray-700">{{ $s('total_corrections', $locale === 'en' ? 'Total advance corrections' : 'Ukupno korekcija avansa') }}</th>
                            <th></th>
                            <th></th>
                            <th class="px-3 py-2 text-right text-gray-900 tabular-nums">{{ $card['total_corrections'] }} €</th>
                        </tr>
                    </tfoot>
                </table>
            </div>
        @endif
    </div>
</div>
