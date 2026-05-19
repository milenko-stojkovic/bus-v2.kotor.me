@php
    $fmtMoney = fn ($v) => number_format((float) $v, 2, ',', '').' EUR';
    $fmtWhen = fn ($dt) => $dt->timezone('Europe/Podgorica')->format('d.m.Y H:i');
    $labelSource = static fn (?string $s) => match ($s) {
        'qr' => 'QR',
        'plate' => 'Tablica',
        default => $s ?? '—',
    };
    $labelStatus = static fn (?string $s) => match ($s) {
        'pending_fiscal' => 'U obradi',
        'fiscalized' => 'Fiskalizovano',
        'fiscal_failed' => 'Greška fiskalizacije',
        default => $s ?? '—',
    };
    $labelIncidentType = static fn (?string $s) => match ($s) {
        \App\Models\LimoIncident::TYPE_QR_INSUFFICIENT_FUNDS => 'Nedovoljan avans (QR)',
        \App\Models\LimoIncident::TYPE_PLATE_INSUFFICIENT_FUNDS => 'Nedovoljan avans (tablica)',
        \App\Models\LimoIncident::TYPE_UNREGISTERED_VEHICLE_WITH_BRANDING => 'Neregistrovano vozilo sa brendingom',
        \App\Models\LimoIncident::TYPE_INVALID_QR_TOKEN => 'Nevažeći QR token',
        \App\Models\LimoIncident::TYPE_DRIVER_NON_COOPERATIVE => 'Vozač ne saradjuje',
        default => $s ?? '—',
    };
    $listType = $listType ?? 'pickup';
@endphp

@component('layouts.admin-panel', ['pageTitle' => $pageTitle ?? 'Limo događaji', 'navActive' => 'limo'])
    <div class="space-y-6">
        <div>
            <h1 class="text-lg font-semibold text-gray-900">Limo događaji</h1>
            <p class="text-sm text-gray-600 mt-1">Samo pregled (bez akcija). Filtar po datumu važi za vrijeme događaja (<code class="text-xs bg-red-50 px-1 rounded">occurred_at</code>), zona Europe/Podgorica.</p>
        </div>

        @if ($errors->any())
            <div class="rounded-md bg-red-50 p-4 text-sm text-red-800 space-y-1">
                @foreach ($errors->all() as $err)
                    <div>{{ $err }}</div>
                @endforeach
            </div>
        @endif

        <form method="get" action="{{ route('admin.limo.index', [], false) }}" class="bg-white shadow rounded-lg p-5 border border-red-100 space-y-4">
            <fieldset class="space-y-2">
                <legend class="text-sm font-medium text-gray-700">Vrsta pregleda</legend>
                <div class="flex flex-wrap gap-4 text-sm">
                    <label class="inline-flex items-center gap-2 cursor-pointer">
                        <input type="radio" name="type" value="pickup" @checked($listType === 'pickup') class="rounded-full border-red-200 text-red-600 shadow-sm" />
                        <span>Limo pickup</span>
                    </label>
                    <label class="inline-flex items-center gap-2 cursor-pointer">
                        <input type="radio" name="type" value="incident" @checked($listType === 'incident') class="rounded-full border-red-200 text-red-600 shadow-sm" />
                        <span>Limo incident</span>
                    </label>
                </div>
            </fieldset>
            <div class="grid grid-cols-1 md:grid-cols-3 gap-4 items-end">
                <div>
                    <x-input-label for="date_from" value="Datum od" />
                    <input type="date" id="date_from" name="date_from"
                           value="{{ old('date_from', $dateFrom) }}"
                           class="mt-1 block w-full rounded-md border-red-200 shadow-sm" />
                </div>
                <div>
                    <x-input-label for="date_to" value="Datum do" />
                    <input type="date" id="date_to" name="date_to"
                           value="{{ old('date_to', $dateTo) }}"
                           class="mt-1 block w-full rounded-md border-red-200 shadow-sm" />
                </div>
                <div class="flex gap-2 justify-start md:justify-end">
                    <button type="submit"
                        class="inline-flex items-center px-4 py-2 bg-red-700 border border-transparent rounded-md font-semibold text-xs text-white uppercase tracking-widest hover:bg-red-800">
                        Filtriraj
                    </button>
                </div>
            </div>
        </form>

        @if ($listType === 'pickup')
            <div class="bg-white shadow rounded-lg border border-red-100 overflow-x-auto">
                <table class="min-w-full divide-y divide-gray-200 text-sm">
                    <thead class="bg-red-50">
                        <tr>
                            <th scope="col" class="px-4 py-3 text-left font-semibold text-gray-700">Datum i vrijeme</th>
                            <th scope="col" class="px-4 py-3 text-left font-semibold text-gray-700">Agencija</th>
                            <th scope="col" class="px-4 py-3 text-left font-semibold text-gray-700">Tablica</th>
                            <th scope="col" class="px-4 py-3 text-left font-semibold text-gray-700">Iznos</th>
                            <th scope="col" class="px-4 py-3 text-left font-semibold text-gray-700">Izvor</th>
                            <th scope="col" class="px-4 py-3 text-left font-semibold text-gray-700">Status</th>
                            <th scope="col" class="px-4 py-3 text-left font-semibold text-gray-700">JIR</th>
                            <th scope="col" class="px-4 py-3 text-left font-semibold text-gray-700">Slika</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-gray-100">
                        @forelse ($events as $event)
                            <tr class="hover:bg-red-50">
                                <td class="px-4 py-3 whitespace-nowrap text-gray-900">{{ $fmtWhen($event->occurred_at) }}</td>
                                <td class="px-4 py-3 text-gray-900">{{ $event->agency_name_snapshot }}</td>
                                <td class="px-4 py-3 whitespace-nowrap text-gray-900">{{ $event->license_plate_snapshot }}</td>
                                <td class="px-4 py-3 whitespace-nowrap text-gray-900">{{ $fmtMoney($event->amount_snapshot) }}</td>
                                <td class="px-4 py-3 whitespace-nowrap text-gray-900">{{ $labelSource($event->source) }}</td>
                                <td class="px-4 py-3 whitespace-nowrap text-gray-900">{{ $labelStatus($event->status) }}</td>
                                <td class="px-4 py-3 whitespace-nowrap text-gray-600">{{ $event->fiscal_jir ?? '—' }}</td>
                                <td class="px-4 py-3 whitespace-nowrap text-gray-700">
                                    @if ($event->source === 'plate')
                                        <a href="{{ route('admin.limo.pickups.plate-photo-preview', $event, false) }}"
                                           target="_blank"
                                           rel="noopener noreferrer"
                                           class="text-red-600 hover:text-red-800 underline text-xs"
                                           title="Otvara sliku tablice (ako je arhiva na MEGA-u, učitavanje može potrajati)">Slika tablice</a>
                                    @else
                                        <span class="text-gray-400 text-xs">—</span>
                                    @endif
                                </td>
                            </tr>
                        @empty
                            <tr>
                                <td colspan="8" class="px-4 py-8 text-center text-gray-500">Nema događaja u izabranom periodu.</td>
                            </tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        @else
            <div class="bg-white shadow rounded-lg border border-red-100 overflow-x-auto">
                <table class="min-w-full divide-y divide-gray-200 text-sm">
                    <thead class="bg-red-50">
                        <tr>
                            <th scope="col" class="px-4 py-3 text-left font-semibold text-gray-700">occurred_at</th>
                            <th scope="col" class="px-4 py-3 text-left font-semibold text-gray-700">created_at</th>
                            <th scope="col" class="px-4 py-3 text-left font-semibold text-gray-700">type</th>
                            <th scope="col" class="px-4 py-3 text-left font-semibold text-gray-700">license_plate_snapshot</th>
                            <th scope="col" class="px-4 py-3 text-left font-semibold text-gray-700">visible_agency_name</th>
                            <th scope="col" class="px-4 py-3 text-left font-semibold text-gray-700">agency_name_snapshot</th>
                            <th scope="col" class="px-4 py-3 text-left font-semibold text-gray-700">note</th>
                            <th scope="col" class="px-4 py-3 text-left font-semibold text-gray-700">recorded_by_limo_admin_id</th>
                            <th scope="col" class="px-4 py-3 text-left font-semibold text-gray-700">gps_lat / gps_lng</th>
                            <th scope="col" class="px-4 py-3 text-left font-semibold text-gray-700">Slike</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-gray-100">
                        @forelse ($incidents as $incident)
                            <tr class="hover:bg-red-50">
                                <td class="px-4 py-3 whitespace-nowrap text-gray-900">{{ $fmtWhen($incident->occurred_at) }}</td>
                                <td class="px-4 py-3 whitespace-nowrap text-gray-600">{{ $fmtWhen($incident->created_at) }}</td>
                                <td class="px-4 py-3 text-gray-900" title="{{ $incident->type }}">{{ $labelIncidentType($incident->type) }}</td>
                                <td class="px-4 py-3 whitespace-nowrap text-gray-900">{{ $incident->license_plate_snapshot ?? '—' }}</td>
                                <td class="px-4 py-3 text-gray-900">{{ $incident->visible_agency_name ?? '—' }}</td>
                                <td class="px-4 py-3 text-gray-900">{{ $incident->agency_name_snapshot ?? '—' }}</td>
                                <td class="px-4 py-3 text-gray-700 max-w-xs truncate" title="{{ $incident->note }}">{{ $incident->note ? \Illuminate\Support\Str::limit($incident->note, 80) : '—' }}</td>
                                <td class="px-4 py-3 whitespace-nowrap text-gray-700">
                                    @if ($incident->recordedBy)
                                        {{ $incident->recordedBy->username }} <span class="text-gray-400">(#{{ $incident->recorded_by_limo_admin_id }})</span>
                                    @else
                                        #{{ $incident->recorded_by_limo_admin_id }}
                                    @endif
                                </td>
                                <td class="px-4 py-3 whitespace-nowrap text-gray-600">
                                    @if ($incident->gps_lat !== null && $incident->gps_lng !== null)
                                        {{ $incident->gps_lat }}, {{ $incident->gps_lng }}
                                    @else
                                        —
                                    @endif
                                </td>
                                <td class="px-4 py-3 whitespace-nowrap text-gray-700 space-x-2">
                                    @if (filled($incident->plate_photo_path))
                                        <a href="{{ route('admin.limo.incidents.plate-photo-preview', $incident, false) }}"
                                           target="_blank"
                                           rel="noopener noreferrer"
                                           class="text-red-600 hover:text-red-800 underline text-xs"
                                           title="Otvara sliku tablice">Slika tablice</a>
                                    @else
                                        <span class="text-gray-400 text-xs">—</span>
                                    @endif
                                    @if (filled($incident->branding_photo_path))
                                        <a href="{{ route('admin.limo.incidents.branding-photo-preview', $incident, false) }}"
                                           target="_blank"
                                           rel="noopener noreferrer"
                                           class="text-red-600 hover:text-red-800 underline text-xs"
                                           title="Otvara sliku brendinga">Slika brendinga</a>
                                    @endif
                                </td>
                            </tr>
                        @empty
                            <tr>
                                <td colspan="10" class="px-4 py-8 text-center text-gray-500">Nema incidenta u izabranom periodu.</td>
                            </tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        @endif
    </div>
@endcomponent
