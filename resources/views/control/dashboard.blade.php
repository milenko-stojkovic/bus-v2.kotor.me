<x-control-layout>
    <header class="flex flex-col gap-4 sm:flex-row sm:flex-wrap sm:items-center sm:justify-between mb-8">
        <h1 class="text-2xl font-semibold text-gray-900">Kontrola</h1>
        <div class="flex flex-col gap-2 sm:flex-row sm:items-center sm:gap-4 text-sm">
            <span class="text-gray-600">
                Posljednje osvježavanje:
                <time id="last-refresh-time" datetime=""></time>
            </span>
            <button type="button" id="btn-refresh-now" class="inline-flex justify-center rounded-md bg-red-700 px-4 py-2 font-medium text-white hover:bg-red-800">
                Osvježi sada
            </button>
            <form method="POST" action="{{ route('control.logout', [], false) }}" class="inline">
                @csrf
                <button type="submit" class="text-red-700 underline font-medium">Odjava</button>
            </form>
        </div>
    </header>

    <section class="mb-10 bg-white shadow rounded-lg p-4 sm:p-6">
        <h2 class="text-lg font-semibold text-gray-900 mb-4">Dolasci po terminima</h2>
        <p class="text-sm text-gray-600 mb-4">Prikaz od 1 sat prije početka termina do njegovog kraja. Za starije termine koristite pretragu.</p>

        @if(empty($arrivalGroups))
            <p class="text-gray-600">Nema termina u prozoru prikaza.</p>
        @else
            <div class="space-y-8">
                @foreach($arrivalGroups as $group)
                    <div>
                        <h3 class="text-base font-medium text-gray-800 mb-2 border-b border-red-100 pb-1">{{ $group['label'] }}</h3>
                        <div class="overflow-x-auto">
                            <table class="min-w-full text-left text-sm">
                                <thead>
                                    <tr class="border-b border-red-100 text-gray-600">
                                        <th class="py-2 pr-4">Registarske oznake</th>
                                        <th class="py-2 pr-4">Tip vozila</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    @foreach($group['reservations'] as $reservation)
                                        <tr class="border-b border-red-100">
                                            <td class="py-2 pr-4 font-medium">{{ $reservation->license_plate }}</td>
                                            <td class="py-2 pr-4">{{ $reservation->vehicleType->formatLabel('cg', 'EUR') }}</td>
                                        </tr>
                                    @endforeach
                                </tbody>
                            </table>
                        </div>
                    </div>
                @endforeach
            </div>
        @endif
    </section>

    @php
        $charts = $capacityCharts ?? null;
    @endphp
    @if (is_array($charts) && isset($charts['today'], $charts['tomorrow']))
        <div class="mb-10 space-y-6">
            @include('partials.daily-capacity-chart', [
                'dataset' => $charts['today'],
                'title' => 'Kapacitet po terminima — danas',
                'chartId' => 'capacity-chart-control-today',
                'scriptId' => 'capacity-chart-control-today-data',
            ])

            @include('partials.daily-capacity-chart', [
                'dataset' => $charts['tomorrow'],
                'title' => 'Kapacitet po terminima — sjutra',
                'chartId' => 'capacity-chart-control-tomorrow',
                'scriptId' => 'capacity-chart-control-tomorrow-data',
            ])
        </div>
    @endif

    <section class="bg-white shadow rounded-lg p-4 sm:p-6">
        <h2 class="text-lg font-semibold text-gray-900 mb-4">Pretraga rezervacija</h2>

        <form method="GET" action="{{ route('control.dashboard', [], false) }}" class="grid grid-cols-1 gap-4 sm:grid-cols-2 lg:grid-cols-3">
            <div>
                <x-input-label for="c_date" value="Datum" />
                <x-text-input id="c_date" class="block mt-1 w-full" type="date" name="date" :value="old('date', $searchInput['date'] ?? '')" />
            </div>
            <div>
                <x-input-label for="c_name" value="Ime" />
                <x-text-input id="c_name" class="block mt-1 w-full" type="text" name="name" :value="old('name', $searchInput['name'] ?? '')" />
            </div>
            <div>
                <x-input-label for="c_email" value="Email" />
                <x-text-input id="c_email" class="block mt-1 w-full" type="text" name="email" autocomplete="off" :value="old('email', $searchInput['email'] ?? '')" />
            </div>
            <div>
                <x-input-label for="c_vehicle_type" value="Tip vozila" />
                <select id="c_vehicle_type" name="vehicle_type_id" class="mt-1 block w-full rounded-md border-red-200 shadow-sm focus:border-red-500 focus:ring-red-500">
                    <option value="">— Bilo koji —</option>
                    @foreach($vehicleTypes as $vt)
                        <option value="{{ $vt->id }}" @selected((string) old('vehicle_type_id', $searchInput['vehicle_type_id'] ?? '') === (string) $vt->id)>
                            {{ $vt->formatLabel('cg', 'EUR') }}
                        </option>
                    @endforeach
                </select>
            </div>
            <div>
                <x-input-label for="c_plate" value="Registarske oznake" />
                <x-text-input id="c_plate" class="block mt-1 w-full" type="text" name="license_plate" :value="old('license_plate', $searchInput['license_plate'] ?? '')" />
            </div>
            <div>
                <x-input-label for="c_status" value="Status" />
                <select id="c_status" name="status" class="mt-1 block w-full rounded-md border-red-200 shadow-sm focus:border-red-500 focus:ring-red-500">
                    <option value="" @selected((string) old('status', $searchInput['status'] ?? '') === '')>— Bilo koji —</option>
                    <option value="paid" @selected((string) old('status', $searchInput['status'] ?? '') === 'paid')>Plaćena</option>
                    <option value="free" @selected((string) old('status', $searchInput['status'] ?? '') === 'free')>Besplatna</option>
                </select>
            </div>
            <div class="flex items-end">
                <x-primary-button type="submit" name="search" value="1" class="w-full sm:w-auto justify-center">
                    Pretraži
                </x-primary-button>
            </div>
        </form>

        <x-input-error :messages="$errors->get('search')" class="mt-4" />

        @if($searchResults !== null)
            <div class="mt-6 overflow-x-auto">
                @if($searchResults->isEmpty())
                    <p class="text-gray-600">Nema rezultata.</p>
                @else
                    <table class="min-w-full text-left text-sm">
                        <thead>
                            <tr class="border-b border-red-100 text-gray-600">
                                <th class="py-2 pr-4">Datum</th>
                                <th class="py-2 pr-4">Dolazak</th>
                                <th class="py-2 pr-4">Odlazak</th>
                                <th class="py-2 pr-4">Ime</th>
                                <th class="py-2 pr-4">Email</th>
                                <th class="py-2 pr-4">Registarske oznake</th>
                                <th class="py-2 pr-4">Tip vozila</th>
                                <th class="py-2 pr-4">Status</th>
                            </tr>
                        </thead>
                        <tbody>
                            @foreach($searchResults as $r)
                                <tr class="border-b border-red-100">
                                    <td class="py-2 pr-4 whitespace-nowrap">{{ $r->reservation_date->format('Y-m-d') }}</td>
                                    <td class="py-2 pr-4">{{ $r->dropOffTimeSlot?->time_slot ?? '—' }}</td>
                                    <td class="py-2 pr-4">{{ $r->pickUpTimeSlot?->time_slot ?? '—' }}</td>
                                    <td class="py-2 pr-4">{{ $r->user_name }}</td>
                                    <td class="py-2 pr-4">{{ $r->email }}</td>
                                    <td class="py-2 pr-4 font-medium">{{ $r->license_plate }}</td>
                                    <td class="py-2 pr-4">{{ $r->vehicleType->formatLabel('cg', 'EUR') }}</td>
                                    <td class="py-2 pr-4">{{ $r->status === 'free' ? 'Besplatna' : 'Plaćena' }}</td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                @endif
            </div>
        @endif
    </section>

    <script>
        (function () {
            var el = document.getElementById('last-refresh-time');
            function stamp() {
                if (!el) return;
                var d = new Date();
                el.dateTime = d.toISOString();
                el.textContent = d.toLocaleString('sr-Latn-ME');
            }
            stamp();
            var btn = document.getElementById('btn-refresh-now');
            if (btn) btn.addEventListener('click', function () { window.location.reload(); });
            window.setInterval(function () { window.location.reload(); }, 5 * 60 * 1000);
        })();
    </script>
</x-control-layout>
