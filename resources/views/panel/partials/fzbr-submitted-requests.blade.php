@php
    $locale = $locale ?? app()->getLocale();
    $p = fn (string $key, ?string $fallback = null) => \App\Support\UiText::t('panel', $key, $fallback, $locale);
    $submittedRequests = $submittedRequests ?? collect();
    $statusLabelsByRequestId = $statusLabelsByRequestId ?? collect();
    $listEntriesByRequestId = $listEntriesByRequestId ?? collect();
@endphp

<div class="bg-white overflow-hidden shadow-sm sm:rounded-lg">
    <div class="p-6">
        <h3 class="text-lg font-medium text-gray-900 mb-4">
            {{ $p('fzbr_submitted_requests_title', $locale === 'cg' ? 'Moji poslati zahtjevi' : 'My submitted requests') }}
        </h3>

        @if ($submittedRequests->isEmpty())
            <p class="text-gray-500">
                {{ $p('fzbr_submitted_requests_empty', $locale === 'cg'
                    ? 'Nemate poslatih zahtjeva za besplatne rezervacije.'
                    : 'You have no submitted free reservation requests.') }}
            </p>
        @else
            <div class="overflow-x-auto">
                <table class="min-w-full divide-y divide-gray-200 text-sm">
                    <thead class="bg-red-50">
                        <tr>
                            <th class="px-3 py-2 text-left font-medium text-gray-700">{{ $p('fzbr_submitted_col_date', $locale === 'cg' ? 'Datum zahtjeva' : 'Request date') }}</th>
                            <th class="px-3 py-2 text-left font-medium text-gray-700">{{ $p('fzbr_submitted_col_arrival', $locale === 'cg' ? 'Vrijeme dolaska' : 'Arrival time') }}</th>
                            <th class="px-3 py-2 text-left font-medium text-gray-700">{{ $p('fzbr_submitted_col_departure', $locale === 'cg' ? 'Vrijeme odlaska' : 'Departure time') }}</th>
                            <th class="px-3 py-2 text-left font-medium text-gray-700">{{ $p('fzbr_submitted_col_vehicles', $locale === 'cg' ? 'Vozila / tablice' : 'Vehicles / plates') }}</th>
                            <th class="px-3 py-2 text-left font-medium text-gray-700">{{ $p('fzbr_submitted_col_status', $locale === 'cg' ? 'Status' : 'Status') }}</th>
                            <th class="px-3 py-2 text-left font-medium text-gray-700">{{ $p('fzbr_submitted_col_submitted_at', $locale === 'cg' ? 'Datum slanja' : 'Submitted at') }}</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-gray-200">
                        @foreach ($submittedRequests as $req)
                            @php
                                $entries = $listEntriesByRequestId->get($req->id, collect());
                                $statusLabel = $statusLabelsByRequestId->get($req->id, $req->status);
                            @endphp
                            <tr class="align-top">
                                <td class="px-3 py-2 whitespace-nowrap text-gray-900">
                                    @foreach ($entries as $entry)
                                        <div @if (!$loop->first) class="mt-1" @endif>{{ $entry['date'] }}</div>
                                    @endforeach
                                </td>
                                <td class="px-3 py-2 text-gray-700">
                                    @foreach ($entries as $entry)
                                        <div @if (!$loop->first) class="mt-1" @endif>{{ $entry['arrival'] }}</div>
                                    @endforeach
                                </td>
                                <td class="px-3 py-2 text-gray-700">
                                    @foreach ($entries as $entry)
                                        <div @if (!$loop->first) class="mt-1" @endif>{{ $entry['departure'] }}</div>
                                    @endforeach
                                </td>
                                <td class="px-3 py-2 text-gray-900">
                                    @foreach ($entries as $entry)
                                        <div @if (!$loop->first) class="mt-1" @endif>{{ $entry['plates'] }}</div>
                                    @endforeach
                                </td>
                                <td class="px-3 py-2 text-gray-900">{{ $statusLabel }}</td>
                                <td class="px-3 py-2 whitespace-nowrap text-gray-700">{{ $req->created_at?->timezone('Europe/Podgorica')->format('d.m.Y. H:i') }}</td>
                            </tr>
                            @if ($req->status === \App\Models\FreeReservationRequest::STATUS_REJECTED && filled($req->rejection_reason))
                                <tr>
                                    <td colspan="6" class="px-3 pb-3 pt-0">
                                        <details class="max-w-[calc(100vw-3rem)] rounded-md border border-red-100 bg-red-50 px-3 py-2">
                                            <summary class="cursor-pointer text-sm font-medium text-red-800">
                                                {{ $p('fzbr_rejection_reason_label', $locale === 'cg' ? 'Razlog odbijanja' : 'Rejection reason') }}
                                            </summary>
                                            <div class="mt-2 whitespace-pre-wrap break-words text-sm text-gray-900">{{ $req->rejection_reason }}</div>
                                        </details>
                                    </td>
                                </tr>
                            @endif
                        @endforeach
                    </tbody>
                </table>
            </div>
        @endif
    </div>
</div>
