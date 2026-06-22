@php
    $fmtMoney = fn (float $v) => number_format($v, 2, '.', '').' EUR';
    $fmtPct = fn (float $v) => number_format($v * 100, 1, '.', '').'%';
    $st = \App\Support\AdminAnalyticsSectionTexts::all();
@endphp

@component('layouts.admin-panel', ['pageTitle' => $pageTitle ?? 'Analitika', 'navActive' => 'analytics'])
    <div class="space-y-6">
        <div class="flex flex-wrap items-start justify-between gap-3">
            <div>
                <h1 class="text-lg font-semibold text-gray-900">Analitika</h1>
                <p class="text-sm text-gray-600 mt-1">Pregled metrike i operativnih pokazatelja za izabrani period.</p>
            </div>
        </div>

        @if ($errors->any())
            <div class="rounded-md bg-red-50 p-4 text-sm text-red-800 space-y-1">
                @foreach ($errors->all() as $err)
                    <div>{{ $err }}</div>
                @endforeach
            </div>
        @endif

        <form method="get" action="{{ route('panel_admin.analytics', [], false) }}" class="bg-white shadow rounded-lg p-5 border border-red-100 space-y-4">
            <input type="hidden" name="show" value="1">
            <div class="grid grid-cols-1 md:grid-cols-4 gap-4 items-end">
                <div>
                    <x-input-label for="date_from" value="Datum od" />
                    <input type="date" id="date_from" name="date_from" min="{{ $minDate }}" max="{{ $maxDate }}"
                           value="{{ $dateFrom }}"
                           class="mt-1 block w-full rounded-md border-red-200 shadow-sm" />
                </div>
                <div>
                    <x-input-label for="date_to" value="Datum do" />
                    <input type="date" id="date_to" name="date_to" min="{{ $minDate }}" max="{{ $maxDate }}"
                           value="{{ $dateTo }}"
                           class="mt-1 block w-full rounded-md border-red-200 shadow-sm" />
                </div>
                <div class="flex items-center gap-2">
                    <input type="checkbox" id="include_free" name="include_free" value="1" class="rounded border-red-200"
                        @checked($includeFree) />
                    <label for="include_free" class="text-sm text-gray-700">Uključi besplatne rezervacije</label>
                </div>
                <div class="flex gap-2 justify-end">
                    <button type="submit"
                        class="inline-flex items-center px-4 py-2 bg-red-700 border border-transparent rounded-md font-semibold text-xs text-white uppercase tracking-widest hover:bg-red-800">
                        Prikaži
                    </button>
                    <a href="{{ route('panel_admin.analytics.pdf', ['date_from' => $dateFrom, 'date_to' => $dateTo, 'include_free' => $includeFree ? 1 : 0], false) }}"
                        class="inline-flex items-center px-4 py-2 border border-red-200 rounded-md font-semibold text-xs text-gray-700 uppercase tracking-widest hover:bg-red-50">
                        Generiši PDF
                    </a>
                </div>
            </div>
        </form>

        @if ($dataset)
            @php
                $k = $dataset['kpi'];
                $lm = $dataset['limo'] ?? [];
                $rk = $dataset['reservation_kinds'] ?? [];
                $tsKind = $rk['time_slots'] ?? [];
                $dtKind = $rk['daily_ticket'] ?? [];
                $dtLimo = $dtKind['limo'] ?? [];
                $dtBuses = $dtKind['buses'] ?? [];
            @endphp
            <p class="text-sm text-gray-600">{{ $st['kpi'] ?? '' }}</p>
            <section class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-3 xl:grid-cols-6 gap-3">
                <div class="bg-white shadow rounded-lg p-4 border border-red-100">
                    <div class="text-xs font-medium text-gray-500 uppercase tracking-wide">Termini</div>
                    <div class="mt-2 text-sm text-gray-700">Broj: <span class="font-semibold text-gray-900">{{ (int) ($tsKind['count'] ?? 0) }}</span></div>
                    <div class="text-sm text-gray-700">Prihod (paid): <span class="font-semibold text-gray-900">{{ $fmtMoney((float) ($tsKind['revenue'] ?? 0)) }}</span></div>
                </div>
                <div class="bg-white shadow rounded-lg p-4 border border-red-100">
                    <div class="text-xs font-medium text-gray-500 uppercase tracking-wide">Dnevna naknada ukupno</div>
                    <div class="mt-2 text-sm text-gray-700">Broj: <span class="font-semibold text-gray-900">{{ (int) ($dtKind['count'] ?? 0) }}</span></div>
                    <div class="text-sm text-gray-700">Prihod (paid): <span class="font-semibold text-gray-900">{{ $fmtMoney((float) ($dtKind['revenue'] ?? 0)) }}</span></div>
                </div>
                <div class="bg-white shadow rounded-lg p-4 border border-red-100">
                    <div class="text-xs font-medium text-gray-500 uppercase tracking-wide">Dnevna naknada — Limo</div>
                    <div class="mt-2 text-sm text-gray-700">Broj: <span class="font-semibold text-gray-900">{{ (int) ($dtLimo['count'] ?? 0) }}</span></div>
                    <div class="text-sm text-gray-700">Prihod (paid): <span class="font-semibold text-gray-900">{{ $fmtMoney((float) ($dtLimo['revenue'] ?? 0)) }}</span></div>
                </div>
                <div class="bg-white shadow rounded-lg p-4 border border-red-100">
                    <div class="text-xs font-medium text-gray-500 uppercase tracking-wide">Dnevna naknada — Autobusi</div>
                    <div class="mt-2 text-sm text-gray-700">Broj: <span class="font-semibold text-gray-900">{{ (int) ($dtBuses['count'] ?? 0) }}</span></div>
                    <div class="text-sm text-gray-700">Prihod (paid): <span class="font-semibold text-gray-900">{{ $fmtMoney((float) ($dtBuses['revenue'] ?? 0)) }}</span></div>
                </div>
                <div class="bg-white shadow rounded-lg p-4 border border-red-100 ring-1 ring-red-100">
                    <div class="text-xs font-medium text-gray-500 uppercase tracking-wide">Ukupno prihod (rezervacije)</div>
                    <div class="mt-2 text-sm text-gray-700">Paid rezervacije: <span class="font-semibold text-gray-900">{{ $k['paid_reservations'] }}</span></div>
                    <div class="text-sm text-gray-700">Prihod: <span class="font-semibold text-gray-900">{{ $fmtMoney((float) ($k['total_revenue'] ?? $k['revenue_reservations'])) }}</span></div>
                </div>
                <div class="bg-white shadow rounded-lg p-4 border border-red-100">
                    <div class="text-xs font-medium text-gray-500 uppercase tracking-wide">Ukupno rezervacija</div>
                    <div class="mt-2 text-sm text-gray-700">Termini + DN: <span class="font-semibold text-gray-900">{{ (int) ($k['total_reservations_all_kinds'] ?? $k['reservations_total']) }}</span></div>
                </div>
            </section>
            <section class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-3">
                <div class="bg-white shadow rounded-lg p-4 border border-red-100">
                    <div class="text-xs text-gray-500">Prihod od rezervacija (paid)</div>
                    <div class="text-lg font-semibold text-gray-900">{{ $fmtMoney($k['revenue_reservations']) }}</div>
                </div>
                <div class="bg-white shadow rounded-lg p-4 border border-red-100">
                    <div class="text-xs text-gray-500">Prihod od Limo servisa</div>
                    <div class="text-lg font-semibold text-gray-900">{{ $fmtMoney((float) ($lm['revenue_total'] ?? 0)) }}</div>
                </div>
                <div class="bg-white shadow rounded-lg p-4 border border-red-100 ring-1 ring-red-100">
                    <div class="text-xs text-gray-500">Ukupan prihod (rezervacije + Limo)</div>
                    <div class="text-lg font-semibold text-gray-900">{{ $fmtMoney($k['revenue_grand_total']) }}</div>
                </div>
                <div class="bg-white shadow rounded-lg p-4 border border-red-100">
                    <div class="text-xs text-gray-500">Broj rezervacija</div>
                    <div class="text-lg font-semibold text-gray-900">{{ $k['reservations_total'] }}</div>
                </div>
                <div class="bg-white shadow rounded-lg p-4 border border-red-100">
                    <div class="text-xs text-gray-500">Paid / Free</div>
                    <div class="text-lg font-semibold text-gray-900">{{ $k['paid_reservations'] }} / {{ $k['free_reservations'] }}</div>
                </div>
                <div class="bg-white shadow rounded-lg p-4 border border-red-100">
                    <div class="text-xs text-gray-500">Prosječan prihod po paid</div>
                    <div class="text-lg font-semibold text-gray-900">{{ $fmtMoney($k['avg_revenue_per_paid']) }}</div>
                </div>
                <div class="bg-white shadow rounded-lg p-4 border border-red-100">
                    <div class="text-xs text-gray-500">Ukupan broj zauzetih slotova</div>
                    <div class="text-lg font-semibold text-gray-900">{{ $k['occupied_slots_total'] }}</div>
                    <div class="text-xs text-gray-500 mt-1">Samo rezervacije sa terminima. Dnevna naknada ne ulazi u popunjenost termina.</div>
                </div>
                <div class="bg-white shadow rounded-lg p-4 border border-red-100">
                    <div class="text-xs text-gray-500">Prosječna popunjenost (slot-level)</div>
                    <div class="text-lg font-semibold text-gray-900">{{ $fmtPct($k['avg_occupancy_slot_level']) }}</div>
                    <div class="text-xs text-gray-500 mt-1">Samo termini / time_slots.</div>
                </div>
                <div class="bg-white shadow rounded-lg p-4 border border-red-100">
                    <div class="text-xs text-gray-500">Broj blokiranih slotova</div>
                    <div class="text-lg font-semibold text-gray-900">{{ $k['blocked_slot_rows'] }}</div>
                </div>
                <div class="bg-white shadow rounded-lg p-4 border border-red-100">
                    <div class="text-xs text-gray-500">Izgubljeni kapacitet (blokiranje)</div>
                    <div class="text-lg font-semibold text-gray-900">{{ $fmtPct($k['blocked_capacity_pct']) }}</div>
                </div>
            </section>

            <section class="bg-white shadow rounded-lg p-6 border border-red-100">
                <h2 class="text-base font-semibold text-gray-900">Dnevna naknada</h2>
                <p class="text-sm text-gray-600 mt-1">{{ $st['daily_fee'] ?? '' }}</p>
                <div class="mt-4 overflow-x-auto">
                    <table class="min-w-full text-sm">
                        <thead class="text-xs text-gray-500 uppercase">
                            <tr class="border-b">
                                <th class="py-2 pr-4 text-left">Kategorija</th>
                                <th class="py-2 pr-4 text-right">Broj vozila</th>
                                <th class="py-2 pr-4 text-right">Prihod</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y">
                            @foreach (($dataset['daily_fee']['rows'] ?? []) as $row)
                                <tr>
                                    <td class="py-2 pr-4 @if(!empty($row['is_total'])) font-semibold @endif">{{ $row['label'] }}</td>
                                    <td class="py-2 pr-4 text-right @if(!empty($row['is_total'])) font-semibold @endif">{{ (int) ($row['count'] ?? 0) }}</td>
                                    <td class="py-2 pr-4 text-right @if(!empty($row['is_total'])) font-semibold @endif">{{ $fmtMoney((float) ($row['revenue'] ?? 0)) }}</td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            </section>

            <section class="bg-white shadow rounded-lg p-6 border border-red-100">
                <h2 class="text-base font-semibold text-gray-900">Limo pickup (evidencija)</h2>
                <p class="text-sm text-gray-600 mt-1">{{ $st['limo'] ?? '' }}</p>
                <div class="mt-4 grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-3">
                    <div class="rounded-lg border border-red-100 bg-red-50 p-4">
                        <div class="text-xs text-gray-500">Ukupan prihod od Limo servisa</div>
                        <div class="text-lg font-semibold text-gray-900">{{ $fmtMoney((float) ($lm['revenue_total'] ?? 0)) }}</div>
                    </div>
                    <div class="rounded-lg border border-red-100 bg-red-50 p-4">
                        <div class="text-xs text-gray-500">Broj evidentiranih Limo pickup-a</div>
                        <div class="text-lg font-semibold text-gray-900">{{ (int) ($lm['pickup_count'] ?? 0) }}</div>
                    </div>
                    <div class="rounded-lg border border-red-100 bg-red-50 p-4">
                        <div class="text-xs text-gray-500">QR pickup</div>
                        <div class="text-lg font-semibold text-gray-900">{{ (int) ($lm['qr_count'] ?? 0) }}</div>
                    </div>
                    <div class="rounded-lg border border-red-100 bg-red-50 p-4">
                        <div class="text-xs text-gray-500">Pickup preko tablice</div>
                        <div class="text-lg font-semibold text-gray-900">{{ (int) ($lm['plate_count'] ?? 0) }}</div>
                    </div>
                    <div class="rounded-lg border border-red-100 bg-red-50 p-4">
                        <div class="text-xs text-gray-500">Fiskalizovano</div>
                        <div class="text-lg font-semibold text-gray-900">{{ (int) ($lm['fiscalized_count'] ?? 0) }}</div>
                    </div>
                    <div class="rounded-lg border border-red-100 bg-red-50 p-4">
                        <div class="text-xs text-gray-500">U obradi</div>
                        <div class="text-lg font-semibold text-gray-900">{{ (int) ($lm['pending_fiscal_count'] ?? 0) }}</div>
                    </div>
                    <div class="rounded-lg border border-red-100 bg-red-50 p-4">
                        <div class="text-xs text-gray-500">Greška fiskalizacije</div>
                        <div class="text-lg font-semibold text-gray-900">{{ (int) ($lm['fiscal_failed_count'] ?? 0) }}</div>
                    </div>
                </div>
            </section>

            <section class="bg-white shadow rounded-lg p-6 border border-red-100">
                <h2 class="text-base font-semibold text-gray-900">Trend po danima</h2>
                <p class="text-sm text-gray-600 mt-1">{{ $st['trend'] ?? '' }}</p>
                <div class="mt-4 overflow-x-auto">
                    <table class="min-w-full text-sm">
                        <thead class="text-xs text-gray-500 uppercase">
                            <tr class="border-b">
                                <th class="py-2 pr-4 text-left">Datum</th>
                                <th class="py-2 pr-4 text-right">Rezervacije</th>
                                <th class="py-2 pr-4 text-right">Paid</th>
                                <th class="py-2 pr-4 text-right">Free</th>
                                <th class="py-2 pr-4 text-right">Zauzeti slotovi</th>
                                <th class="py-2 pr-4 text-right">Prihod</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y">
                            @foreach ($dataset['trend_by_day'] as $row)
                                <tr>
                                    <td class="py-2 pr-4">{{ $row['date'] }}</td>
                                    <td class="py-2 pr-4 text-right">{{ $row['reservations'] }}</td>
                                    <td class="py-2 pr-4 text-right">{{ $row['paid'] }}</td>
                                    <td class="py-2 pr-4 text-right">{{ $row['free'] }}</td>
                                    <td class="py-2 pr-4 text-right">{{ $row['occupied_slots'] }}</td>
                                    <td class="py-2 pr-4 text-right">{{ $fmtMoney($row['revenue']) }}</td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            </section>

            <div class="grid grid-cols-1 lg:grid-cols-2 gap-6">
                <section class="bg-white shadow rounded-lg p-6 border border-red-100">
                    <h2 class="text-base font-semibold text-gray-900">Analiza po delovima dana</h2>
                    <p class="text-sm text-gray-600 mt-1">{{ $st['day_parts'] ?? '' }} Samo rezervacije sa terminima. Dnevna naknada ne ulazi u popunjenost termina.</p>
                    <div class="mt-4 overflow-x-auto">
                        <table class="min-w-full text-sm">
                            <thead class="text-xs text-gray-500 uppercase">
                                <tr class="border-b">
                                    <th class="py-2 pr-4 text-left">Prozor</th>
                                    <th class="py-2 pr-4 text-right">Rezervacije</th>
                                    <th class="py-2 pr-4 text-right">Zauzeti slotovi</th>
                                    <th class="py-2 pr-4 text-right">Prihod</th>
                                    <th class="py-2 pr-4 text-right">Udeo zauzetosti</th>
                                </tr>
                            </thead>
                            <tbody class="divide-y">
                                @foreach ($dataset['day_parts'] as $row)
                                    <tr>
                                        <td class="py-2 pr-4">{{ $row['label'] }}</td>
                                        <td class="py-2 pr-4 text-right">{{ $row['reservations'] }}</td>
                                        <td class="py-2 pr-4 text-right">{{ $row['occupied_slots'] }}</td>
                                        <td class="py-2 pr-4 text-right">{{ $fmtMoney($row['revenue']) }}</td>
                                        <td class="py-2 pr-4 text-right">{{ $fmtPct($row['share_occupied']) }}</td>
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                    </div>
                </section>

                <section class="bg-white shadow rounded-lg p-6 border border-red-100">
                    <h2 class="text-base font-semibold text-gray-900">Paid vs Free</h2>
                    <p class="text-sm text-gray-600 mt-1">{{ $st['paid_vs_free'] ?? '' }}</p>
                    @php
                        $pf = $dataset['paid_vs_free'];
                    @endphp
                    <div class="mt-4 overflow-x-auto">
                        <table class="min-w-full text-sm">
                            <tbody class="divide-y">
                                <tr><td class="py-2 pr-4 text-gray-600">Paid rezervacije</td><td class="py-2 pr-4 text-right font-medium">{{ $pf['paid_reservations'] }}</td></tr>
                                <tr><td class="py-2 pr-4 text-gray-600">Free rezervacije</td><td class="py-2 pr-4 text-right font-medium">{{ $pf['free_reservations'] }}</td></tr>
                                <tr><td class="py-2 pr-4 text-gray-600">Zauzeti slotovi (paid)</td><td class="py-2 pr-4 text-right font-medium">{{ $pf['paid_occupied_slots'] }}</td></tr>
                                <tr><td class="py-2 pr-4 text-gray-600">Zauzeti slotovi (free)</td><td class="py-2 pr-4 text-right font-medium">{{ $pf['free_occupied_slots'] }}</td></tr>
                                <tr><td class="py-2 pr-4 text-gray-600">Prihod (paid)</td><td class="py-2 pr-4 text-right font-medium">{{ $fmtMoney($pf['paid_revenue']) }}</td></tr>
                                <tr><td class="py-2 pr-4 text-gray-600">% zauzetosti koje troše free (slot-level)</td><td class="py-2 pr-4 text-right font-medium">{{ $fmtPct($pf['free_capacity_pct_by_slots']) }}</td></tr>
                            </tbody>
                        </table>
                    </div>
                </section>
            </div>

            <section class="bg-white shadow rounded-lg p-6 border border-red-100">
                <h2 class="text-base font-semibold text-gray-900">Analiza po tipovima vozila</h2>
                <p class="text-sm text-gray-600 mt-1">{{ $st['vehicle_types'] ?? '' }} Kolona „Zauzeti slotovi” uključuje samo termine.</p>
                <div class="mt-4 overflow-x-auto">
                    <table class="min-w-full text-sm">
                        <thead class="text-xs text-gray-500 uppercase">
                            <tr class="border-b">
                                <th class="py-2 pr-4 text-left">Tip vozila</th>
                                <th class="py-2 pr-4 text-right">Rezervacije</th>
                                <th class="py-2 pr-4 text-right">Zauzeti slotovi</th>
                                <th class="py-2 pr-4 text-right">Prihod</th>
                                <th class="py-2 pr-4 text-right">Prosječno</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y">
                            @foreach ($dataset['by_vehicle_type'] as $row)
                                <tr>
                                    <td class="py-2 pr-4">{{ $row['name'] }}</td>
                                    <td class="py-2 pr-4 text-right">{{ $row['reservations'] }}</td>
                                    <td class="py-2 pr-4 text-right">{{ $row['occupied_slots'] }}</td>
                                    <td class="py-2 pr-4 text-right">{{ $fmtMoney($row['revenue']) }}</td>
                                    <td class="py-2 pr-4 text-right">{{ $fmtMoney($row['avg_revenue']) }}</td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            </section>

            <section class="bg-white shadow rounded-lg p-6 border border-red-100">
                <h2 class="text-base font-semibold text-gray-900">Analiza po agencijama</h2>
                <p class="text-sm text-gray-600 mt-1">{{ $st['agencies'] ?? '' }}</p>
                <div class="mt-4 overflow-x-auto">
                    <table class="min-w-full text-sm">
                        <thead class="text-xs text-gray-500 uppercase">
                            <tr class="border-b">
                                <th class="py-2 pr-4 text-left">Agencija</th>
                                <th class="py-2 pr-4 text-right">Prihod</th>
                                <th class="py-2 pr-4 text-right">% prihoda</th>
                                <th class="py-2 pr-4 text-right">Rezervacije</th>
                                <th class="py-2 pr-4 text-right">Paid</th>
                                <th class="py-2 pr-4 text-right">Free</th>
                                <th class="py-2 pr-4 text-right">% free</th>
                                <th class="py-2 pr-4 text-right">Prosj. prihod (paid)</th>
                                <th class="py-2 pr-4 text-right">Termini</th>
                                <th class="py-2 pr-4 text-right">Prihod (T)</th>
                                <th class="py-2 pr-4 text-right">DN Limo</th>
                                <th class="py-2 pr-4 text-right">Prihod (L)</th>
                                <th class="py-2 pr-4 text-right">DN Autobusi</th>
                                <th class="py-2 pr-4 text-right">Prihod (A)</th>
                                <th class="py-2 pr-4 text-right">DN ukupno</th>
                                <th class="py-2 pr-4 text-right">Prihod (DN)</th>
                                <th class="py-2 pr-4 text-right" title="Samo rezervacije sa terminima">Zauzeti slotovi</th>
                                <th class="py-2 pr-4 text-right">Prosj. slotova</th>
                                <th class="py-2 pr-4 text-left">Najčešći tip vozila</th>
                                <th class="py-2 pr-4 text-right">% tipa</th>
                                <th class="py-2 pr-4 text-right">Jutro %</th>
                                <th class="py-2 pr-4 text-right">Dan %</th>
                                <th class="py-2 pr-4 text-right">Veče %</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y">
                            @forelse (($dataset['by_agency'] ?? []) as $row)
                                <tr>
                                    <td class="py-2 pr-4 font-medium text-gray-900">{{ $row['agency'] }}</td>
                                    <td class="py-2 pr-4 text-right">{{ $fmtMoney((float) $row['revenue']) }}</td>
                                    <td class="py-2 pr-4 text-right">{{ $fmtPct((float) $row['revenue_share']) }}</td>
                                    <td class="py-2 pr-4 text-right">{{ (int) $row['reservations_total'] }}</td>
                                    <td class="py-2 pr-4 text-right">{{ (int) $row['paid_reservations'] }}</td>
                                    <td class="py-2 pr-4 text-right">{{ (int) $row['free_reservations'] }}</td>
                                    <td class="py-2 pr-4 text-right">{{ $fmtPct((float) $row['free_pct']) }}</td>
                                    <td class="py-2 pr-4 text-right">{{ $fmtMoney((float) $row['avg_revenue_per_paid']) }}</td>
                                    <td class="py-2 pr-4 text-right">{{ (int) ($row['time_slots_count'] ?? 0) }}</td>
                                    <td class="py-2 pr-4 text-right">{{ $fmtMoney((float) ($row['time_slots_revenue'] ?? 0)) }}</td>
                                    <td class="py-2 pr-4 text-right">{{ (int) ($row['daily_fee_limo_count'] ?? 0) }}</td>
                                    <td class="py-2 pr-4 text-right">{{ $fmtMoney((float) ($row['daily_fee_limo_revenue'] ?? 0)) }}</td>
                                    <td class="py-2 pr-4 text-right">{{ (int) ($row['daily_fee_buses_count'] ?? 0) }}</td>
                                    <td class="py-2 pr-4 text-right">{{ $fmtMoney((float) ($row['daily_fee_buses_revenue'] ?? 0)) }}</td>
                                    <td class="py-2 pr-4 text-right">{{ (int) ($row['daily_ticket_count'] ?? 0) }}</td>
                                    <td class="py-2 pr-4 text-right">{{ $fmtMoney((float) ($row['daily_ticket_revenue'] ?? 0)) }}</td>
                                    <td class="py-2 pr-4 text-right">{{ (int) $row['occupied_slots'] }}</td>
                                    <td class="py-2 pr-4 text-right">{{ number_format((float) $row['avg_slots_per_reservation'], 2, '.', '') }}</td>
                                    <td class="py-2 pr-4 text-gray-900">{{ $row['top_vehicle_type'] }}</td>
                                    <td class="py-2 pr-4 text-right">{{ $fmtPct((float) $row['top_vehicle_type_pct']) }}</td>
                                    <td class="py-2 pr-4 text-right">{{ $fmtPct((float) $row['morning_pct']) }}</td>
                                    <td class="py-2 pr-4 text-right">{{ $fmtPct((float) $row['day_pct']) }}</td>
                                    <td class="py-2 pr-4 text-right">{{ $fmtPct((float) $row['evening_pct']) }}</td>
                                </tr>
                            @empty
                                <tr>
                                    <td colspan="23" class="py-3 pr-4 text-sm text-gray-500">Nema podataka za izabrani period.</td>
                                </tr>
                            @endforelse
                        </tbody>
                    </table>
                </div>
            </section>

            <section class="bg-white shadow rounded-lg p-6 border border-red-100">
                <h2 class="text-base font-semibold text-gray-900">Besplatne rezervacije po agencijama</h2>
                <p class="text-sm text-gray-600 mt-1">{{ $st['admin_free_agencies'] ?? '' }}</p>
                <div class="mt-4 overflow-x-auto">
                    <table class="min-w-full text-sm">
                        <thead class="text-xs text-gray-500 uppercase">
                            <tr class="border-b">
                                <th class="py-2 pr-4 text-left">Agencija</th>
                                <th class="py-2 pr-4 text-right">Broj besplatnih rezervacija</th>
                                <th class="py-2 pr-4 text-right">Zauzeti slotovi</th>
                                <th class="py-2 pr-4 text-right">Prosj. slotova</th>
                                <th class="py-2 pr-4 text-left">Najčešći tip vozila</th>
                                <th class="py-2 pr-4 text-right">% tipa</th>
                                <th class="py-2 pr-4 text-right">Jutro %</th>
                                <th class="py-2 pr-4 text-right">Dan %</th>
                                <th class="py-2 pr-4 text-right">Veče %</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y">
                            @forelse (($dataset['admin_free_by_agency'] ?? []) as $row)
                                <tr>
                                    <td class="py-2 pr-4 font-medium text-gray-900">{{ $row['agency'] }}</td>
                                    <td class="py-2 pr-4 text-right">{{ (int) $row['reservations'] }}</td>
                                    <td class="py-2 pr-4 text-right">{{ (int) $row['occupied_slots'] }}</td>
                                    <td class="py-2 pr-4 text-right">{{ number_format((float) $row['avg_slots_per_reservation'], 2, '.', '') }}</td>
                                    <td class="py-2 pr-4 text-gray-900">{{ $row['top_vehicle_type'] }}</td>
                                    <td class="py-2 pr-4 text-right">{{ $fmtPct((float) $row['top_vehicle_type_pct']) }}</td>
                                    <td class="py-2 pr-4 text-right">{{ $fmtPct((float) $row['morning_pct']) }}</td>
                                    <td class="py-2 pr-4 text-right">{{ $fmtPct((float) $row['day_pct']) }}</td>
                                    <td class="py-2 pr-4 text-right">{{ $fmtPct((float) $row['evening_pct']) }}</td>
                                </tr>
                            @empty
                                <tr>
                                    <td colspan="9" class="py-3 pr-4 text-sm text-gray-500">Nema podataka za izabrani period.</td>
                                </tr>
                            @endforelse
                        </tbody>
                    </table>
                </div>
            </section>

            <section class="bg-white shadow rounded-lg p-6 border border-red-100">
                <h2 class="text-base font-semibold text-gray-900">Analiza po državama</h2>
                <p class="text-sm text-gray-600 mt-1">{{ $st['countries'] ?? '' }}</p>
                <div class="mt-4 overflow-x-auto">
                    <table class="min-w-full text-sm">
                        <thead class="text-xs text-gray-500 uppercase">
                            <tr class="border-b">
                                <th class="py-2 pr-4 text-left">Država</th>
                                <th class="py-2 pr-4 text-right">Rezervacije</th>
                                <th class="py-2 pr-4 text-right">Paid</th>
                                <th class="py-2 pr-4 text-right">Free</th>
                                <th class="py-2 pr-4 text-right">Prihod</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y">
                            @foreach ($dataset['by_country'] as $row)
                                <tr>
                                    <td class="py-2 pr-4">{{ $row['country'] }}</td>
                                    <td class="py-2 pr-4 text-right">{{ $row['reservations'] }}</td>
                                    <td class="py-2 pr-4 text-right">{{ $row['paid'] }}</td>
                                    <td class="py-2 pr-4 text-right">{{ $row['free'] }}</td>
                                    <td class="py-2 pr-4 text-right">{{ $fmtMoney($row['revenue']) }}</td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            </section>

            <div class="grid grid-cols-1 lg:grid-cols-2 gap-6">
                <section class="bg-white shadow rounded-lg p-6 border border-red-100">
                    <h2 class="text-base font-semibold text-gray-900">Blokiranje i izgubljeni kapacitet</h2>
                    <p class="text-sm text-gray-600 mt-1">{{ $st['blocking'] ?? '' }}</p>
                    @php
                        $b = $dataset['blocking'];
                    @endphp
                    <div class="mt-4 space-y-2 text-sm">
                        <div class="flex justify-between"><span class="text-gray-600">Blokirani slotovi (redovi)</span><span class="font-medium">{{ $b['blocked_slot_rows'] }}</span></div>
                        <div class="flex justify-between"><span class="text-gray-600">Potpuno blokirani dani</span><span class="font-medium">{{ $b['fully_blocked_days'] }}</span></div>
                        <div class="flex justify-between"><span class="text-gray-600">% izgubljenog kapaciteta</span><span class="font-medium">{{ $fmtPct($b['blocked_capacity_pct']) }}</span></div>
                    </div>
                    @if (!empty($b['top_days']))
                        <h3 class="mt-4 text-sm font-semibold text-gray-900">Top dani po blokiranju</h3>
                        <div class="mt-2 overflow-x-auto">
                            <table class="min-w-full text-sm">
                                <thead class="text-xs text-gray-500 uppercase">
                                    <tr class="border-b">
                                        <th class="py-2 pr-4 text-left">Datum</th>
                                        <th class="py-2 pr-4 text-right">Blokirani slotovi</th>
                                        <th class="py-2 pr-4 text-right">Udeo</th>
                                    </tr>
                                </thead>
                                <tbody class="divide-y">
                                    @foreach ($b['top_days'] as $row)
                                        <tr>
                                            <td class="py-2 pr-4">{{ $row['date'] }}</td>
                                            <td class="py-2 pr-4 text-right">{{ $row['blocked_slots'] }}</td>
                                            <td class="py-2 pr-4 text-right">{{ $fmtPct($row['blocked_slots_pct']) }}</td>
                                        </tr>
                                    @endforeach
                                </tbody>
                            </table>
                        </div>
                    @endif
                </section>

                <section class="bg-white shadow rounded-lg p-6 border border-red-100">
                    <h2 class="text-base font-semibold text-gray-900">Operativni problemi / recovery</h2>
                    <p class="text-sm text-gray-600 mt-1">{{ $st['ops'] ?? '' }}</p>
                    @php
                        $o = $dataset['ops'];
                    @endphp
                    <div class="mt-4 overflow-x-auto">
                        <table class="min-w-full text-sm">
                            <tbody class="divide-y">
                                <tr><td class="py-2 pr-4 text-gray-600">Failed payment pokušaji</td><td class="py-2 pr-4 text-right font-medium">{{ $o['failed_payments'] }}</td></tr>
                                <tr><td class="py-2 pr-4 text-gray-600">Expired payment pokušaji</td><td class="py-2 pr-4 text-right font-medium">{{ $o['expired_payments'] }}</td></tr>
                                <tr><td class="py-2 pr-4 text-gray-600">Late success</td><td class="py-2 pr-4 text-right font-medium">{{ $o['late_success'] }}</td></tr>
                                <tr><td class="py-2 pr-4 text-gray-600">Paid bez fiscal JIR</td><td class="py-2 pr-4 text-right font-medium">{{ $o['paid_without_fiscal_jir'] }}</td></tr>
                                <tr><td class="py-2 pr-4 text-gray-600">Unresolved post-fiscal</td><td class="py-2 pr-4 text-right font-medium">{{ $o['unresolved_post_fiscal'] }}</td></tr>
                                <tr><td class="py-2 pr-4 text-gray-600">Resolved post-fiscal (u periodu)</td><td class="py-2 pr-4 text-right font-medium">{{ $o['resolved_post_fiscal'] }}</td></tr>
                                <tr>
                                    <td class="py-2 pr-4 text-gray-600 align-top" title="Rezervacije koje su plaćene, ali se u potpunosti nalaze u besplatnim terminima (najčešće rezultat administrativne izmjene).">
                                        Paid rezervacije u free terminima
                                    </td>
                                    <td class="py-2 pr-4 text-right font-medium align-top">{{ (int)($o['paid_reservations_fully_in_free_zone'] ?? 0) }}</td>
                                </tr>
                                <tr>
                                    <td colspan="2" class="pb-2 text-xs text-gray-500">
                                        Rezervacije koje su plaćene, ali se u potpunosti nalaze u besplatnim terminima (najčešće rezultat administrativne izmjene).
                                    </td>
                                </tr>
                                <tr>
                                    <td class="py-2 pr-4 text-gray-600 align-top" title="Sumnjivi slučajevi gdje su za isti datum i iste tablice plaćene rezervacije sa bar jednim zajedničkim terminom.">
                                        Duplo plaćanje istog termina
                                    </td>
                                    <td class="py-2 pr-4 text-right font-medium align-top">{{ (int)($o['double_paid_same_slot_pairs'] ?? 0) }}</td>
                                </tr>
                                <tr>
                                    <td colspan="2" class="pb-2 text-xs text-gray-500">
                                        Sumnjivi slučajevi gdje su za isti datum i iste tablice plaćene rezervacije sa bar jednim zajedničkim terminom.
                                    </td>
                                </tr>
                            </tbody>
                        </table>
                    </div>
                </section>
            </div>

            @if ((bool) config('features.advance_payments'))
                <section class="bg-white shadow rounded-lg p-6 border border-red-100">
                    <h2 class="text-base font-semibold text-gray-900">Stanje avansa po agencijama</h2>
                    <p class="text-sm text-gray-600 mt-1">{{ $st['advance_balances'] ?? '' }}</p>

                    @php
                        $advTotal = (float) ($dataset['advance_balances_total'] ?? 0);
                        $advRows = (array) ($dataset['advance_balances_by_agency'] ?? []);
                        $fmtSignedMoney = function (float $v): string {
                            $sign = $v > 0.000001 ? '+' : '';
                            return $sign.number_format($v, 2, '.', '').' EUR';
                        };
                    @endphp

                    <div class="mt-4 flex flex-wrap items-baseline justify-between gap-3">
                        <div class="text-sm text-gray-600">Ukupno stanje avansa:</div>
                        <div class="text-lg font-semibold text-gray-900">{{ $fmtMoney($advTotal) }}</div>
                    </div>

                    <div class="mt-4 overflow-x-auto">
                        <table class="min-w-full text-sm">
                            <thead class="text-xs text-gray-500 uppercase">
                                <tr class="border-b">
                                    <th class="py-2 pr-4 text-left">Agencija</th>
                                    <th class="py-2 pr-4 text-left">Email</th>
                                    <th class="py-2 pr-4 text-right">Uplaćeno ukupno</th>
                                    <th class="py-2 pr-4 text-right">Iskorišćeno ukupno</th>
                                    <th class="py-2 pr-4 text-right">Korekcije ukupno</th>
                                    <th class="py-2 pr-4 text-right">Trenutno stanje</th>
                                    <th class="py-2 pr-4 text-left">Poslednja aktivnost</th>
                                    <th class="py-2 pr-4 text-right">Detalji</th>
                                </tr>
                            </thead>
                            <tbody class="divide-y">
                                @forelse ($advRows as $row)
                                    <tr>
                                        <td class="py-2 pr-4 font-medium text-gray-900">{{ $row['agency'] }}</td>
                                        <td class="py-2 pr-4 text-gray-700">{{ $row['email'] }}</td>
                                        <td class="py-2 pr-4 text-right">{{ $fmtMoney((float) ($row['topup_total'] ?? 0)) }}</td>
                                        <td class="py-2 pr-4 text-right">{{ $fmtMoney((float) ($row['usage_total'] ?? 0)) }}</td>
                                        <td class="py-2 pr-4 text-right">{{ $fmtSignedMoney((float) ($row['correction_total'] ?? 0)) }}</td>
                                        <td class="py-2 pr-4 text-right font-semibold">{{ $fmtMoney((float) ($row['balance'] ?? 0)) }}</td>
                                        <td class="py-2 pr-4 text-gray-700 whitespace-nowrap">
                                            @if (!empty($row['last_activity']))
                                                {{ \Carbon\Carbon::parse((string) $row['last_activity'])->format('d.m.Y. H:i') }}
                                            @else
                                                —
                                            @endif
                                        </td>
                                        <td class="py-2 pr-4 text-right whitespace-nowrap">
                                            <a class="text-red-700 underline font-medium" href="{{ route('panel_admin.agencies.show', ['user' => $row['agency_user_id']], false) }}">Detalji</a>
                                        </td>
                                    </tr>
                                @empty
                                    <tr>
                                        <td colspan="8" class="py-3 pr-4 text-sm text-gray-500">Nema agencija sa avansnim transakcijama.</td>
                                    </tr>
                                @endforelse
                            </tbody>
                        </table>
                    </div>
                </section>
            @endif
        @endif
    </div>
@endcomponent

