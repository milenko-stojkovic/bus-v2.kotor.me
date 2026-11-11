@php
    $d = $dataset ?? [];
    $locale = app()->getLocale();
    $t = fn (string $key, ?string $fallback = null) => \App\Support\UiText::t('statistics', $key, $fallback, $locale);
    $from = (string) ($d['date_from'] ?? '');
    $to = (string) ($d['date_to'] ?? '');
    $period = $from !== '' && $to !== '' ? ($from.' — '.$to) : '—';
    $agency = (string) ($d['agency_name'] ?? '');
    $lines = $d['lines'] ?? [];
    $money = fn (?string $amount): string => $amount === null || $amount === '' ? '' : $amount.' EUR';
    $eventLabel = function (array $line) use ($t, $locale): string {
        if (($line['kind'] ?? '') === 'correction' && is_string($line['note'] ?? null) && $line['note'] !== '') {
            return $line['note'];
        }

        return match ($line['kind'] ?? '') {
            'card_purchase' => $t('event_card_purchase', $locale === 'en' ? 'Card payment' : 'Plaćanje karticom'),
            'topup' => $t('event_topup', $locale === 'en' ? 'Advance top-up' : 'Avansna uplata'),
            'service' => $t('event_service', $locale === 'en' ? 'Reservation' : 'Rezervacija'),
            default => $t('event_correction', $locale === 'en' ? 'Advance correction' : 'Korekcija avansa'),
        };
    };
    $undatedCount = (int) ($d['undated_paid_topup_count'] ?? 0);
    $undatedNotice = str_replace(
        ':count',
        (string) $undatedCount,
        $t('financial_card_undated_topup', $locale === 'en'
            ? 'Paid advance top-ups without a payment date are omitted. The count is for the whole agency, not only the selected period: :count.'
            : 'Plaćene avansne uplate bez datuma uplate nisu uračunate. Broj je za cijelu agenciju, ne samo za izabrani period: :count.'),
    );
@endphp
<!doctype html>
<html lang="sr-Latn-ME">
<head>
    <meta charset="utf-8">
    <style>
        body { font-family: DejaVu Sans, sans-serif; font-size: 11px; color: #111827; }
        .header { margin-bottom: 12px; }
        .logo { height: 48px; width: auto; }
        h1 { font-size: 16px; margin: 0; }
        .muted { color: #6b7280; font-size: 10px; margin-top: 2px; }
        .notice { border: 1px solid #fcd34d; background: #fffbeb; padding: 8px; margin-top: 10px; }
        table { width: 100%; border-collapse: collapse; margin-top: 10px; }
        td, th { padding: 4px 3px; border-bottom: 1px solid #e5e7eb; vertical-align: top; }
        th { text-align: left; color: #374151; font-size: 10px; }
        .right { text-align: right; }
    </style>
</head>
<body>
    <div class="header">
        @if (!empty($logoDataUri))
            <img class="logo" src="{{ $logoDataUri }}" alt="Logo" />
        @endif
        <h1>{{ $t('financial_card_title', $locale === 'en' ? 'Financial card' : 'Finansijska kartica') }}</h1>
        @if ($agency !== '')
            <div>{{ $agency }}</div>
        @endif
        <div class="muted">{{ $period }}</div>
        <div class="muted">
            {{ $t('financial_card_disclaimer', $locale === 'en'
                ? 'This card lists recorded payments, realized services, and advance corrections. It is not the advance balance.'
                : 'Ova kartica prikazuje zabilježene uplate, realizovane usluge i korekcije avansa. Nije stanje avansa.') }}
        </div>
    </div>

    @if (!empty($d['historical_notice']))
        <div class="notice">
            {{ $t('financial_card_historical_notice', $locale === 'en'
                ? 'Some older reservations may not yet appear on this agency financial card. The recorded purchase date may differ from the actual card payment date because of earlier administrative adjustments.'
                : 'Neke starije rezervacije možda još nisu prikazane na finansijskoj kartici ove agencije. Zabilježeni datum kupovine može se razlikovati od stvarnog datuma plaćanja karticom zbog ranijih administrativnih izmjena.') }}
        </div>
    @endif

    @if ($undatedCount > 0)
        <div class="notice">{{ $undatedNotice }}</div>
    @endif

    <table>
        <thead>
            <tr>
                <th>{{ $t('col_date', $locale === 'en' ? 'Date' : 'Datum') }}</th>
                <th>{{ $t('col_event', $locale === 'en' ? 'Description' : 'Opis') }}</th>
                <th>{{ $t('col_mtid', $locale === 'en' ? 'Transaction' : 'Transakcija') }}</th>
                <th>{{ $t('registration_plates', $locale === 'en' ? 'Registration plates' : 'Tablice') }}</th>
                <th class="right">{{ $t('col_incoming', $locale === 'en' ? 'Incoming' : 'Uplate') }}</th>
                <th class="right">{{ $t('col_service', $locale === 'en' ? 'Services' : 'Usluge') }}</th>
                <th class="right">{{ $t('col_correction', $locale === 'en' ? 'Advance correction' : 'Korekcija avansa') }}</th>
            </tr>
        </thead>
        <tbody>
            @forelse ($lines as $line)
                <tr>
                    <td>{{ $line['date'] }}</td>
                    <td>{{ $eventLabel($line) }}</td>
                    <td>{{ $line['merchant_transaction_id'] ?? '—' }}</td>
                    <td>{{ $line['license_plate'] ?? '—' }}</td>
                    <td class="right">{{ $money($line['incoming'] ?? null) }}</td>
                    <td class="right">{{ $money($line['service'] ?? null) }}</td>
                    <td class="right">{{ $money($line['correction'] ?? null) }}</td>
                </tr>
            @empty
                <tr>
                    <td colspan="7">{{ $t('financial_card_empty', $locale === 'en' ? 'No entries in the selected period.' : 'Nema stavki u izabranom periodu.') }}</td>
                </tr>
            @endforelse
        </tbody>
        <tfoot>
            <tr>
                <th colspan="4">{{ $t('total_incoming', $locale === 'en' ? 'Total incoming payments' : 'Ukupno uplata') }}</th>
                <th class="right">{{ $d['total_incoming'] ?? '0.00' }} EUR</th>
                <th></th>
                <th></th>
            </tr>
            <tr>
                <th colspan="4">{{ $t('total_services', $locale === 'en' ? 'Total realized services' : 'Ukupno usluga') }}</th>
                <th></th>
                <th class="right">{{ $d['total_services'] ?? '0.00' }} EUR</th>
                <th></th>
            </tr>
            <tr>
                <th colspan="4">{{ $t('total_corrections', $locale === 'en' ? 'Total advance corrections' : 'Ukupno korekcija avansa') }}</th>
                <th></th>
                <th></th>
                <th class="right">{{ $d['total_corrections'] ?? '0.00' }} EUR</th>
            </tr>
        </tfoot>
    </table>
</body>
</html>
