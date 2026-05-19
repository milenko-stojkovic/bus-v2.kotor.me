@php
    $c = (array)($criteria ?? []);
    $r = (array)($results ?? []);
    $rows = (array)($r['results'] ?? []);
    $adminFree = $r['admin_free_reservation'] ?? null;
    $countries = (array)($countries ?? []);
    $statuses = (array)($statuses ?? []);
    $resolutionReasons = (array)($resolutionReasons ?? []);
    $rq = request()->getQueryString();
@endphp

<x-admin-panel-layout :page-title="$pageTitle ?? 'Uvid'" nav-active="insight">
    <div class="space-y-6">
        <div>
            <h1 class="text-lg font-semibold text-gray-900">Uvid</h1>
            <p class="text-sm text-gray-600 mt-1">Read-only uvid u payment pokušaje (primarni izvor: temp_data). Osnovna jedinica je merchant_transaction_id.</p>
        </div>

        @if ($errors->any())
            <div class="rounded-md bg-red-50 p-4 text-sm text-red-800 space-y-1">
                @foreach ($errors->all() as $err)
                    <div>{{ $err }}</div>
                @endforeach
            </div>
        @endif

        <form method="get" action="{{ route('panel_admin.insight', [], false) }}" class="bg-white shadow rounded-lg p-5 border border-red-100 space-y-4">
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
                    <x-input-label for="user_name" value="Ime" />
                    <p class="text-xs text-gray-500 mt-1">Preporuka: unesi samo ime ili deo imena (npr. „Milenko“). Ako uneseš puno ime i prezime i nema rezultata, probaj kraći unos.</p>
                    <input id="user_name" name="user_name" value="{{ $c['user_name'] ?? '' }}"
                           class="mt-1 block w-full rounded-md border-red-200 shadow-sm" />
                </div>
                <div class="md:col-span-2">
                    <x-input-label for="email" value="Email" />
                    <input id="email" name="email" value="{{ $c['email'] ?? '' }}"
                           class="mt-1 block w-full rounded-md border-red-200 shadow-sm" />
                </div>
                <div>
                    <x-input-label for="license_plate" value="Tablica" />
                    <input id="license_plate" name="license_plate" value="{{ $c['license_plate'] ?? '' }}"
                           autocomplete="off"
                           inputmode="latin"
                           pattern="[A-Z0-9]+"
                           oninput="this.value=this.value.toUpperCase().replace(/[^A-Z0-9]+/g,'')"
                           class="mt-1 block w-full rounded-md border-red-200 shadow-sm uppercase" />
                </div>
                <div>
                    <x-input-label for="country" value="Država" />
                    <select name="country" id="country" class="mt-1 block w-full rounded-md border-red-200 shadow-sm">
                        <option value="">—</option>
                        @foreach ($countries as $code => $labels)
                            @php $lab = is_array($labels) ? ($labels['cg'] ?? $code) : $labels; @endphp
                            <option value="{{ $code }}" @selected(($c['country'] ?? '') === $code)>{{ $lab }} ({{ $code }})</option>
                        @endforeach
                    </select>
                </div>
                <div>
                    <x-input-label for="status" value="Status (temp_data)" />
                    <select name="status" id="status" class="mt-1 block w-full rounded-md border-red-200 shadow-sm">
                        <option value="">—</option>
                        @foreach ($statuses as $value => $label)
                            <option value="{{ $value }}" @selected(($c['status'] ?? '') === (string)$value)>{{ $label }}</option>
                        @endforeach
                    </select>
                </div>
                <div class="md:col-span-2">
                    <x-input-label for="resolution_reason" value="Resolution reason" />
                    <select name="resolution_reason" id="resolution_reason" class="mt-1 block w-full rounded-md border-red-200 shadow-sm">
                        <option value="">—</option>
                        @foreach ($resolutionReasons as $reason)
                            <option value="{{ $reason }}" @selected(($c['resolution_reason'] ?? '') === (string)$reason)>{{ $reason }}</option>
                        @endforeach
                    </select>
                </div>
                <div class="flex gap-2 justify-end md:col-span-4">
                    <a href="{{ route('panel_admin.insight', [], false) }}"
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
            @if ($adminFree)
                <div class="rounded-md bg-red-50 p-4 text-sm text-red-900 border border-red-100">
                    <div class="font-medium">Admin-free rezervacija</div>
                    <div class="mt-1">{{ $adminFree['note'] ?? '' }}</div>
                    <div class="mt-2">
                        <a class="underline" href="{{ route('panel_admin.insight.show', ['merchantTransactionId' => $adminFree['merchant_transaction_id'], 'rq' => $rq], false) }}">Detalji</a>
                    </div>
                </div>
            @endif

            <section class="bg-white shadow rounded-lg p-6 border border-red-100">
                <h2 class="text-base font-semibold text-gray-900">Rezultati</h2>
                <p class="text-sm text-gray-600 mt-1">Svaki red je jedan payment case po merchant_transaction_id (temp_data).</p>

                <div class="mt-4 overflow-x-auto">
                    <table class="min-w-full text-sm">
                        <thead class="text-xs text-gray-500 uppercase">
                        <tr class="border-b">
                            <th class="py-2 pr-4 text-left">MTID</th>
                            <th class="py-2 pr-4 text-left">Created</th>
                            <th class="py-2 pr-4 text-left">Status</th>
                            <th class="py-2 pr-4 text-left">Ime</th>
                            <th class="py-2 pr-4 text-left">Email</th>
                            <th class="py-2 pr-4 text-left">Rez. datum</th>
                            <th class="py-2 pr-4 text-left">Termini</th>
                            <th class="py-2 pr-4 text-left">Rez.</th>
                            <th class="py-2 pr-4 text-right"></th>
                        </tr>
                        </thead>
                        <tbody class="divide-y">
                        @forelse ($rows as $row)
                            <tr>
                                <td class="py-2 pr-4 font-mono text-xs">{{ $row['merchant_transaction_id'] }}</td>
                                <td class="py-2 pr-4 text-xs text-gray-700">{{ !empty($row['created_at']) ? \Carbon\Carbon::parse($row['created_at'])->format('d.m.Y.') : '' }}</td>
                                <td class="py-2 pr-4">
                                    <div class="font-medium">{{ $row['status'] }}</div>
                                    @if (!empty($row['resolution_reason']))
                                        <div class="text-xs text-gray-500">{{ $row['resolution_reason'] }}</div>
                                    @endif
                                </td>
                                <td class="py-2 pr-4">{{ $row['user_name'] }}</td>
                                <td class="py-2 pr-4">{{ $row['email'] }}</td>
                                <td class="py-2 pr-4 text-xs">{{ !empty($row['reservation_date']) ? \Carbon\Carbon::parse($row['reservation_date'])->format('d.m.Y.') : '' }}</td>
                                <td class="py-2 pr-4 text-xs">
                                    <div>Drop: {{ $row['drop_off'] }}</div>
                                    <div>Pick: {{ $row['pick_up'] }}</div>
                                </td>
                                <td class="py-2 pr-4 text-xs">
                                    @if (!empty($row['reservation_exists']))
                                        <span class="text-red-800">DA</span>
                                        @if (!empty($row['reservation_is_admin_free']))
                                            <div class="text-red-700 text-xs">admin-free</div>
                                        @endif
                                    @else
                                        <span class="text-gray-500">NE</span>
                                    @endif
                                </td>
                                <td class="py-2 pr-4 text-right">
                                    <a href="{{ route('panel_admin.insight.show', ['merchantTransactionId' => $row['merchant_transaction_id'], 'rq' => $rq], false) }}"
                                       class="inline-flex items-center px-3 py-1.5 border border-red-200 rounded-md font-semibold text-xs text-gray-700 uppercase tracking-widest hover:bg-red-50">
                                        Detalji
                                    </a>
                                </td>
                            </tr>
                        @empty
                            <tr><td colspan="9" class="py-6 text-center text-gray-600">Nema rezultata.</td></tr>
                        @endforelse
                        </tbody>
                    </table>
                </div>
            </section>
        @endif
    </div>
</x-admin-panel-layout>

