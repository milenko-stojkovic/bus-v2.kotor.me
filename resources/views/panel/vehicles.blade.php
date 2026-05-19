@php
    $pn = fn (string $key, ?string $fallback = null) => \App\Support\UiText::t('panel', $key, $fallback);
    $ui = fn (string $key) => \App\Support\UiText::t('reservation', $key);
    $locale = app()->getLocale();
@endphp
<x-app-layout>
    <x-slot name="header">
        <h2 class="font-semibold text-xl text-gray-800 leading-tight">{{ $pn('nav_vehicles', 'Vehicles') }}</h2>
    </x-slot>

    <div class="py-6" x-data="{ deleteOpen: false, deleteUrl: '' }">
        <div class="max-w-7xl mx-auto sm:px-6 lg:px-8 space-y-6">
            @if(session('message'))
                <div class="rounded-md bg-red-50 p-4 text-sm text-red-900">{{ session('message') }}</div>
            @endif
            @if(session('error'))
                <div class="rounded-md bg-red-50 p-4 text-sm text-red-800">{{ session('error') }}</div>
            @endif

            @if ($errors->any())
                <div class="rounded-md bg-red-50 p-4 text-sm text-red-800 space-y-1">
                    @foreach ($errors->all() as $err)
                        <div>{{ $err }}</div>
                    @endforeach
                </div>
            @endif

            <div class="bg-white overflow-hidden shadow-sm sm:rounded-lg">
                <div class="p-6">
                    <form method="post" action="{{ route('panel.vehicles.store', [], false) }}" id="addVehicleForm" class="space-y-3">
                        @csrf
                        <div class="flex flex-wrap gap-3 items-end">
                            <div class="flex-none w-40">
                                <x-input-label for="add_license_plate" :value="$ui('registration_plates')" />
                                <x-text-input
                                    id="add_license_plate"
                                    name="license_plate"
                                    type="text"
                                    class="mt-1 block w-full"
                                    :value="old('license_plate')"
                                    autocomplete="off"
                                    inputmode="latin"
                                    pattern="[A-Z0-9]+"
                                />
                            </div>
                            <div class="flex-none w-72">
                                <x-input-label for="add_vehicle_type_id" :value="$ui('vehicle_category')" />
                                <select id="add_vehicle_type_id" name="vehicle_type_id" class="mt-1 block w-full rounded-md border-red-200 shadow-sm focus:border-red-500 focus:ring-red-500">
                                    <option value="">{{ $ui('select_vehicle_category') }}</option>
                                    @foreach($vehicleTypes as $type)
                                        <option value="{{ $type->id }}" @selected((string) old('vehicle_type_id') === (string) $type->id)>
                                            {{ $type->formatLabel($locale, 'EUR') }}
                                        </option>
                                    @endforeach
                                </select>
                            </div>
                        </div>
                        <div class="flex flex-wrap gap-3">
                            <x-primary-button type="submit" id="addVehicleSubmit" disabled>{{ $pn('vehicles_add') }}</x-primary-button>
                            <x-secondary-button type="button" id="cancelAddVehicle">{{ $pn('vehicles_cancel') }}</x-secondary-button>
                        </div>
                    </form>

                    @php
                        $change = session('category_change_needed');
                        $needsChange = is_array($change) && isset($change['old_vehicle_id'], $change['license_plate'], $change['old_vehicle_type_id'], $change['requested_vehicle_type_id']);
                    @endphp

                    @if ($needsChange)
                        <div class="mt-6 rounded-md border border-red-200 bg-red-50 p-4 space-y-3">
                            <div class="text-sm text-red-900 font-semibold">
                                {{ $pn('vehicle_category_change_title', 'Zahtjev za promjenu kategorije') }}
                            </div>
                            <div class="text-sm text-red-900">
                                {{ $pn('vehicle_category_change_help', 'Molimo priložite fotografiju ili PDF dokumenta.') }}
                            </div>

                            <form method="post" action="{{ route('panel.vehicles.category_change_requests.store', [], false) }}" enctype="multipart/form-data" class="space-y-3">
                                @csrf
                                <input type="hidden" name="old_vehicle_id" value="{{ (int) $change['old_vehicle_id'] }}">
                                <input type="hidden" name="license_plate" value="{{ (string) $change['license_plate'] }}">
                                <input type="hidden" name="old_vehicle_type_id" value="{{ (int) $change['old_vehicle_type_id'] }}">
                                <input type="hidden" name="requested_vehicle_type_id" value="{{ (int) $change['requested_vehicle_type_id'] }}">

                                <div>
                                    <x-input-label for="change_document" :value="$pn('vehicle_category_change_document', 'Dokument (slika ili PDF)')" />
                                    <input id="change_document" name="document" type="file" required accept="image/*,application/pdf"
                                           class="mt-1 block w-full text-sm text-gray-700" />
                                    <div class="mt-1 text-xs text-gray-700">{{ $pn('vehicle_category_change_document_hint', 'Max 10MB. Obavezno.') }}</div>
                                </div>

                                <div class="flex flex-wrap gap-3">
                                    <x-primary-button type="submit">{{ $pn('vehicle_category_change_send', 'Pošalji zahtjev') }}</x-primary-button>
                                    <a href="{{ route('panel.vehicles', [], false) }}" class="inline-flex items-center justify-center rounded-md border border-red-200 bg-white px-3 py-2 text-xs font-semibold uppercase tracking-widest text-gray-800 hover:bg-red-50">
                                        {{ $pn('vehicle_category_change_cancel', 'Odustani') }}
                                    </a>
                                </div>
                            </form>
                        </div>
                    @endif
                </div>
            </div>

            <div class="bg-white overflow-hidden shadow-sm sm:rounded-lg">
                <div class="p-6">
                    <h3 class="text-lg font-medium text-gray-900 mb-4">{{ $pn('vehicles_list_title') }}</h3>
                    <div class="overflow-x-auto">
                        <table class="min-w-full divide-y divide-gray-200 text-sm">
                            <thead class="bg-red-50">
                                <tr>
                                    <th class="px-4 py-2 text-left text-xs font-medium text-gray-500 uppercase">{{ $ui('registration_plates') }}</th>
                                    <th class="px-4 py-2 text-left text-xs font-medium text-gray-500 uppercase">{{ $ui('vehicle_category') }}</th>
                                    <th class="px-4 py-2 text-right text-xs font-medium text-gray-500 uppercase w-40"></th>
                                </tr>
                            </thead>
                            <tbody class="bg-white divide-y divide-gray-200">
                                @forelse($vehicles as $vehicle)
                                    <tr>
                                        <td class="px-4 py-3 font-medium text-gray-900">{{ $vehicle->license_plate }}</td>
                                        <td class="px-4 py-3">{{ $vehicle->vehicleType?->formatLabel($locale, 'EUR') }}</td>
                                        <td class="px-4 py-3 text-right">
                                            <button
                                                type="button"
                                                class="text-sm font-medium text-red-600 hover:text-red-800"
                                                x-on:click="deleteUrl = @js(route('panel.vehicles.destroy', $vehicle, false)); deleteOpen = true"
                                            >
                                                {{ $pn('vehicles_remove') }}
                                            </button>
                                        </td>
                                    </tr>
                                @empty
                                    <tr>
                                        <td colspan="3" class="px-4 py-6 text-center text-gray-500">{{ $pn('vehicles_empty') }}</td>
                                    </tr>
                                @endforelse
                            </tbody>
                        </table>
                    </div>

                    @if($vehicles->hasPages())
                        <div class="mt-4">{{ $vehicles->links() }}</div>
                    @endif
                </div>
            </div>
        </div>

        <div
            x-show="deleteOpen"
            x-cloak
            class="fixed inset-0 z-50 flex items-center justify-center p-4"
            style="display: none;"
            role="dialog"
            aria-modal="true"
        >
            <div class="absolute inset-0 bg-black/50" x-on:click="deleteOpen = false"></div>
            <div class="relative bg-white rounded-lg shadow-xl max-w-md w-full p-6 space-y-4" x-on:click.stop>
                <p class="text-sm text-gray-700">{{ $pn('vehicles_remove_confirm') }}</p>
                <p class="text-xs text-gray-500">
                    {{ $pn('vehicles_remove_workflow_hint', 'If this vehicle is used in upcoming reservations, you will be guided through a replacement workflow before it can be removed.') }}
                </p>
                <form :action="deleteUrl" method="post" class="flex flex-wrap gap-2 justify-end">
                    @csrf
                    @method('delete')
                    <x-secondary-button type="button" x-on:click="deleteOpen = false">{{ $pn('vehicles_remove_modal_cancel') }}</x-secondary-button>
                    <x-danger-button type="submit">{{ $pn('vehicles_remove_modal_remove') }}</x-danger-button>
                </form>
            </div>
        </div>
    </div>

    <script>
        (function () {
            const form = document.getElementById('addVehicleForm');
            if (!form) return;
            const plate = document.getElementById('add_license_plate');
            const type = document.getElementById('add_vehicle_type_id');
            const submit = document.getElementById('addVehicleSubmit');
            const cancel = document.getElementById('cancelAddVehicle');

            function refreshAddState() {
                const p = (plate && plate.value) ? plate.value.trim() : '';
                const t = type && type.value;
                if (submit) submit.disabled = !(p.length > 0 && t);
            }

            if (plate) {
                plate.addEventListener('input', function () {
                    const v = (plate.value || '').toUpperCase().replace(/[^A-Z0-9]/g, '');
                    if (plate.value !== v) plate.value = v;
                    refreshAddState();
                });
            }
            if (type) type.addEventListener('change', refreshAddState);
            if (form) {
                form.addEventListener('input', refreshAddState);
                form.addEventListener('change', refreshAddState);
            }
            refreshAddState();

            if (cancel) {
                cancel.addEventListener('click', function () {
                    if (plate) plate.value = '';
                    if (type) type.value = '';
                    refreshAddState();
                });
            }
        })();
    </script>
</x-app-layout>
