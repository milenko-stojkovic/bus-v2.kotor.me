@php
    $c = (array)($criteria ?? []);
    $r = (array)($results ?? []);
    $rows = (array)($r['results'] ?? []);
    $statuses = (array)($statuses ?? []);
    $rq = request()->getQueryString();
@endphp

<x-admin-panel-layout :page-title="$pageTitle ?? 'Uvid — avans'" nav-active="insight">
    <div class="space-y-6">
        @include('admin-panel.insight._tabs', ['insightTab' => $insightTab ?? 'advance'])

        <div>
            <h1 class="text-lg font-semibold text-gray-900">Uvid — avansna uplata</h1>
            <p class="text-sm text-gray-600 mt-1">Read-only uvid u pokušaje dopune avansa karticom (primarni izvor: agency_advance_topups). Osnovna jedinica je merchant_transaction_id.</p>
        </div>

        @if ($errors->any())
            <div class="rounded-md bg-red-50 p-4 text-sm text-red-800 space-y-1">
                @foreach ($errors->all() as $err)
                    <div>{{ $err }}</div>
                @endforeach
            </div>
        @endif

        <form method="get" action="{{ route('panel_admin.insight.advance', [], false) }}" class="bg-white shadow rounded-lg p-5 border border-red-100 space-y-4">
            <input type="hidden" name="search" value="1" />
            <div class="grid grid-cols-1 md:grid-cols-4 gap-4 items-end">
                <div class="md:col-span-2">
                    <x-input-label for="merchant_transaction_id" value="Merchant transaction ID" />
                    <input id="merchant_transaction_id" name="merchant_transaction_id" value="{{ $c['merchant_transaction_id'] ?? '' }}"
                           class="mt-1 block w-full rounded-md border-red-200 shadow-sm" />
                </div>
                <div>
                    <x-input-label for="date_from" value="Datum od (created_at)" />
                    <input type="date" id="date_from" name="date_from" value="{{ $c['date_from'] ?? '' }}"
                           class="mt-1 block w-full rounded-md border-red-200 shadow-sm" />
                </div>
                <div>
                    <x-input-label for="date_to" value="Datum do (created_at)" />
                    <input type="date" id="date_to" name="date_to" value="{{ $c['date_to'] ?? '' }}"
                           class="mt-1 block w-full rounded-md border-red-200 shadow-sm" />
                </div>
                <div class="md:col-span-2">
                    <x-input-label for="agency_q" value="Agencija (ime ili email)" />
                    <input id="agency_q" name="agency_q" value="{{ $c['agency_q'] ?? '' }}"
                           class="mt-1 block w-full rounded-md border-red-200 shadow-sm" />
                </div>
                <div>
                    <x-input-label for="status" value="Status (topup)" />
                    <select name="status" id="status" class="mt-1 block w-full rounded-md border-red-200 shadow-sm">
                        <option value="">—</option>
                        @foreach ($statuses as $value => $label)
                            <option value="{{ $value }}" @selected(($c['status'] ?? '') === (string)$value)>{{ $label }}</option>
                        @endforeach
                    </select>
                </div>
                <div class="flex gap-2 justify-end md:col-span-4">
                    <a href="{{ route('panel_admin.insight.advance', [], false) }}"
                       class="inline-flex items-center px-4 py-2 border border-red-200 rounded-md font-semibold text-xs text-gray-700 uppercase tracking-widest hover:bg-red-50">
                        Reset
                    </a>
                    <button type="submit"
                            class="inline-flex items-center px-4 py-2 bg-red-700 border border-transparent rounded-md font-semibold text-xs text-white uppercase tracking-widest hover:bg-red-800">
                        Pretraži
                    </button>
                </div>
            </div>
        </form>

        @if ($results !== null)
            <section class="bg-white shadow rounded-lg p-6 border border-red-100">
                <h2 class="text-base font-semibold text-gray-900">Rezultati</h2>
                <p class="text-sm text-gray-600 mt-1">Svaki red je jedan pokušaj avansne uplate po merchant_transaction_id.</p>

                <div class="mt-4 overflow-x-auto">
                    <table class="min-w-full text-sm">
                        <thead class="text-xs text-gray-500 uppercase">
                        <tr class="border-b">
                            <th class="py-2 pr-4 text-left">MTID</th>
                            <th class="py-2 pr-4 text-left">Created</th>
                            <th class="py-2 pr-4 text-left">Status</th>
                            <th class="py-2 pr-4 text-left">Iznos</th>
                            <th class="py-2 pr-4 text-left">Agencija</th>
                            <th class="py-2 pr-4 text-left">Ledger</th>
                            <th class="py-2 pr-4 text-left">Potvrda</th>
                            <th class="py-2 pr-4 text-right"></th>
                        </tr>
                        </thead>
                        <tbody class="divide-y">
                        @forelse ($rows as $row)
                            <tr>
                                <td class="py-2 pr-4 font-mono text-xs">{{ $row['merchant_transaction_id'] }}</td>
                                <td class="py-2 pr-4 text-xs text-gray-700">{{ !empty($row['created_at']) ? \Carbon\Carbon::parse($row['created_at'])->format('d.m.Y. H:i') : '' }}</td>
                                <td class="py-2 pr-4 font-medium">{{ $row['status'] }}</td>
                                <td class="py-2 pr-4 whitespace-nowrap">{{ number_format((float) ($row['amount'] ?? 0), 2, '.', '') }} EUR</td>
                                <td class="py-2 pr-4">
                                    <div>{{ $row['agency_name'] }}</div>
                                    <div class="text-xs text-gray-500">{{ $row['agency_email'] }}</div>
                                </td>
                                <td class="py-2 pr-4 text-xs">
                                    @if (!empty($row['ledger_exists']))
                                        <span class="text-red-800">DA</span>
                                    @else
                                        <span class="text-gray-500">NE</span>
                                    @endif
                                </td>
                                <td class="py-2 pr-4 text-xs whitespace-nowrap">
                                    @if (!empty($row['confirmation_sent_at']))
                                        {{ \Carbon\Carbon::parse($row['confirmation_sent_at'])->format('d.m.Y. H:i') }}
                                    @else
                                        —
                                    @endif
                                </td>
                                <td class="py-2 pr-4 text-right">
                                    <a href="{{ route('panel_admin.insight.advance.show', ['merchantTransactionId' => $row['merchant_transaction_id'], 'rq' => $rq], false) }}"
                                       class="inline-flex items-center px-3 py-1.5 border border-red-200 rounded-md font-semibold text-xs text-gray-700 uppercase tracking-widest hover:bg-red-50">
                                        Detalji
                                    </a>
                                </td>
                            </tr>
                        @empty
                            <tr><td colspan="8" class="py-6 text-center text-gray-600">Nema rezultata.</td></tr>
                        @endforelse
                        </tbody>
                    </table>
                </div>
            </section>
        @endif
    </div>
</x-admin-panel-layout>
