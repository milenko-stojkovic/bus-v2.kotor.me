@php
    $statusLabel = fn (string $s) => match ($s) {
        \App\Models\AdminAlert::STATUS_UNREAD => 'Nepročitano',
        \App\Models\AdminAlert::STATUS_IN_PROGRESS => 'U obradi',
        \App\Models\AdminAlert::STATUS_DONE => 'Završeno',
        default => $s,
    };
    $fmtDate = fn (string $d) => \Carbon\Carbon::parse($d)->format('d.m.Y.');
@endphp

@push('head')
    <meta http-equiv="refresh" content="300">
@endpush

<x-admin-panel-layout page-title="Upozorenja / Informacije" nav-active="dashboard">
    <div class="space-y-10">
        @if (session('status'))
            <div class="rounded-md bg-red-50 p-4 text-sm text-red-900">{{ session('status') }}</div>
        @endif
        @if (session('error'))
            <div class="rounded-md bg-red-50 p-4 text-sm text-red-800">{{ session('error') }}</div>
        @endif

        <section>
            <h2 class="text-lg font-semibold text-gray-900 mb-4">Upozorenja</h2>
            @if ($alerts->isEmpty())
                <p class="text-gray-600 text-sm">Nema aktivnih upozorenja.</p>
            @else
                <ul class="space-y-4">
                    @foreach ($alerts as $alert)
                        <li class="bg-white shadow rounded-lg p-5 border border-red-100">
                            <div class="flex flex-wrap items-start justify-between gap-3">
                                <div class="min-w-0 flex-1">
                                    <h3 class="font-medium text-gray-900">{{ $alert->title }}</h3>
                                    <p class="mt-2 text-sm text-gray-700 whitespace-pre-wrap">{{ $alert->message }}</p>
                                    <dl class="mt-3 text-xs text-gray-500 space-y-1">
                                        <div><span class="font-medium text-gray-600">Status:</span> {{ $statusLabel($alert->status) }}</div>
                                        <div><span class="font-medium text-gray-600">Kreirano:</span> {{ $alert->created_at?->timezone(config('app.timezone'))->format('d.m.Y. H:i') }}</div>
                                    </dl>
                                </div>
                                <div class="flex flex-col sm:flex-row gap-2 shrink-0">
                                    <button type="button"
                                        class="inline-flex justify-center items-center px-3 py-2 border border-red-200 rounded-md text-xs font-semibold text-gray-700 uppercase tracking-widest hover:bg-red-50"
                                        data-b64="{{ base64_encode($alert->copyDetailsText()) }}"
                                        onclick="(function(btn){var t=atob(btn.dataset.b64);navigator.clipboard.writeText(t).then(function(){btn.replaceWith(Object.assign(document.createElement('span'),{className:'text-xs text-red-800',textContent:'Kopirano'}));}).catch(function(){alert('Kopiranje nije uspelo');});})(this)">
                                        Copy details
                                    </button>
                                </div>
                            </div>
                        </li>
                    @endforeach
                </ul>
            @endif
        </section>

        <section>
            <h2 class="text-lg font-semibold text-gray-900 mb-2">Nedostupni dani i termini</h2>
            <p class="text-sm text-gray-600 mb-4 max-w-3xl">
                Termini koji se trenutno <strong class="font-medium">ne mogu kupiti</strong> po backend pravilima (nema reda u
                <code class="text-xs bg-red-50 px-1 rounded">daily_parking_data</code>, blokada, ili nema slobodnog kapaciteta uzimajući u obzir i pending).
                Lista prati postojeće datume u tabeli (od danas nadalje). Blokirani termini su uključeni i ovde i u sekciji ispod.
            </p>
            @if (empty($unavailableDays))
                <p class="text-sm text-gray-600">Nema nedostupnih termina za prikazane datume.</p>
            @else
                <ul class="space-y-3">
                    @foreach ($unavailableDays as $day)
                        <li class="bg-white shadow rounded-lg p-4 border border-red-100">
                            <div class="font-medium text-gray-900">
                                {{ $fmtDate($day['date']) }}
                                @if ($day['is_full_day'])
                                    <span class="text-gray-600">— nedostupan</span>
                                @endif
                            </div>
                            @if (! $day['is_full_day'])
                                <div class="mt-1 text-sm text-gray-700">
                                    {{ implode(', ', $day['ranges']) }}
                                </div>
                            @endif
                        </li>
                    @endforeach
                </ul>
            @endif
        </section>

        <section>
            <h2 class="text-lg font-semibold text-gray-900 mb-2">Blokirani dani i termini</h2>
            <p class="text-sm text-gray-600 mb-4 max-w-3xl">
                Redovi u <code class="text-xs bg-red-50 px-1 rounded">daily_parking_data</code> sa <code class="text-xs bg-red-50 px-1 rounded">is_blocked = 1</code>.
                Blokada sprečava novu prodaju bez menjanja kapaciteta, rezervisanih i pending vrednosti.
            </p>
            @if (empty($blockedDays))
                <p class="text-sm text-gray-600">Nema blokiranih termina.</p>
            @else
                <ul class="space-y-3">
                    @foreach ($blockedDays as $day)
                        <li class="bg-white shadow rounded-lg p-4 flex flex-wrap items-start justify-between gap-3 border border-red-100">
                            <div class="min-w-0">
                                <div class="font-medium text-gray-900">
                                    {{ $fmtDate($day['date']) }}
                                    @if ($day['is_full_day'])
                                        <span class="text-gray-600">— blokiran</span>
                                    @endif
                                </div>
                                @if (! $day['is_full_day'])
                                    <div class="mt-1 text-sm text-gray-700">
                                        {{ implode(', ', $day['ranges']) }}
                                    </div>
                                @endif
                            </div>
                            <a href="{{ route('panel_admin.blocking.day', ['date' => $day['date']], false) }}"
                               class="inline-flex items-center px-3 py-2 border border-red-200 rounded-md text-xs font-semibold text-gray-700 uppercase tracking-widest hover:bg-red-50 shrink-0">
                                Deblokiraj
                            </a>
                        </li>
                    @endforeach
                </ul>
            @endif
        </section>

        {{-- Operativni pregled kapaciteta po slotovima (danas/sjutra) --}}
        @php
            $charts = $capacityCharts ?? null;
        @endphp
        @if (is_array($charts) && isset($charts['today'], $charts['tomorrow']))
            <section class="space-y-6">
                @include('partials.daily-capacity-chart', [
                    'dataset' => $charts['today'],
                    'title' => 'Kapacitet po terminima — danas',
                    'chartId' => 'capacity-chart-admin-today',
                    'scriptId' => 'capacity-chart-admin-today-data',
                ])

                @include('partials.daily-capacity-chart', [
                    'dataset' => $charts['tomorrow'],
                    'title' => 'Kapacitet po terminima — sjutra',
                    'chartId' => 'capacity-chart-admin-tomorrow',
                    'scriptId' => 'capacity-chart-admin-tomorrow-data',
                ])
            </section>
        @endif
    </div>
</x-admin-panel-layout>
