@php
    /** @var \App\Models\Reservation $reservation */
    $cancelUrl = $cancelUrl ?? route('panel_admin.reservations', [], false).($returnQuery !== '' ? '?'.$returnQuery : '');
@endphp

<x-admin-panel-layout :page-title="$pageTitle ?? 'Dnevna karta'" nav-active="reservations">
    <div class="space-y-6">
        @if (session('status'))
            <div class="rounded-md bg-red-50 p-4 text-sm text-red-900">{{ session('status') }}</div>
        @endif
        @if (session('error'))
            <div class="rounded-md bg-red-50 p-4 text-sm text-red-800">{{ session('error') }}</div>
        @endif

        <div class="flex flex-wrap items-center justify-between gap-3">
            <div>
                <h1 class="text-lg font-semibold text-gray-900">Rezervacija #{{ $reservation->id }}</h1>
                <p class="text-sm text-gray-600 mt-1">
                    Vrsta: <span class="font-medium text-red-800">Dnevna karta</span>
                    · Status: <span class="font-medium">{{ $reservation->status }}</span>
                    @if ($reservation->merchant_transaction_id)
                        · MTID: {{ $reservation->merchant_transaction_id }}
                    @endif
                </p>
            </div>
            <a href="{{ $cancelUrl }}" class="text-sm text-red-700 hover:underline">Otkaži</a>
        </div>

        <p class="text-sm text-red-800 bg-red-50 border border-red-200 rounded-md p-3">
            Dnevna karta nema termine dolaska/odlaska. Vrsta rezervacije se ne može mijenjati.
        </p>

        <form method="post" action="{{ route('panel_admin.reservations.update', $reservation, false) }}"
            class="bg-white shadow rounded-lg p-6 space-y-5 border border-red-100">
            @csrf
            @method('PUT')
            <input type="hidden" name="return_query" value="{{ $returnQuery }}" />

            <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                <div class="md:col-span-2">
                    <x-input-label for="reservation_date" value="Datum važenja" />
                    <input type="date" name="reservation_date" id="reservation_date" required
                        min="{{ $dateMin }}" max="{{ $dateMax }}"
                        value="{{ old('reservation_date', $reservation->reservation_date->toDateString()) }}"
                        class="mt-1 block w-full rounded-md border-red-200 shadow-sm focus:border-red-500 focus:ring-red-500" />
                    <x-input-error class="mt-2" :messages="$errors->get('reservation_date')" />
                </div>

                <div class="md:col-span-2">
                    <x-input-label for="user_name" value="Ime / agencija" />
                    <x-text-input class="mt-1 block w-full" type="text" name="user_name" id="user_name" required
                        :value="old('user_name', $reservation->user_name)" />
                    <x-input-error class="mt-2" :messages="$errors->get('user_name')" />
                </div>

                <div>
                    <x-input-label for="country" value="Država" />
                    <select name="country" id="country" class="mt-1 block w-full rounded-md border-red-200 shadow-sm" required>
                        @foreach ($countries as $code => $labels)
                            @php $lab = is_array($labels) ? ($labels['cg'] ?? $code) : $labels; @endphp
                            <option value="{{ $code }}" @selected(old('country', $reservation->country) === $code)>{{ $lab }} ({{ $code }})</option>
                        @endforeach
                    </select>
                    <x-input-error class="mt-2" :messages="$errors->get('country')" />
                </div>

                <div>
                    <x-input-label for="license_plate" value="Registarska tablica" />
                    <x-text-input class="mt-1 block w-full" type="text" name="license_plate" id="license_plate" required
                        style="text-transform: uppercase"
                        :value="old('license_plate', $reservation->license_plate)" />
                    <x-input-error class="mt-2" :messages="$errors->get('license_plate')" />
                </div>

                <div class="md:col-span-2">
                    <x-input-label for="vehicle_type_id" value="Kategorija vozila" />
                    <select name="vehicle_type_id" id="vehicle_type_id" class="mt-1 block w-full rounded-md border-red-200 shadow-sm" required>
                        @foreach ($vehicleTypesAllowed as $vt)
                            <option value="{{ $vt->id }}" @selected((string) old('vehicle_type_id', $reservation->vehicle_type_id) === (string) $vt->id)>
                                {{ $vt->formatLabel('cg', 'EUR') }}
                            </option>
                        @endforeach
                    </select>
                    <x-input-error class="mt-2" :messages="$errors->get('vehicle_type_id')" />
                </div>

                <div class="md:col-span-2">
                    <x-input-label for="email" value="Email" />
                    <x-text-input class="mt-1 block w-full" type="email" name="email" id="email" required
                        :value="old('email', $reservation->email)" />
                    <x-input-error class="mt-2" :messages="$errors->get('email')" />
                </div>
            </div>

            <div class="flex flex-wrap justify-end gap-3 pt-2">
                <a href="{{ $cancelUrl }}"
                    class="inline-flex items-center px-4 py-2 border border-red-200 rounded-md font-semibold text-xs text-gray-700 uppercase tracking-widest hover:bg-red-50">
                    Otkaži
                </a>
                <button type="submit"
                    class="inline-flex items-center px-4 py-2 bg-red-700 border border-transparent rounded-md font-semibold text-xs text-white uppercase tracking-widest hover:bg-red-800">
                    Sačuvaj
                </button>
            </div>
        </form>
    </div>
</x-admin-panel-layout>
