@php
    /** @var array<string, mixed> $v2 */
    $v2 = $v2ReservationStats ?? [];
    /** @var array<string, mixed> $v1 */
    $v1 = $v1HistoricalEstimate ?? [];
    $v1Sort = $v1Sort ?? 'confidence';
    $linked = $v1['linked_reservations'] ?? [];

    $fmtDate = fn (?string $d) => $d ? \Carbon\Carbon::parse($d)->format('d.m.Y.') : '—';
    $fmtMoney = fn ($n) => number_format((float) $n, 2, '.', '').' EUR';

    $confidenceBadge = fn (string $level) => match ($level) {
        \App\Support\AgencyHeuristicConfidence::HIGH => 'bg-green-100 text-green-800 border-green-200',
        \App\Support\AgencyHeuristicConfidence::MEDIUM => 'bg-amber-100 text-amber-900 border-amber-200',
        default => 'bg-gray-100 text-gray-700 border-gray-200',
    };

    $sortUrl = fn (string $sort) => route('panel_admin.agencies.show', ['user' => $user->id, 'v1_sort' => $sort], false);
@endphp

<section class="bg-white shadow rounded-lg p-4 sm:p-6 space-y-6">
    <div>
        <h2 class="text-lg font-semibold text-gray-900">Statistika rezervacija</h2>
        <p class="text-sm text-gray-600 mt-1">Pregled operativnih podataka za agenciju.</p>
    </div>

    <div class="rounded-lg border border-red-100 bg-red-50/40 p-4 sm:p-5 space-y-4">
        <div>
            <h3 class="text-base font-semibold text-gray-900">Službena V2 statistika</h3>
            <p class="text-xs text-gray-600 mt-1">
                Period: tekuća kalendarska godina ({{ $v2['period_year'] ?? now()->year }}).
                Samo autoritativni V2 podaci vezani za ovu agenciju (<code class="text-xs">user_id</code>).
            </p>
        </div>

        <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-3 gap-4 text-sm">
            <div class="space-y-1">
                <div class="font-medium text-gray-800">Rezervacije</div>
                <div>Ukupno: <span class="font-semibold">{{ $v2['total_reservations'] ?? 0 }}</span></div>
                <div>Plaćene: <span class="font-semibold">{{ $v2['paid_reservations'] ?? 0 }}</span></div>
                <div>Besplatne: <span class="font-semibold">{{ $v2['free_reservations'] ?? 0 }}</span></div>
            </div>
            <div class="space-y-1">
                <div class="font-medium text-gray-800">Tip rezervacije</div>
                <div>Termini: <span class="font-semibold">{{ $v2['time_slots_count'] ?? 0 }}</span></div>
                <div>Dnevna naknada: <span class="font-semibold">{{ $v2['daily_ticket_count'] ?? 0 }}</span></div>
            </div>
            <div class="space-y-1">
                <div class="font-medium text-gray-800">Finansije (plaćeno)</div>
                <div>Ukupno: <span class="font-semibold">{{ $fmtMoney($v2['total_paid_amount'] ?? 0) }}</span></div>
                <div>Termini: <span class="font-semibold">{{ $fmtMoney($v2['time_slots_paid_amount'] ?? 0) }}</span></div>
                <div>Dnevna naknada: <span class="font-semibold">{{ $fmtMoney($v2['daily_ticket_paid_amount'] ?? 0) }}</span></div>
            </div>
            <div class="space-y-1">
                <div class="font-medium text-gray-800">Vozila</div>
                <div>Različite tablice (godina): <span class="font-semibold">{{ $v2['distinct_license_plates'] ?? 0 }}</span></div>
                <div>Aktivna vozila agencije: <span class="font-semibold">{{ $v2['active_vehicles'] ?? 0 }}</span></div>
            </div>
            <div class="space-y-1">
                <div class="font-medium text-gray-800">Aktivnost agencije</div>
                <div>Prva rezervacija: <span class="font-semibold">{{ $fmtDate($v2['first_reservation_date'] ?? null) }}</span></div>
                <div>Posljednja rezervacija: <span class="font-semibold">{{ $fmtDate($v2['last_reservation_date'] ?? null) }}</span></div>
            </div>
            <div class="space-y-1">
                <div class="font-medium text-gray-800">Dodatno</div>
                <div>Prosjek rezervacija / mjesec: <span class="font-semibold">{{ $v2['avg_reservations_per_month'] ?? 0 }}</span></div>
            </div>
        </div>
    </div>

    <div class="rounded-lg border border-dashed border-gray-300 bg-gray-50 p-4 sm:p-5 space-y-4">
        <div>
            <h3 class="text-base font-semibold text-gray-900">Procijenjena V1 istorija</h3>
            <p class="mt-2 text-sm text-amber-900 bg-amber-50 border border-amber-200 rounded-md p-3">
                Procijenjena istorijska statistika rekonstruisana iz V1 rezervacija heurističkim uparivanjem.
                Informativno — ne koristi se za izvještaje, fakture, dozvole ili poslovnu logiku.
            </p>
        </div>

        <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-3 gap-4 text-sm">
            <div class="space-y-1">
                <div class="font-medium text-gray-800">Procijenjena istorijska aktivnost</div>
                <div>Prva procijenjena rezervacija: <span class="font-semibold">{{ $fmtDate($v1['estimated_first_reservation'] ?? null) }}</span></div>
                <div>Posljednja procijenjena rezervacija: <span class="font-semibold">{{ $fmtDate($v1['estimated_last_reservation'] ?? null) }}</span></div>
            </div>
            <div class="space-y-1">
                <div class="font-medium text-gray-800">Sažetak pouzdanosti</div>
                <div class="text-gray-700">Procijenjene povezane rezervacije: <span class="font-semibold text-gray-900">{{ $v1['linked_total'] ?? 0 }}</span></div>
                <div>Visoka: <span class="font-semibold">{{ $v1['high_confidence'] ?? 0 }}</span></div>
                <div>Srednja: <span class="font-semibold">{{ $v1['medium_confidence'] ?? 0 }}</span></div>
                <div>Niska: <span class="font-semibold">{{ $v1['low_confidence'] ?? 0 }}</span></div>
            </div>
            <div class="space-y-1">
                <div class="font-medium text-gray-800">Status</div>
                <div>Procijenjene plaćene: <span class="font-semibold">{{ $v1['estimated_paid'] ?? 0 }}</span></div>
                <div>Procijenjene besplatne: <span class="font-semibold">{{ $v1['estimated_free'] ?? 0 }}</span></div>
                <div>Procijenjeni prihod: <span class="font-semibold">{{ $fmtMoney($v1['estimated_revenue'] ?? 0) }}</span></div>
            </div>
        </div>

        <details class="rounded-md border border-gray-200 bg-white" @if(count($linked) > 0) open @endif>
            <summary class="cursor-pointer px-4 py-3 text-sm font-semibold text-gray-900">
                Procijenjene povezane rezervacije ({{ count($linked) }})
            </summary>
            <div class="px-4 pb-4 space-y-3">
                <div class="flex flex-wrap gap-3 text-xs">
                    <span class="text-gray-600">Sortiranje:</span>
                    <a href="{{ $sortUrl('confidence') }}"
                       class="font-medium {{ $v1Sort === 'confidence' ? 'text-red-800 underline' : 'text-red-700 hover:underline' }}">
                        Pouzdanost (visoka prvo)
                    </a>
                    <a href="{{ $sortUrl('date') }}"
                       class="font-medium {{ $v1Sort === 'date' ? 'text-red-800 underline' : 'text-red-700 hover:underline' }}">
                        Datum (najnovije prvo)
                    </a>
                </div>
                <div class="overflow-x-auto">
                    <table class="min-w-full text-left text-sm">
                        <thead>
                            <tr class="border-b border-gray-200 text-gray-600">
                                <th class="py-2 pr-4">ID</th>
                                <th class="py-2 pr-4">Datum</th>
                                <th class="py-2 pr-4">Tablica</th>
                                <th class="py-2 pr-4">Email</th>
                                <th class="py-2 pr-4">Država</th>
                                <th class="py-2 pr-4">Tip</th>
                                <th class="py-2 pr-4">Iznos</th>
                                <th class="py-2 pr-4">Pouzdanost</th>
                                <th class="py-2 pr-4">Razlog</th>
                            </tr>
                        </thead>
                        <tbody>
                            @forelse ($linked as $row)
                                <tr class="border-b border-gray-100">
                                    <td class="py-2 pr-4 whitespace-nowrap">
                                        <a class="text-red-700 underline font-medium" href="{{ route('panel_admin.reservations.show', ['reservation' => $row['id']], false) }}">
                                            #{{ $row['id'] }}
                                        </a>
                                    </td>
                                    <td class="py-2 pr-4 whitespace-nowrap">{{ $fmtDate($row['reservation_date'] ?? null) }}</td>
                                    <td class="py-2 pr-4 whitespace-nowrap">{{ $row['license_plate'] ?? '—' }}</td>
                                    <td class="py-2 pr-4">{{ $row['email'] ?? '—' }}</td>
                                    <td class="py-2 pr-4 whitespace-nowrap">{{ $row['country'] ?? '—' }}</td>
                                    <td class="py-2 pr-4 whitespace-nowrap">{{ $row['reservation_kind_label'] ?? '—' }}</td>
                                    <td class="py-2 pr-4 whitespace-nowrap">
                                        @if (($row['status'] ?? '') === 'paid')
                                            {{ $fmtMoney($row['amount'] ?? 0) }}
                                        @else
                                            —
                                        @endif
                                    </td>
                                    <td class="py-2 pr-4 whitespace-nowrap">
                                        <span class="inline-flex items-center rounded-full border px-2 py-0.5 text-xs font-medium {{ $confidenceBadge((string) ($row['confidence'] ?? '')) }}">
                                            {{ $row['confidence_label'] ?? '—' }}
                                        </span>
                                    </td>
                                    <td class="py-2 pr-4 text-gray-700">{{ $row['matching_reason'] ?? '—' }}</td>
                                </tr>
                            @empty
                                <tr><td class="py-4 text-gray-600" colspan="9">Nema procijenjenih povezanih V1 rezervacija.</td></tr>
                            @endforelse
                        </tbody>
                    </table>
                </div>
            </div>
        </details>
    </div>
</section>
