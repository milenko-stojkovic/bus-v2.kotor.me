@php
    $d = $dataset ?? [];
    $locale = app()->getLocale();
    $t = fn (string $key, ?string $fallback = null) => \App\Support\UiText::t('statistics', $key, $fallback, $locale);
    $p = fn (string $key, ?string $fallback = null) => \App\Support\UiText::t('panel', $key, $fallback, $locale);
    $dailyLabel = $p('daily_ticket_label', $locale === 'en' ? 'Daily fee' : 'Dnevna naknada');
    $statusLabel = function (string $status) use ($t, $locale): string {
        return match ($status) {
            'paid' => $t('status_paid', $locale === 'en' ? 'Paid' : 'Plaćeno'),
            'free' => $t('status_free', $locale === 'en' ? 'Free' : 'Besplatno'),
            default => $status,
        };
    };
    $lines = $d['lines'] ?? [];
    $countLabel = str_replace(
        ':count',
        (string) ($d['reservation_count'] ?? count($lines)),
        $t('plate_count', $locale === 'en' ? 'Reservations: :count' : 'Rezervacije: :count'),
    );
@endphp
<!doctype html>
<html lang="sr-Latn-ME">
<head>
    <meta charset="utf-8">
    <style>
        body { font-family: DejaVu Sans, sans-serif; font-size: 10px; color: #111827; }
        .header { margin-bottom: 10px; }
        .logo { height: 42px; width: auto; }
        h1 { font-size: 15px; margin: 0; }
        .muted { color: #6b7280; font-size: 9px; margin-top: 2px; }
        table { width: 100%; border-collapse: collapse; margin-top: 8px; }
        thead { display: table-header-group; }
        td, th { padding: 3px 4px; border-bottom: 1px solid #e5e7eb; vertical-align: top; }
        th { text-align: left; color: #374151; font-size: 9px; }
        .right { text-align: right; }
    </style>
</head>
<body>
    <div class="header">
        @if (!empty($logoDataUri))
            <img class="logo" src="{{ $logoDataUri }}" alt="Logo" />
        @endif
        <h1>{{ $t('report_license_plate', $locale === 'en' ? 'Reservations by license plate' : 'Rezervacije po tablici') }}</h1>
        @if (!empty($d['agency_name']))
            <div>{{ $d['agency_name'] }}</div>
        @endif
        <div>{{ $d['license_plate'] ?? '' }}</div>
        <div class="muted">{{ ($d['date_from'] ?? '') }} — {{ ($d['date_to'] ?? '') }}</div>
        <div class="muted">{{ $countLabel }}</div>
        <div class="muted">
            {{ $t('plate_amount_disclaimer', $locale === 'en'
                ? 'The amount total adds the recorded reservation amounts in this list. Free reservations are shown as 0.00. This is not a bank-confirmed payment total.'
                : 'Ukupan iznos sabira zabilježene iznose rezervacija na ovom spisku. Besplatne rezervacije su prikazane kao 0.00. Ovo nije bankom potvrđen zbir uplata.') }}
        </div>
    </div>

    @if ($lines === [])
        <p>{{ $t('plate_empty', $locale === 'en' ? 'No reservations in the selected period.' : 'Nema rezervacija u izabranom periodu.') }}</p>
    @else
        <table>
            <thead>
                <tr>
                    <th>{{ $t('col_date', $locale === 'en' ? 'Date' : 'Datum') }}</th>
                    <th>{{ $t('registration_plates', $locale === 'en' ? 'Registration plates' : 'Tablice') }}</th>
                    <th>{{ $t('vehicle_category', $locale === 'en' ? 'Vehicle category' : 'Kategorija vozila') }}</th>
                    <th>{{ $t('col_arrival', $locale === 'en' ? 'Arrival' : 'Dolazak') }}</th>
                    <th>{{ $t('col_departure', $locale === 'en' ? 'Departure' : 'Odlazak') }}</th>
                    <th>{{ $t('col_mtid', $locale === 'en' ? 'Transaction' : 'Transakcija') }}</th>
                    <th>{{ $t('col_status', $locale === 'en' ? 'Status' : 'Status') }}</th>
                    <th class="right">{{ $t('col_amount', $locale === 'en' ? 'Amount' : 'Iznos') }}</th>
                </tr>
            </thead>
            <tbody>
                @foreach ($lines as $line)
                    <tr>
                        <td>{{ $line['date'] }}</td>
                        <td>{{ $line['license_plate'] }}</td>
                        <td>{{ $line['category'] }}</td>
                        <td>{{ $line['daily_ticket'] ? $dailyLabel : ($line['drop_off_slot'] ?? '—') }}</td>
                        <td>{{ $line['daily_ticket'] ? $dailyLabel : ($line['pick_up_slot'] ?? '—') }}</td>
                        <td>{{ $line['merchant_transaction_id'] ?? '—' }}</td>
                        <td>{{ $statusLabel((string) $line['status']) }}</td>
                        <td class="right">{{ $line['amount'] === null ? '—' : $line['amount'].' EUR' }}</td>
                    </tr>
                @endforeach
            </tbody>
            <tfoot>
                <tr>
                    <th colspan="7">{{ $t('plate_amount_total', $locale === 'en' ? 'Recorded amount total' : 'Ukupno zabilježeni iznos') }}</th>
                    <th class="right">{{ $d['amount_total'] ?? '0.00' }} EUR</th>
                </tr>
            </tfoot>
        </table>
    @endif
</body>
</html>
