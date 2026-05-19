@php
    $p = fn (string $key, ?string $fallback = null) => \App\Support\UiText::t('panel', $key, $fallback);
    $a = fn (string $key, ?string $fallback = null) => \App\Support\UiText::t('advance', $key, $fallback);

    /** @var string $balance */
    $balance = $balance ?? '0.00';
    /** @var \Illuminate\Support\Collection<int,\App\Models\AgencyAdvanceTransaction> $ledger */
    $ledger = $ledger ?? collect();

    $typeLabel = fn (string $t) => match ($t) {
        \App\Models\AgencyAdvanceTransaction::TYPE_TOPUP => $a('type_topup', 'Uplata avansa'),
        \App\Models\AgencyAdvanceTransaction::TYPE_USAGE => $a('type_usage', 'Korišćenje avansa'),
        \App\Models\AgencyAdvanceTransaction::TYPE_CORRECTION => $a('type_correction', 'Korekcija'),
        default => $t,
    };

    $fmtAmount = function ($s): string {
        $n = is_numeric((string) $s) ? (float) $s : 0.0;
        return number_format($n, 2, '.', '').' EUR';
    };
@endphp

<x-app-layout>
    <x-slot name="header">
        <h2 class="font-semibold text-xl text-gray-800 leading-tight">{{ $p('nav_advance', 'Avans') }}</h2>
    </x-slot>

    <div class="py-6">
        <div class="max-w-7xl mx-auto sm:px-6 lg:px-8 space-y-6">
            @if (session('status'))
                <div class="rounded-md bg-red-50 p-3 text-sm text-red-900">{{ session('status') }}</div>
            @endif

            @if ($errors->any())
                <div class="rounded-md bg-red-50 p-4 text-sm text-red-800 space-y-1">
                    @foreach ($errors->all() as $err)
                        <div>{{ $err }}</div>
                    @endforeach
                </div>
            @endif

            <div class="bg-white overflow-hidden shadow-sm sm:rounded-lg">
                <div class="p-6 space-y-4">
                    <h3 class="text-lg font-medium text-gray-900">{{ $a('balance_title', 'Trenutno stanje avansa') }}</h3>
                    <div class="text-3xl font-semibold text-gray-900">{{ $fmtAmount($balance) }}</div>
                    <p class="text-sm text-gray-600">
                        {{ $a('blurb', 'Avansna sredstva se koriste za buduće rezervacije u okviru Bus Kotor servisa. Uplaćena sredstva se ne vraćaju i mogu se koristiti isključivo za buduće usluge.') }}
                    </p>
                </div>
            </div>

            <div class="bg-white overflow-hidden shadow-sm sm:rounded-lg">
                <div class="p-6 space-y-4">
                    <h3 class="text-lg font-medium text-gray-900">{{ $a('topup_title', 'Avansna uplata') }}</h3>

                    <form method="POST" action="{{ route('panel.advance.topup.store', [], false) }}" class="grid grid-cols-1 sm:grid-cols-3 gap-3 items-end">
                        @csrf
                        <div class="sm:col-span-2">
                            <x-input-label for="advance_amount" :value="$a('topup_amount_label', 'Iznos avansne uplate')" />
                            <input
                                id="advance_amount"
                                name="amount"
                                type="text"
                                inputmode="numeric"
                                placeholder="100"
                                value="{{ old('amount') }}"
                                class="mt-1 block w-full rounded-md border-red-200 shadow-sm focus:border-red-500 focus:ring-red-500"
                                required
                            />
                            <p class="mt-1 text-xs text-gray-500">{{ $a('topup_amount_hint', 'Dozvoljeni su samo cijeli iznosi eura koji se završavaju na 0 ili 5 (npr. 15, 100, 105).') }}</p>
                            <x-input-error class="mt-2" :messages="$errors->get('amount')" />
                        </div>
                        <div class="sm:col-span-1">
                            <x-primary-button class="w-full justify-center" type="submit">
                                {{ $a('topup_submit', 'Pokreni avansnu uplatu') }}
                            </x-primary-button>
                        </div>
                    </form>
                </div>
            </div>

            <div class="bg-white overflow-hidden shadow-sm sm:rounded-lg">
                <div class="p-6 space-y-4">
                    <h3 class="text-lg font-medium text-gray-900">{{ $a('ledger_title', 'Istorija avansa') }}</h3>

                    @if ($ledger->isEmpty())
                        <p class="text-sm text-gray-600">{{ $a('ledger_empty', 'Nema transakcija.') }}</p>
                    @else
                        <div class="overflow-x-auto">
                            <table class="min-w-full text-left text-sm">
                                <thead>
                                    <tr class="border-b border-red-100 text-gray-600">
                                        <th class="py-2 pr-4">{{ $a('ledger_col_date', 'Datum') }}</th>
                                        <th class="py-2 pr-4">{{ $a('ledger_col_type', 'Tip') }}</th>
                                        <th class="py-2 pr-4">{{ $a('ledger_col_amount', 'Iznos') }}</th>
                                        <th class="py-2 pr-4">{{ $a('ledger_col_note', 'Napomena / referenca') }}</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    @foreach ($ledger as $tx)
                                        <tr class="border-b border-red-100">
                                            <td class="py-2 pr-4 whitespace-nowrap">{{ $tx->created_at?->format('d.m.Y. H:i') ?? '—' }}</td>
                                            <td class="py-2 pr-4">{{ $typeLabel((string) $tx->type) }}</td>
                                            <td class="py-2 pr-4 font-medium">{{ $fmtAmount($tx->amount) }}</td>
                                            <td class="py-2 pr-4 text-gray-700">
                                                {{ $tx->note ?: ($tx->reference_type ? ($tx->reference_type.($tx->reference_id ? '#'.$tx->reference_id : '')) : '—') }}
                                            </td>
                                        </tr>
                                    @endforeach
                                </tbody>
                            </table>
                        </div>
                    @endif
                </div>
            </div>
        </div>
    </div>
</x-app-layout>

