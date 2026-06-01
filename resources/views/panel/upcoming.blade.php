@php
    $p = fn (string $key, ?string $fallback = null) => \App\Support\UiText::t('panel', $key, $fallback);
    $ui = fn (string $key, ?string $fallback = null) => \App\Support\UiText::t('reservation', $key, $fallback);
    $locale = app()->getLocale();
@endphp
<x-app-layout>
    <x-slot name="header">
        <h2 class="font-semibold text-xl text-gray-800 leading-tight">
            {{ $p('page_upcoming_title', 'Upcoming reservations') }}
        </h2>
    </x-slot>

    <div class="py-6">
        <div class="max-w-3xl mx-auto sm:px-6 lg:px-8 space-y-6">
            @if ($errors->any())
                <div class="rounded-md bg-red-50 p-4 text-sm text-red-800">
                    @foreach ($errors->all() as $err)
                        <div>{{ $err }}</div>
                    @endforeach
                </div>
            @endif

            @if (session('message'))
                <div class="rounded-md bg-red-50 p-3 text-sm text-red-900">{{ session('message') }}</div>
            @endif
            @if (session('error'))
                <div class="rounded-md bg-red-50 p-3 text-sm text-red-800">{{ session('error') }}</div>
            @endif

            <div class="bg-white overflow-hidden shadow-sm sm:rounded-lg">
                <div class="p-6 space-y-4 text-sm text-gray-700">
                    <p>{{ $p('upcoming_help_intro', 'You can change the vehicle on an upcoming reservation only within the rules below.') }}</p>
                    <ul class="list-disc list-inside space-y-1 text-gray-600">
                        <li>{{ $p('upcoming_help_category_rule', 'You may switch only to a vehicle of the same or a lower category (not a higher one).') }}</li>
                        <li>{{ $p('upcoming_help_invoice_rule', 'For paid reservations, changing the vehicle generates a new invoice/PDF and sends it to your email.') }}</li>
                    </ul>
                </div>
            </div>

            <div class="bg-white overflow-hidden shadow-sm sm:rounded-lg">
                <div class="p-6">
                    <h3 class="text-lg font-medium text-gray-900 mb-4">{{ $p('upcoming_table_title', 'Reservations') }}</h3>

                    @if ($reservations->isEmpty())
                        <p class="text-gray-500">{{ $p('upcoming_empty', 'No upcoming reservations.') }}</p>
                    @else
                        <div class="overflow-x-auto">
                            <table class="min-w-full divide-y divide-gray-200 text-sm">
                                <thead class="bg-red-50">
                                    <tr>
                                        <th class="px-3 py-2 text-left font-medium text-gray-700">{{ $ui('date', 'Date') }}</th>
                                        <th class="px-3 py-2 text-left font-medium text-gray-700">{{ $p('table_kind_or_arrival', $locale === 'cg' ? 'Termin / vrsta' : 'Slot / type') }}</th>
                                        <th class="px-3 py-2 text-left font-medium text-gray-700">{{ $ui('departure_time', 'Departure') }}</th>
                                        <th class="px-3 py-2 text-left font-medium text-gray-700">{{ $ui('registration_plates', 'Registration plates') }}</th>
                                        <th class="px-3 py-2 text-left font-medium text-gray-700">{{ $ui('vehicle_category', 'Vehicle category') }}</th>
                                        <th class="px-3 py-2 text-right font-medium text-gray-700 w-48">{{ $p('table_actions', 'Actions') }}</th>
                                    </tr>
                                </thead>
                                <tbody class="divide-y divide-gray-200">
                                    @foreach ($reservations as $r)
                                        @php
                                            $allowed = collect($allowedVehiclesByReservationId[$r->id] ?? []);
                                            $formId = 'upcoming-veh-form-'.$r->id;
                                            $initialVehicleId = $r->vehicle_id ?? $allowed->first()?->id;
                                        @endphp
                                        <tr class="align-top" x-data="{ editing: false }">
                                            <td class="px-3 py-2 whitespace-nowrap text-gray-900">{{ $r->reservation_date?->format('Y-m-d') }}</td>
                                            <td class="px-3 py-2 text-gray-700">@include('partials.reservation-slot-display', ['reservation' => $r, 'slot' => $r->dropOffTimeSlot])</td>
                                            <td class="px-3 py-2 text-gray-700">
                                                @if ($r->isDailyTicket())
                                                    <span class="text-gray-400">—</span>
                                                @else
                                                    @include('partials.reservation-slot-display', ['reservation' => $r, 'slot' => $r->pickUpTimeSlot])
                                                @endif
                                            </td>
                                            <td class="px-3 py-2 text-gray-900">
                                                <span x-show="! editing">{{ $r->license_plate }}</span>
                                                @if ($allowed->isNotEmpty())
                                                    <select
                                                        id="upcoming-sel-{{ $r->id }}"
                                                        x-show="editing"
                                                        name="vehicle_id"
                                                        form="{{ $formId }}"
                                                        class="mt-1 block w-full rounded-md border-red-200 shadow-sm focus:border-red-500 focus:ring-red-500 text-sm"
                                                        required
                                                    >
                                                        @foreach ($allowed as $v)
                                                            <option value="{{ $v->id }}" @selected((int) $r->vehicle_id === (int) $v->id)>
                                                                {{ $v->license_plate }}
                                                            </option>
                                                        @endforeach
                                                    </select>
                                                @else
                                                    <span x-show="editing" class="text-red-700 text-xs">{{ $p('upcoming_no_eligible_vehicle', 'No eligible vehicle in your fleet.') }}</span>
                                                @endif
                                            </td>
                                            <td class="px-3 py-2 text-gray-700">{{ $r->vehicleType?->formatLabel($locale, 'EUR') ?? '—' }}</td>
                                            <td class="px-3 py-2 text-right whitespace-nowrap">
                                                <div x-show="! editing" class="flex justify-end">
                                                    @if ($allowed->isNotEmpty())
                                                        <button
                                                            type="button"
                                                            class="inline-flex items-center px-3 py-1.5 border border-red-200 rounded-md text-xs font-medium text-gray-700 bg-white hover:bg-red-50"
                                                            @click="editing = true"
                                                        >
                                                            {{ $p('change_vehicle', 'Change vehicle') }}
                                                        </button>
                                                    @else
                                                        <span class="text-gray-400 text-xs">—</span>
                                                    @endif
                                                </div>
                                                <div x-show="editing" class="flex flex-col sm:flex-row sm:justify-end gap-2 items-stretch sm:items-center">
                                                    @if ($allowed->isNotEmpty())
                                                        <form id="{{ $formId }}" method="post" action="{{ route('panel.reservations.vehicle', $r->id, false) }}" class="inline">
                                                            @csrf
                                                            @method('PATCH')
                                                            <button
                                                                type="submit"
                                                                class="inline-flex items-center justify-center px-3 py-1.5 rounded-md text-xs font-medium text-white bg-red-700 hover:bg-red-800"
                                                            >
                                                                {{ $p('change_vehicle_confirm', 'Confirm') }}
                                                            </button>
                                                        </form>
                                                    @endif
                                                    <button
                                                        type="button"
                                                        class="inline-flex items-center justify-center px-3 py-1.5 border border-red-200 rounded-md text-xs font-medium text-gray-700 bg-white hover:bg-red-50"
                                                        @click="editing = false; (function(){ var el = document.getElementById('upcoming-sel-{{ $r->id }}'); if (el) el.value = '{{ $initialVehicleId }}'; })()"
                                                    >
                                                        {{ $p('action_cancel', 'Cancel') }}
                                                    </button>
                                                </div>
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
