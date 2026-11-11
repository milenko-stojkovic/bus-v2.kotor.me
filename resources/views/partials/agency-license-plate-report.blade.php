@php
    $locale = $locale ?? app()->getLocale();
    $s = fn (string $key, ?string $fallback = null) => \App\Support\UiText::t('statistics', $key, $fallback, $locale);
    $p = fn (string $key, ?string $fallback = null) => \App\Support\UiText::t('panel', $key, $fallback, $locale);
    $report = $license_plate_report;
    $dailyLabel = $p('daily_ticket_label', $locale === 'en' ? 'Daily fee' : 'Dnevna naknada');
    $statusLabel = function (string $status) use ($s, $locale): string {
        return match ($status) {
            'paid' => $s('status_paid', $locale === 'en' ? 'Paid' : 'Plaćeno'),
            'free' => $s('status_free', $locale === 'en' ? 'Free' : 'Besplatno'),
            default => $status,
        };
    };
    $countLabel = str_replace(
        ':count',
        (string) ($report['reservation_count'] ?? 0),
        $s('plate_count', $locale === 'en' ? 'Reservations: :count' : 'Rezervacije: :count'),
    );
@endphp

<div class="bg-white overflow-hidden shadow-sm sm:rounded-lg">
    <div class="p-6 space-y-4">
        <h3 class="text-lg font-medium text-gray-900">
            {{ $s('report_license_plate', $locale === 'en' ? 'Reservations by license plate' : 'Rezervacije po tablici') }}
        </h3>
        <p class="text-sm text-gray-700">
            {{ $report['license_plate'] }}
            <span class="text-gray-500">{{ $report['date_from'] }} — {{ $report['date_to'] }}</span>
        </p>
        <p class="text-sm text-gray-600">
            {{ $s('plate_amount_disclaimer', $locale === 'en'
                ? 'The amount total adds the recorded reservation amounts in this list. Free reservations are shown as 0.00. This is not a bank-confirmed payment total.'
                : 'Ukupan iznos sabira zabilježene iznose rezervacija na ovom spisku. Besplatne rezervacije su prikazane kao 0.00. Ovo nije bankom potvrđen zbir uplata.') }}
        </p>
        <p class="text-sm font-medium text-gray-900">{{ $countLabel }}</p>

        @if (($report['lines'] ?? []) === [])
            <p class="text-sm text-gray-500">
                {{ $s('plate_empty', $locale === 'en' ? 'No reservations in the selected period.' : 'Nema rezervacija u izabranom periodu.') }}
            </p>
        @else
            <div class="overflow-x-auto">
                <table class="min-w-full divide-y divide-gray-200 text-sm">
                    <thead class="bg-red-50">
                        <tr>
                            <th class="px-3 py-2 text-left font-medium text-gray-700">{{ $s('col_date', $locale === 'en' ? 'Date' : 'Datum') }}</th>
                            <th class="px-3 py-2 text-left font-medium text-gray-700">{{ $s('registration_plates', $locale === 'en' ? 'Registration plates' : 'Tablice') }}</th>
                            <th class="px-3 py-2 text-left font-medium text-gray-700">{{ $s('vehicle_category', $locale === 'en' ? 'Vehicle category' : 'Kategorija vozila') }}</th>
                            <th class="px-3 py-2 text-left font-medium text-gray-700">{{ $s('col_arrival', $locale === 'en' ? 'Arrival' : 'Dolazak') }}</th>
                            <th class="px-3 py-2 text-left font-medium text-gray-700">{{ $s('col_departure', $locale === 'en' ? 'Departure' : 'Odlazak') }}</th>
                            <th class="px-3 py-2 text-left font-medium text-gray-700">{{ $s('col_mtid', $locale === 'en' ? 'Transaction' : 'Transakcija') }}</th>
                            <th class="px-3 py-2 text-left font-medium text-gray-700">{{ $s('col_status', $locale === 'en' ? 'Status' : 'Status') }}</th>
                            <th class="px-3 py-2 text-right font-medium text-gray-700">{{ $s('col_amount', $locale === 'en' ? 'Amount' : 'Iznos') }}</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-gray-200">
                        @foreach ($report['lines'] as $line)
                            <tr>
                                <td class="px-3 py-2 whitespace-nowrap text-gray-900">{{ $line['date'] }}</td>
                                <td class="px-3 py-2 text-gray-900">{{ $line['license_plate'] }}</td>
                                <td class="px-3 py-2 text-gray-700">{{ $line['category'] }}</td>
                                <td class="px-3 py-2 text-gray-700">{{ $line['daily_ticket'] ? $dailyLabel : ($line['drop_off_slot'] ?? '—') }}</td>
                                <td class="px-3 py-2 text-gray-700">{{ $line['daily_ticket'] ? $dailyLabel : ($line['pick_up_slot'] ?? '—') }}</td>
                                <td class="px-3 py-2 text-gray-700">{{ $line['merchant_transaction_id'] ?? '—' }}</td>
                                <td class="px-3 py-2 text-gray-900">{{ $statusLabel((string) $line['status']) }}</td>
                                <td class="px-3 py-2 text-right text-gray-900 tabular-nums">{{ $line['amount'] === null ? '—' : $line['amount'].' €' }}</td>
                            </tr>
                        @endforeach
                    </tbody>
                    <tfoot>
                        <tr>
                            <th colspan="7" class="px-3 py-2 text-left text-gray-700">{{ $s('plate_amount_total', $locale === 'en' ? 'Recorded amount total' : 'Ukupno zabilježeni iznos') }}</th>
                            <th class="px-3 py-2 text-right text-gray-900 tabular-nums">{{ $report['amount_total'] }} €</th>
                        </tr>
                    </tfoot>
                </table>
            </div>
        @endif
    </div>
</div>
