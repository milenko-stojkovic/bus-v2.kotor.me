@php
    /** @var \App\Models\User $user */
    $user = $user ?? null;
    $advanceEnabled = (bool) ($advanceEnabled ?? false);
    $balance = $balance ?? null;
    $ledger = $ledger ?? collect();
    $topups = $topups ?? collect();
    $pendingVehicleCategoryChangeRequests = $pendingVehicleCategoryChangeRequests ?? collect();

    $typeLabel = fn (string $t) => match ($t) {
        \App\Models\AgencyAdvanceTransaction::TYPE_TOPUP => 'Uplata avansa',
        \App\Models\AgencyAdvanceTransaction::TYPE_USAGE => 'Korišćenje avansa',
        \App\Models\AgencyAdvanceTransaction::TYPE_CORRECTION => 'Korekcija',
        default => $t,
    };

    $fmtSigned = function ($amount): string {
        $n = is_numeric((string) $amount) ? (float) $amount : 0.0;
        $sign = $n > 0.000001 ? '+' : '';
        return $sign.number_format($n, 2, '.', '').' EUR';
    };
@endphp

<x-admin-panel-layout page-title="Agencija" nav-active="agencies">
    <div class="space-y-6">
        <header class="flex flex-wrap items-start justify-between gap-3">
            <div>
                <h1 class="text-2xl font-semibold text-gray-900">Agencija: {{ $user->name }}</h1>
                <div class="text-sm text-gray-600 mt-1">{{ $user->email }} • Registrovana: {{ $user->created_at?->format('d.m.Y.') ?? '—' }}</div>
            </div>
            <a href="{{ route('panel_admin.agencies.index', [], false) }}" class="text-sm text-red-700 underline font-medium">Nazad na listu</a>
        </header>

        @include('admin-panel.agencies.partials.reservation-statistics', [
            'user' => $user,
            'v2ReservationStats' => $v2ReservationStats ?? [],
            'v1HistoricalEstimate' => $v1HistoricalEstimate ?? [],
            'v1Sort' => $v1Sort ?? 'confidence',
        ])

        @if (! $advanceEnabled)
            <div class="rounded-md bg-red-50 border border-red-100 p-4 text-sm text-gray-700">
                Avansna funkcionalnost trenutno nije aktivna.
            </div>
        @else
            @if ($pendingVehicleCategoryChangeRequests->isNotEmpty())
                <section class="bg-white shadow rounded-lg p-4 sm:p-6 space-y-3">
                    <h2 class="text-lg font-semibold text-gray-900">Zahtjevi za promjenu kategorije vozila</h2>
                    <div class="overflow-x-auto">
                        <table class="min-w-full text-left text-sm">
                            <thead>
                                <tr class="border-b border-red-100 text-gray-600">
                                    <th class="py-2 pr-4">Datum</th>
                                    <th class="py-2 pr-4">Tablica</th>
                                    <th class="py-2 pr-4">Stara kategorija</th>
                                    <th class="py-2 pr-4">Tražena kategorija</th>
                                    <th class="py-2 pr-4">Prilozi</th>
                                    <th class="py-2 pr-4">Status</th>
                                    <th class="py-2 pr-4 text-right"></th>
                                </tr>
                            </thead>
                            <tbody>
                                @foreach ($pendingVehicleCategoryChangeRequests as $req)
                                    <tr class="border-b border-red-100">
                                        <td class="py-2 pr-4 whitespace-nowrap">{{ $req->created_at?->format('d.m.Y. H:i') ?? '—' }}</td>
                                        <td class="py-2 pr-4 font-medium whitespace-nowrap">{{ $req->license_plate }}</td>
                                        <td class="py-2 pr-4">{{ $req->oldVehicleType?->formatLabel('cg', 'EUR') ?? ('#'.$req->old_vehicle_type_id) }}</td>
                                        <td class="py-2 pr-4">{{ $req->requestedVehicleType?->formatLabel('cg', 'EUR') ?? ('#'.$req->requested_vehicle_type_id) }}</td>
                                        <td class="py-2 pr-4 whitespace-nowrap">
                                            @include('admin-panel.agencies.partials.category-change-attachments', ['user' => $user, 'req' => $req])
                                        </td>
                                        <td class="py-2 pr-4 whitespace-nowrap">{{ $req->status }}</td>
                                        <td class="py-2 pr-0 whitespace-nowrap text-right">
                                            <div class="flex gap-2 justify-end">
                                                <form method="POST" action="{{ route('panel_admin.agencies.vehicle_category_change_requests.approve', ['user' => $user->id, 'request' => $req->id], false) }}">
                                                    @csrf
                                                    <button type="submit"
                                                            class="inline-flex items-center rounded-md bg-red-700 px-3 py-2 text-xs font-semibold uppercase tracking-widest text-white hover:bg-red-600"
                                                            onclick="return confirm('Prihvatiti zahtjev i reaktivirati vozilo?');">
                                                        Prihvati
                                                    </button>
                                                </form>
                                                <form method="POST" action="{{ route('panel_admin.agencies.vehicle_category_change_requests.reject', ['user' => $user->id, 'request' => $req->id], false) }}" class="inline-flex flex-col items-end gap-1">
                                                    @csrf
                                                    <input type="text" name="reason" required maxlength="2000" minlength="3"
                                                           placeholder="Razlog odbijanja"
                                                           class="rounded border-gray-300 text-xs w-48"
                                                           value="{{ old('reason') }}">
                                                    <button type="submit"
                                                            class="inline-flex items-center rounded-md bg-red-700 px-3 py-2 text-xs font-semibold uppercase tracking-widest text-white hover:bg-red-600"
                                                            onclick="return confirm('Odbiti zahtjev?');">
                                                        Odbij
                                                    </button>
                                                </form>
                                            </div>
                                        </td>
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                    </div>
                </section>
            @endif

            <section class="bg-white shadow rounded-lg p-4 sm:p-6 space-y-3">
                <h2 class="text-lg font-semibold text-gray-900">Avans</h2>
                <div class="text-3xl font-semibold text-gray-900">{{ number_format((float) $balance, 2, '.', '') }} EUR</div>
                @if (session('status'))
                    <div class="rounded-md bg-red-50 p-3 text-sm text-red-900">{{ session('status') }}</div>
                @endif
                @if (session('message'))
                    <div class="rounded-md bg-red-50 p-3 text-sm text-red-900">{{ session('message') }}</div>
                @endif
                @if (session('error'))
                    <div class="rounded-md bg-red-50 p-3 text-sm text-red-800">{{ session('error') }}</div>
                @endif
                @if ($errors->any())
                    <div class="rounded-md bg-red-50 p-3 text-sm text-red-800 space-y-1">
                        @foreach ($errors->all() as $err)
                            <div>{{ $err }}</div>
                        @endforeach
                    </div>
                @endif

                <details class="rounded-md border border-red-100 bg-red-50 p-4">
                    <summary class="cursor-pointer text-sm font-semibold text-gray-900">Dodaj korekciju</summary>
                    <form method="POST" action="{{ route('panel_admin.agencies.advance.correction.store', $user, false) }}" class="mt-4 space-y-3">
                        @csrf
                        <div class="grid grid-cols-1 sm:grid-cols-3 gap-3 items-end">
                            <div>
                                <label class="block text-sm font-medium text-gray-700" for="corr_amount">Iznos korekcije</label>
                                <input id="corr_amount" name="amount" type="number" step="0.01" min="0.01" max="99999.99"
                                       value="{{ old('amount') }}"
                                       class="mt-1 block w-full rounded-md border-red-200 shadow-sm focus:border-red-500 focus:ring-red-500"
                                       required>
                            </div>
                            <div>
                                <label class="block text-sm font-medium text-gray-700" for="corr_dir">Tip korekcije</label>
                                <select id="corr_dir" name="direction"
                                        class="mt-1 block w-full rounded-md border-red-200 shadow-sm focus:border-red-500 focus:ring-red-500"
                                        required>
                                    <option value="increase" @selected(old('direction', 'increase') === 'increase')>Povećaj saldo</option>
                                    <option value="decrease" @selected(old('direction') === 'decrease')>Smanji saldo</option>
                                </select>
                            </div>
                            <div class="sm:justify-end flex">
                                <button type="submit"
                                        class="inline-flex items-center justify-center rounded-md bg-red-700 px-3 py-2 text-xs font-semibold uppercase tracking-widest text-white hover:bg-red-800">
                                    Evidentiraj
                                </button>
                            </div>
                        </div>
                        <div>
                            <label class="block text-sm font-medium text-gray-700" for="corr_reason">Razlog korekcije</label>
                            <textarea id="corr_reason" name="reason" rows="3"
                                      class="mt-1 block w-full rounded-md border-red-200 shadow-sm focus:border-red-500 focus:ring-red-500"
                                      required>{{ old('reason') }}</textarea>
                            <p class="mt-1 text-xs text-gray-500">Min 5, max 1000 karaktera.</p>
                        </div>
                    </form>
                </details>
            </section>

            <section class="bg-white shadow rounded-lg p-4 sm:p-6 space-y-3">
                <h2 class="text-lg font-semibold text-gray-900">Ledger istorija</h2>
                <div class="overflow-x-auto">
                    <table class="min-w-full text-left text-sm">
                        <thead>
                            <tr class="border-b border-red-100 text-gray-600">
                                <th class="py-2 pr-4">Datum</th>
                                <th class="py-2 pr-4">Tip</th>
                                <th class="py-2 pr-4">Iznos</th>
                                <th class="py-2 pr-4">Napomena</th>
                                <th class="py-2 pr-4">Referenca</th>
                            </tr>
                        </thead>
                        <tbody>
                            @forelse ($ledger as $tx)
                                <tr class="border-b border-red-100">
                                    <td class="py-2 pr-4 whitespace-nowrap">{{ $tx->created_at?->format('d.m.Y. H:i') ?? '—' }}</td>
                                    <td class="py-2 pr-4">{{ $typeLabel((string) $tx->type) }}</td>
                                    <td class="py-2 pr-4 font-medium whitespace-nowrap">{{ $fmtSigned($tx->amount) }}</td>
                                    <td class="py-2 pr-4 text-gray-700">{{ $tx->note ?? '—' }}</td>
                                    <td class="py-2 pr-4 text-gray-700">
                                        @if (($tx->reference_type ?? null) === 'reservation' && $tx->reference_id)
                                            <a class="text-red-700 underline font-medium" href="{{ route('panel_admin.reservations.show', ['reservation' => $tx->reference_id], false) }}">
                                                reservation#{{ $tx->reference_id }}
                                            </a>
                                        @elseif (($tx->reference_type ?? null) === 'advance_topup' && $tx->reference_id)
                                            topup#{{ $tx->reference_id }}
                                        @elseif ($tx->merchant_transaction_id)
                                            MTID: {{ $tx->merchant_transaction_id }}
                                        @else
                                            —
                                        @endif
                                    </td>
                                </tr>
                            @empty
                                <tr><td class="py-4 text-gray-600" colspan="5">Nema ledger transakcija.</td></tr>
                            @endforelse
                        </tbody>
                    </table>
                </div>
                <div class="text-xs text-gray-500">Prikazano poslednjih 50 redova.</div>
            </section>

            <section class="bg-white shadow rounded-lg p-4 sm:p-6 space-y-3">
                <h2 class="text-lg font-semibold text-gray-900">Topup istorija</h2>
                <div class="overflow-x-auto">
                    <table class="min-w-full text-left text-sm">
                        <thead>
                            <tr class="border-b border-red-100 text-gray-600">
                                <th class="py-2 pr-4">Datum</th>
                                <th class="py-2 pr-4">Iznos</th>
                                <th class="py-2 pr-4">Status</th>
                                <th class="py-2 pr-4">Potvrda</th>
                                <th class="py-2 pr-4">Merchant tx</th>
                            </tr>
                        </thead>
                        <tbody>
                            @forelse ($topups as $t)
                                <tr class="border-b border-red-100">
                                    <td class="py-2 pr-4 whitespace-nowrap">{{ $t->created_at?->format('d.m.Y. H:i') ?? '—' }}</td>
                                    <td class="py-2 pr-4 font-medium whitespace-nowrap">{{ number_format((float) $t->amount, 2, '.', '') }} EUR</td>
                                    <td class="py-2 pr-4 whitespace-nowrap">{{ $t->status }}</td>
                                    <td class="py-2 pr-4 whitespace-nowrap">
                                        @if ($t->confirmation_sent_at)
                                            {{ $t->confirmation_sent_at->format('d.m.Y. H:i') }}
                                        @elseif ($t->status === \App\Models\AgencyAdvanceTopup::STATUS_PAID)
                                            <form method="POST" action="{{ route('panel_admin.agencies.advance.topups.confirmation.resend', ['user' => $user->id, 'topup' => $t->id], false) }}">
                                                @csrf
                                                <button type="submit"
                                                        class="inline-flex items-center rounded-md bg-red-700 px-3 py-2 text-xs font-semibold uppercase tracking-widest text-white hover:bg-red-600">
                                                    Pošalji potvrdu ponovo
                                                </button>
                                            </form>
                                        @else
                                            —
                                        @endif
                                    </td>
                                    <td class="py-2 pr-4 text-gray-700 whitespace-nowrap">{{ $t->merchant_transaction_id }}</td>
                                </tr>
                            @empty
                                <tr><td class="py-4 text-gray-600" colspan="5">Nema topup pokušaja.</td></tr>
                            @endforelse
                        </tbody>
                    </table>
                </div>
                <div class="text-xs text-gray-500">Prikazano poslednjih 50 redova.</div>
            </section>
        @endif
    </div>
</x-admin-panel-layout>

