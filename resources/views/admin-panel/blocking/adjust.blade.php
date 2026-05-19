@php
    $fmt = fn ($d) => $d ? \Carbon\Carbon::parse($d)->format('d.m.Y.') : '—';
@endphp

<x-admin-panel-layout page-title="Prilagodi rezervaciju" nav-active="blocking">
    <div class="space-y-6">
        @if (session('status'))
            <div class="rounded-md bg-red-50 p-4 text-sm text-red-900">{{ session('status') }}</div>
        @endif
        @if (session('error'))
            <div class="rounded-md bg-red-50 p-4 text-sm text-red-800">{{ session('error') }}</div>
        @endif

        <div class="flex items-center justify-between flex-wrap gap-3">
            <div>
                <h1 class="text-lg font-semibold text-gray-900">Prilagodi rezervaciju</h1>
                <p class="text-sm text-gray-600">MTID: {{ $row->merchant_transaction_id }}</p>
            </div>
            <a href="{{ route('panel_admin.blocking', [], false) }}" class="text-sm text-red-700 hover:underline">Nazad</a>
        </div>

        <div class="bg-white shadow rounded-lg p-5 space-y-2">
            <div class="text-sm text-gray-700">Stari datum: <span class="font-medium">{{ $fmt($row->old_date->toDateString()) }}</span></div>
            <div class="text-sm text-gray-700">
                Stari drop-off: <span class="{{ $row->affected_drop_off ? 'font-semibold text-red-800' : 'font-medium' }}">#{{ $row->old_drop_off }}</span>
                · Stari pick-up: <span class="{{ $row->affected_pick_up ? 'font-semibold text-red-800' : 'font-medium' }}">#{{ $row->old_pick_up }}</span>
            </div>
            @if (! $reservation)
                <div class="mt-2 text-sm text-red-700">Rezervacija nije pronađena (možda je obrisana ili još nije kreirana).</div>
            @else
                <div class="mt-2 text-sm text-gray-700">Rezervacija ID: {{ $reservation->id }} · status: {{ $reservation->status }}</div>
            @endif
        </div>

        <p class="text-sm text-red-800 bg-red-50 border border-red-200 rounded-md p-3">
            Kalendar ispod je samo <strong>prefilter</strong> (teorijski dani sa najmanje dva raspoloživa termina). Konačna provjera novih termina radi se pri „Primeni prilagođavanje“, posle zaključavanja redova u bazi.
        </p>

        <form method="POST" action="{{ route('panel_admin.blocking.worklist.adjust.apply', $row, false) }}" class="bg-white shadow rounded-lg p-5 space-y-4">
            @csrf

            <div class="grid grid-cols-1 md:grid-cols-3 gap-3">
                <div>
                    <x-input-label for="new_date" value="Novi datum" />
                    @php
                        $defaultDate = old('new_date', $row->old_date->toDateString());
                        if ($prefilterDates !== [] && ! in_array($defaultDate, $prefilterDates, true)) {
                            $defaultDate = $prefilterDates[0];
                        }
                    @endphp
                    @if (count($prefilterDates) > 0)
                        <select id="new_date" name="new_date" class="mt-1 block w-full border-red-200 rounded-md shadow-sm" required>
                            @foreach ($prefilterDates as $d)
                                <option value="{{ $d }}" {{ $d === $defaultDate ? 'selected' : '' }}>
                                    {{ \Carbon\Carbon::parse($d)->format('d.m.Y.') }}
                                </option>
                            @endforeach
                        </select>
                    @else
                        <x-text-input id="new_date" type="date" name="new_date" class="mt-1 w-full" :value="$defaultDate" min="{{ now()->toDateString() }}" required />
                        <p class="mt-1 text-xs text-gray-500">Nema predloženih dana iz prefilta (npr. nema daily_parking_data). Ručno izaberite budući datum.</p>
                    @endif
                </div>
                <div>
                    <x-input-label for="new_drop_off" value="Novi drop-off termin" />
                    <select id="new_drop_off" name="new_drop_off" class="mt-1 block w-full border-red-200 rounded-md shadow-sm">
                        @foreach ($slots as $slot)
                            <option value="{{ $slot->id }}" {{ (int) old('new_drop_off', $row->old_drop_off) === (int) $slot->id ? 'selected' : '' }}>
                                {{ $slot->time_slot }} (#{{ $slot->id }})
                            </option>
                        @endforeach
                    </select>
                </div>
                <div>
                    <x-input-label for="new_pick_up" value="Novi pick-up termin" />
                    <select id="new_pick_up" name="new_pick_up" class="mt-1 block w-full border-red-200 rounded-md shadow-sm">
                        @foreach ($slots as $slot)
                            <option value="{{ $slot->id }}" {{ (int) old('new_pick_up', $row->old_pick_up) === (int) $slot->id ? 'selected' : '' }}>
                                {{ $slot->time_slot }} (#{{ $slot->id }})
                            </option>
                        @endforeach
                    </select>
                </div>
            </div>

            <div class="flex justify-end gap-3">
                <x-danger-button type="submit">Primeni prilagođavanje</x-danger-button>
            </div>
        </form>
    </div>
</x-admin-panel-layout>

