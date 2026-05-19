@php
    $p = fn (string $key, ?string $fallback = null) => \App\Support\UiText::t('panel', $key, $fallback);
    $locale = $locale ?? app()->getLocale();
@endphp

<x-app-layout>
    <x-slot name="header">
        <h2 class="font-semibold text-xl text-gray-800 leading-tight">
            {{ $p('vehicles_remove_workflow_title', 'Remove vehicle') }}
        </h2>
    </x-slot>

    <div class="py-6">
        <div class="max-w-5xl mx-auto sm:px-6 lg:px-8 space-y-6">
            @if (session('error'))
                <div class="rounded-md bg-red-50 p-4 text-sm text-red-800">{{ session('error') }}</div>
            @endif

            <div class="bg-white shadow sm:rounded-lg p-6 space-y-2">
                <div class="text-sm text-gray-700">
                    {{ $p('vehicles_remove_target', 'Target vehicle:') }}
                    <span class="font-semibold text-gray-900">{{ $targetVehicle->license_plate }}</span>
                    <span class="text-gray-600">({{ $targetVehicle->vehicleType?->formatLabel($locale, 'EUR') ?? ('#'.$targetVehicle->vehicle_type_id) }})</span>
                </div>
                <div class="text-sm text-gray-600">
                    {{ $p('vehicles_remove_help', 'This vehicle is used in upcoming reservations. You must replace it on those reservations before it can be removed.') }}
                </div>
            </div>

            @if ($upcomingReservations->isEmpty())
                <div class="bg-white shadow sm:rounded-lg p-6">
                    <p class="text-sm text-gray-700">{{ $p('vehicles_remove_no_upcoming', 'No upcoming reservations use this vehicle.') }}</p>
                    <form method="POST" action="{{ route('panel.vehicles.destroy', $targetVehicle->id, false) }}" class="mt-4">
                        @csrf
                        @method('DELETE')
                        <button type="submit" class="inline-flex items-center justify-center rounded-md bg-red-600 px-3 py-2 text-xs font-semibold uppercase tracking-widest text-white hover:bg-red-700">
                            {{ $p('vehicles_remove_confirm', 'Remove') }}
                        </button>
                    </form>
                </div>
            @else
                @if ($missingAnyCandidate)
                    <div class="rounded-md bg-red-50 border border-red-200 p-4 text-sm text-red-900">
                        {{ $p('vehicles_remove_no_solution', 'This vehicle cannot be removed because at least one upcoming reservation has no eligible replacement. Please add another vehicle of the same category to your fleet.') }}
                    </div>
                @endif

                <form method="POST" action="{{ route('panel.vehicles.remove.apply', $targetVehicle->id, false) }}" class="space-y-4">
                    @csrf

                    <div class="bg-white shadow sm:rounded-lg p-6 space-y-4">
                        <h3 class="text-base font-semibold text-gray-900">
                            {{ $p('vehicles_remove_upcoming_title', 'Upcoming reservations to replace') }}
                        </h3>

                        <div class="space-y-4">
                            @foreach ($upcomingReservations as $r)
                                @php
                                    $cands = $candidateVehiclesByReservationId[$r->id] ?? collect();
                                    $onlyOne = $cands instanceof \Illuminate\Support\Collection ? $cands->count() === 1 : false;
                                @endphp
                                <div class="rounded-md border border-red-100 p-4 space-y-2">
                                    <div class="flex flex-wrap items-start justify-between gap-2 text-sm">
                                        <div class="text-gray-900 font-semibold">#{{ $r->id }}</div>
                                        <div class="text-gray-700">
                                            {{ $r->reservation_date?->format('Y-m-d') ?? '—' }}
                                            —
                                            {{ $r->dropOffTimeSlot?->time_slot ?? '—' }}
                                            /
                                            {{ $r->pickUpTimeSlot?->time_slot ?? '—' }}
                                        </div>
                                    </div>

                                    @if ($onlyOne)
                                        <div class="text-xs text-red-800">
                                            {{ $p('vehicles_remove_only_one', 'Only one eligible replacement exists for this reservation.') }}
                                        </div>
                                    @endif

                                    <div class="grid grid-cols-1 md:grid-cols-2 gap-2 items-end">
                                        <div>
                                            <label class="block text-xs font-medium text-gray-600">{{ $p('vehicles_remove_select_label', 'Replacement vehicle') }}</label>
                                            <select
                                                name="replacements[{{ $r->id }}]"
                                                class="mt-1 block w-full rounded-md border-red-200 shadow-sm focus:border-red-500 focus:ring-red-500 text-sm"
                                                @disabled($cands->isEmpty())
                                                required
                                            >
                                                <option value="">{{ $p('vehicles_remove_select_placeholder', 'Select vehicle') }}</option>
                                                @foreach ($cands as $v)
                                                    <option value="{{ $v->id }}">{{ $v->license_plate }}</option>
                                                @endforeach
                                            </select>
                                        </div>
                                    </div>
                                </div>
                            @endforeach
                        </div>

                        <div class="flex flex-wrap gap-2 justify-end">
                            <a href="{{ route('panel.vehicles', [], false) }}" class="inline-flex items-center justify-center rounded-md border border-red-200 bg-white px-3 py-2 text-xs font-semibold uppercase tracking-widest text-gray-800 hover:bg-red-50">
                                {{ $p('action_cancel', 'Cancel') }}
                            </a>
                            <button
                                type="submit"
                                class="inline-flex items-center justify-center rounded-md bg-red-600 px-3 py-2 text-xs font-semibold uppercase tracking-widest text-white hover:bg-red-700 disabled:opacity-50"
                                @disabled($missingAnyCandidate)
                                onclick="return confirm('{{ $p('vehicles_remove_apply_confirm', 'Apply replacements and remove this vehicle?') }}');"
                            >
                                {{ $p('vehicles_remove_apply', 'Apply & remove') }}
                            </button>
                        </div>
                    </div>
                </form>
            @endif
        </div>
    </div>
</x-app-layout>

