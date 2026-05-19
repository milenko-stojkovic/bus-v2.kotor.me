<x-admin-panel-layout page-title="Deblokiranje" nav-active="blocking">
    <div class="space-y-6">
        @if (session('status'))
            <div class="rounded-md bg-red-50 p-4 text-sm text-red-900">{{ session('status') }}</div>
        @endif
        @if (session('error'))
            <div class="rounded-md bg-red-50 p-4 text-sm text-red-800">{{ session('error') }}</div>
        @endif

        <div class="flex items-center justify-between flex-wrap gap-3">
            <div>
                <h1 class="text-lg font-semibold text-gray-900">Deblokiranje</h1>
                <p class="text-sm text-gray-600">Datum: {{ \Carbon\Carbon::parse($date)->format('d.m.Y.') }}</p>
                <p class="text-sm text-gray-600 mt-2 max-w-3xl">
                    Označite <strong>samo termine koji su blokirani</strong>. Termini koji nisu blokirani nisu izbor ovde — za blokadu koristite <strong>Blokiraj</strong> na glavnoj stranici modula.
                </p>
            </div>
            <a href="{{ route('panel_admin.blocking', [], false) }}" class="text-sm text-red-700 hover:underline">Nazad</a>
        </div>

        <form method="POST" action="{{ route('panel_admin.blocking.unblock.apply', [], false) }}" class="bg-white shadow rounded-lg p-5 space-y-4">
            @csrf
            <input type="hidden" name="date" value="{{ $date }}">

            <div class="flex items-center gap-2">
                <input id="unblock_all" type="checkbox" name="unblock_all" value="1" class="rounded border-red-200 text-red-600 shadow-sm focus:ring-red-500" />
                <label for="unblock_all" class="text-sm text-gray-700">Deblokiraj sve (ceo dan)</label>
            </div>

            <div class="grid grid-cols-1 md:grid-cols-2 gap-2">
                @foreach ($slots as $slot)
                    @php
                        $daily = $dailyBySlotId->get($slot->id);
                        $blocked = (bool) ($daily?->is_blocked ?? false);
                        $hasRow = $daily !== null;
                    @endphp
                    @if ($blocked)
                        <label class="flex items-center gap-2 p-2 rounded border border-red-200 bg-red-50 cursor-pointer hover:border-red-300">
                            <input type="checkbox" name="slot_ids[]" value="{{ $slot->id }}" class="rounded border-red-200 text-red-600 shadow-sm focus:ring-red-500" checked>
                            <span class="text-sm text-gray-900">{{ $slot->time_slot }}</span>
                            <span class="text-xs font-medium text-red-800 ms-auto">Blokiran — označite za deblokadu</span>
                        </label>
                    @elseif (! $hasRow)
                        <div class="flex items-center gap-2 p-2 rounded border border-red-100 bg-red-50 opacity-80">
                            <input type="checkbox" disabled class="rounded border-red-200 opacity-50 cursor-not-allowed" aria-hidden="true">
                            <span class="text-sm text-gray-700">{{ $slot->time_slot }}</span>
                            <span class="text-xs text-gray-500 ms-auto">Nema podataka za dan</span>
                        </div>
                    @else
                        <div class="flex items-center gap-2 p-2 rounded border border-red-100 bg-red-50 opacity-90">
                            <input type="checkbox" disabled class="rounded border-red-200 opacity-50 cursor-not-allowed" aria-hidden="true">
                            <span class="text-sm text-gray-900">{{ $slot->time_slot }}</span>
                            <span class="text-xs text-gray-500 ms-auto">Nije blokiran</span>
                        </div>
                    @endif
                @endforeach
            </div>

            <div class="flex justify-end">
                <x-primary-button type="submit">Primeni</x-primary-button>
            </div>
        </form>
    </div>
</x-admin-panel-layout>

