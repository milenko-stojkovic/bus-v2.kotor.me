<x-control-layout :page-title="$pageTitle ?? 'Kontrola dnevne naknade'">
    <header class="mb-8 flex flex-col gap-4 sm:flex-row sm:flex-wrap sm:items-center sm:justify-between">
        <h1 class="text-2xl font-semibold text-gray-900">Kontrola dnevne naknade</h1>
        <div class="flex flex-wrap items-center gap-4 text-sm">
            <form method="POST" action="{{ route('control.logout', [], false) }}" class="inline">
                @csrf
                <button type="submit" class="font-medium text-red-700 underline">Odjava</button>
            </form>
        </div>
    </header>

    <section class="mb-8 rounded-lg border border-red-100 bg-white p-4 shadow sm:p-6">
        <p class="mb-4 text-sm text-gray-600">
            Provjera plaćene dnevne naknade za današnji datum (Europe/Podgorica). Unesite registarsku tablicu — samo čitanje, bez izmjena podataka.
        </p>

        <form method="POST" action="{{ route('control.daily_fee.check', [], false) }}" class="space-y-4 max-w-md">
            @csrf
            <div>
                <label for="license_plate" class="block text-sm font-medium text-gray-700">Registarska tablica</label>
                <input
                    type="text"
                    name="license_plate"
                    id="license_plate"
                    value="{{ old('license_plate', $submittedPlate ?? '') }}"
                    autocomplete="off"
                    autocapitalize="characters"
                    class="mt-1 block w-full rounded-md border-gray-300 shadow-sm focus:border-red-500 focus:ring-red-500 uppercase"
                    placeholder="npr. PG123AB"
                    required
                />
                @error('license_plate')
                    <p class="mt-1 text-sm text-red-600">{{ $message }}</p>
                @enderror
            </div>
            <button type="submit" class="inline-flex justify-center rounded-md bg-red-700 px-4 py-2 text-sm font-medium text-white hover:bg-red-800">
                Provjeri
            </button>
        </form>
    </section>

    @if ($result !== null)
        <section class="rounded-lg border border-red-100 bg-white p-4 shadow sm:p-6">
            @if ($result['found'])
                <div class="mb-4 rounded-md border border-green-200 bg-green-50 p-4">
                    <p class="text-sm font-semibold text-green-900">Status</p>
                    <p class="mt-1 text-lg font-bold text-green-800">Plaćena dnevna naknada: DA</p>
                </div>

                <p class="mb-4 text-sm text-gray-600">
                    Datum važenja: <span class="font-medium text-gray-900">{{ $result['validity_date_display'] }}</span>
                    · Tablica: <span class="font-medium text-gray-900">{{ $result['normalized_plate'] }}</span>
                </p>

                <div class="space-y-4">
                    @foreach ($result['matches'] as $match)
                        <div class="rounded-md border border-gray-200 p-4 text-sm text-gray-800">
                            <dl class="grid grid-cols-1 gap-2 sm:grid-cols-2">
                                <div>
                                    <dt class="text-gray-500">Registarska tablica</dt>
                                    <dd class="font-medium">{{ $match['license_plate'] }}</dd>
                                </div>
                                <div>
                                    <dt class="text-gray-500">Agencija / korisnik</dt>
                                    <dd class="font-medium">{{ $match['user_name'] }}</dd>
                                </div>
                                <div>
                                    <dt class="text-gray-500">Datum važenja</dt>
                                    <dd class="font-medium">{{ $match['reservation_date'] }}</dd>
                                </div>
                                <div>
                                    <dt class="text-gray-500">Tip vozila</dt>
                                    <dd class="font-medium">{{ $match['vehicle_type_label'] }}</dd>
                                </div>
                                <div>
                                    <dt class="text-gray-500">Email agencije</dt>
                                    <dd class="font-medium break-all">{{ $match['email'] }}</dd>
                                </div>
                                <div>
                                    <dt class="text-gray-500">Vrijeme kreiranja / plaćanja</dt>
                                    <dd class="font-medium">{{ $match['created_at'] }}</dd>
                                </div>
                            </dl>
                        </div>
                    @endforeach
                </div>
            @else
                <div class="rounded-md border border-red-200 bg-red-50 p-4">
                    <p class="text-sm font-semibold text-red-900">Status</p>
                    <p class="mt-1 text-lg font-bold text-red-800">Plaćena dnevna naknada: NE</p>
                    <p class="mt-2 text-sm text-red-900">
                        Tablica: <span class="font-medium">{{ $result['normalized_plate'] }}</span>
                        · Datum: <span class="font-medium">{{ $result['validity_date_display'] }}</span>
                    </p>
                </div>
            @endif
        </section>
    @endif

    @php
        $todayList = $todayList ?? null;
        $todayRows = is_array($todayList) ? ($todayList['rows'] ?? []) : [];
    @endphp
    <section id="control-daily-fee-today-list" class="mt-10 rounded-lg border border-red-100 bg-white p-4 shadow sm:p-6">
        <h2 class="text-lg font-semibold text-gray-900">Vozila sa plaćenom dnevnom naknadom za danas</h2>
        <p class="mt-1 text-sm text-gray-600">
            Prikazana su putnička vozila 4+1–7+1 i minibus 8+1.
            @if (! empty($todayList['validity_date_display'] ?? null))
                Datum važenja: <span class="font-medium text-gray-900">{{ $todayList['validity_date_display'] }}</span>
            @endif
        </p>

        @if ($todayRows === [])
            <p class="mt-4 text-sm text-gray-600">Nema vozila sa plaćenom dnevnom naknadom za danas.</p>
        @else
            <div class="mt-4 overflow-x-auto">
                <table class="min-w-full text-left text-sm">
                    <thead>
                        <tr class="border-b border-red-100 text-gray-600">
                            <th class="py-2 pr-4">Registarska tablica</th>
                            <th class="py-2 pr-4">Agencija / korisnik</th>
                            <th class="py-2 pr-4">Tip vozila</th>
                            <th class="py-2 pr-4">Vrijeme kupovine / kreiranja</th>
                            <th class="py-2 pr-4">Datum važenja</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($todayRows as $row)
                            <tr class="border-b border-red-100">
                                <td class="py-2 pr-4 font-medium">{{ $row['license_plate'] }}</td>
                                <td class="py-2 pr-4">{{ $row['user_name'] }}</td>
                                <td class="py-2 pr-4">{{ $row['vehicle_type_label'] }}</td>
                                <td class="py-2 pr-4 whitespace-nowrap">{{ $row['created_at'] }}</td>
                                <td class="py-2 pr-4 whitespace-nowrap">{{ $row['reservation_date'] }}</td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        @endif
    </section>
</x-control-layout>
