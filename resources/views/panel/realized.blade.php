@php
    $p = fn (string $key, ?string $fallback = null) => \App\Support\UiText::t('panel', $key, $fallback);
    $ui = fn (string $key, ?string $fallback = null) => \App\Support\UiText::t('reservation', $key, $fallback);
    $locale = app()->getLocale();
@endphp
<x-app-layout>
    <x-slot name="header">
        <h2 class="font-semibold text-xl text-gray-800 leading-tight">
            {{ $p('page_realized_title', 'Realized reservations') }}
        </h2>
    </x-slot>

    <div class="py-6">
        <div class="max-w-3xl mx-auto sm:px-6 lg:px-8 space-y-6">
            @if (session('message'))
                <div class="rounded-md bg-red-50 p-3 text-sm text-red-900">{{ session('message') }}</div>
            @endif

            <div class="bg-white overflow-hidden shadow-sm sm:rounded-lg">
                <div class="p-6 space-y-4">
                    <p class="text-sm text-gray-600">{{ $p('realized_intro', 'Past reservations and today’s visits after your departure time has ended.') }}</p>

                    <h3 class="text-lg font-medium text-gray-900">{{ $p('realized_table_title', 'Reservations') }}</h3>

                    @if ($reservations->isEmpty())
                        <p class="text-gray-500">{{ $p('realized_empty', 'No realized reservations.') }}</p>
                    @else
                        <div class="overflow-x-auto">
                            <table class="min-w-full divide-y divide-gray-200 text-sm">
                                <thead class="bg-red-50">
                                    <tr>
                                        <th class="px-3 py-2 text-left font-medium text-gray-700">{{ $ui('date', 'Date') }}</th>
                                        <th class="px-3 py-2 text-left font-medium text-gray-700">{{ $p('table_kind_or_arrival', $locale === 'cg' ? 'Termin / vrsta' : 'Slot / type') }}</th>
                                        <th class="px-3 py-2 text-left font-medium text-gray-700">{{ $ui('departure_time', 'Departure') }}</th>
                                        <th class="px-3 py-2 text-left font-medium text-gray-700">{{ $ui('registration_plates', 'Registration plates') }}</th>
                                        <th class="px-3 py-2 text-right font-medium text-gray-700">{{ $p('table_price', 'Price') }}</th>
                                        <th class="px-3 py-2 text-right font-medium text-gray-700 w-28">{{ $p('table_actions', 'Actions') }}</th>
                                    </tr>
                                </thead>
                                <tbody class="divide-y divide-gray-200">
                                    @foreach ($reservations as $r)
                                        <tr>
                                            <td class="px-3 py-2 whitespace-nowrap text-gray-900">{{ $r->reservation_date?->format('Y-m-d') }}</td>
                                            <td class="px-3 py-2 text-gray-700">@include('partials.reservation-slot-display', ['reservation' => $r, 'slot' => $r->dropOffTimeSlot])</td>
                                            <td class="px-3 py-2 text-gray-700">
                                                @if ($r->isDailyTicket())
                                                    <span class="text-gray-400">—</span>
                                                @else
                                                    @include('partials.reservation-slot-display', ['reservation' => $r, 'slot' => $r->pickUpTimeSlot])
                                                @endif
                                            </td>
                                            <td class="px-3 py-2 text-gray-900">{{ $r->license_plate }}</td>
                                            <td class="px-3 py-2 text-right text-gray-900 whitespace-nowrap">
                                                @if ($r->status === 'free')
                                                    {{ $ui('free_reservation', 'Free') }}
                                                @else
                                                    {{ number_format((float) ($r->invoice_amount ?? 0), 2, '.', '') }} €
                                                @endif
                                            </td>
                                            <td class="px-3 py-2 text-right whitespace-nowrap">
                                                @if (in_array($r->status, ['paid', 'free'], true))
                                                    <a
                                                        href="{{ route('panel.reservations.invoice.view', ['id' => $r->id], false) }}"
                                                        target="_blank"
                                                        rel="noopener noreferrer"
                                                        class="inline-flex items-center px-3 py-1.5 border border-red-200 rounded-md text-xs font-medium text-red-700 bg-white hover:bg-red-50"
                                                    >
                                                        {{ $p('reservations_pdf', 'PDF') }}
                                                    </a>
                                                @else
                                                    <span class="text-gray-400">—</span>
                                                @endif
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
