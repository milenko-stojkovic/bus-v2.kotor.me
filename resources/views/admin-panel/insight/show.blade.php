@php
    /** @var array $case */
    $mtid = (string)($case['merchant_transaction_id'] ?? '');
    $isAdminFree = (bool)($case['is_admin_free_reservation'] ?? false);
    $temp = $case['temp'] ?? null;
    $reservation = $case['reservation'] ?? null;
    $timeline = (array)($case['timeline'] ?? []);
    $timelineAvailable = (bool)($case['timeline_available'] ?? false);
    $timelineNote = (string)($case['timeline_note'] ?? '');

    $copyLines = [];
    $copyLines[] = 'MTID: '.$mtid;
    $fmtDate = fn ($d) => $d ? $d->format('d.m.Y.') : '—';
    if ($temp) {
        $copyLines[] = 'Temp status: '.$temp->status;
        $copyLines[] = 'Temp created_at: '.$fmtDate($temp->created_at);
        $copyLines[] = 'Temp updated_at: '.$fmtDate($temp->updated_at);
        if (!empty($temp->resolution_reason)) $copyLines[] = 'Resolution reason: '.$temp->resolution_reason;
        $copyLines[] = 'Ime: '.$temp->user_name;
        $copyLines[] = 'Email: '.$temp->email;
        $copyLines[] = 'Država: '.$temp->country;
        $copyLines[] = 'Tablica: '.$temp->license_plate;
        $copyLines[] = 'Reservation date: '.$fmtDate($temp->reservation_date);
        $copyLines[] = 'Drop-off: '.($temp->dropOffTimeSlot?->time_slot ?? '');
        $copyLines[] = 'Pick-up: '.($temp->pickUpTimeSlot?->time_slot ?? '');
    }
    if ($reservation) {
        $copyLines[] = 'Reservation exists: DA';
        $copyLines[] = 'Reservation status: '.$reservation->status;
        $copyLines[] = 'Fiscal JIR: '.($reservation->fiscal_jir ?? '—');
        $copyLines[] = 'Email sent: '.$reservation->email_sent;
        $copyLines[] = 'Created by admin: '.($reservation->created_by_admin ? 'DA' : 'NE');
    } else {
        $copyLines[] = 'Reservation exists: NE';
    }
    $copyLines[] = '--- Timeline ---';
    if ($timelineAvailable) {
        foreach ($timeline as $e) {
            $copyLines[] = trim(($e['ts'] ?? '').' '.($e['label'] ?? '').' '.($e['raw'] ?? ''));
        }
    } else {
        $copyLines[] = $timelineNote !== '' ? $timelineNote : 'Detaljni payment logovi nisu dostupni u retention periodu.';
    }
    $copyText = implode("\n", $copyLines);
@endphp

<x-admin-panel-layout :page-title="$pageTitle ?? 'Uvid'" nav-active="insight">
    <div class="space-y-6" x-data="{copied:false}">
        @php
            $rq = (string) request()->query('rq', '');
            $backUrl = route('panel_admin.insight', [], false);
            if ($rq !== '') {
                $backUrl .= '?'.$rq;
            }
        @endphp
        <div class="flex flex-wrap items-start justify-between gap-3">
            <div>
                <h1 class="text-lg font-semibold text-gray-900">Uvid — <span class="font-mono text-sm">{{ $mtid }}</span></h1>
                <p class="text-sm text-gray-600 mt-1">Detalj jednog payment case-a (read-only).</p>
            </div>
            <div class="flex items-center gap-2">
                <a href="{{ $backUrl }}"
                   class="inline-flex items-center px-4 py-2 border border-red-200 rounded-md font-semibold text-xs text-gray-700 uppercase tracking-widest hover:bg-red-50">
                    Nazad
                </a>
                <button type="button"
                        @click="navigator.clipboard.writeText(@js($copyText)).then(() => {copied=true; setTimeout(()=>copied=false,1500);})"
                        class="inline-flex items-center px-4 py-2 bg-red-700 border border-transparent rounded-md font-semibold text-xs text-white uppercase tracking-widest hover:bg-red-800">
                    Copy details
                </button>
            </div>
        </div>

        <div x-show="copied" x-cloak class="rounded-md bg-red-50 p-3 text-sm text-red-900 border border-red-100">
            Kopirano.
        </div>

        @if ($isAdminFree && !$temp)
            <div class="rounded-md bg-red-50 p-4 text-sm text-red-900 border border-red-100">
                Ovo je admin-free rezervacija i ne pripada payment lifecycle-u. Prikazani su samo rezervacioni podaci (bez payment timeline-a).
            </div>
        @endif

        <section class="bg-white shadow rounded-lg p-6 border border-red-100">
            <h2 class="text-base font-semibold text-gray-900">A. Temp data (payment attempt)</h2>
            @if (!$temp)
                <div class="text-sm text-gray-600 mt-2">Nema temp_data za ovaj MTID.</div>
            @else
                <div class="mt-3 grid grid-cols-1 md:grid-cols-2 gap-3 text-sm">
                    <div><span class="text-gray-600">Status:</span> <span class="font-medium">{{ $temp->status }}</span></div>
                    <div><span class="text-gray-600">Resolution reason:</span> {{ $temp->resolution_reason ?? '—' }}</div>
                    <div><span class="text-gray-600">Created at:</span> {{ $fmtDate($temp->created_at) }}</div>
                    <div><span class="text-gray-600">Updated at:</span> {{ $fmtDate($temp->updated_at) }}</div>
                    <div><span class="text-gray-600">Ime:</span> {{ $temp->user_name ?? '—' }}</div>
                    <div><span class="text-gray-600">Email:</span> {{ $temp->email ?? '—' }}</div>
                    <div><span class="text-gray-600">Država:</span> {{ $temp->country ?? '—' }}</div>
                    <div><span class="text-gray-600">Tablica:</span> {{ $temp->license_plate ?? '—' }}</div>
                    <div><span class="text-gray-600">Tip vozila:</span> {{ $temp->vehicleType?->formatLabel('cg') ?? '—' }}</div>
                    <div><span class="text-gray-600">Reservation date:</span> {{ $fmtDate($temp->reservation_date) }}</div>
                    <div><span class="text-gray-600">Drop-off:</span> {{ $temp->dropOffTimeSlot?->time_slot ?? '—' }}</div>
                    <div><span class="text-gray-600">Pick-up:</span> {{ $temp->pickUpTimeSlot?->time_slot ?? '—' }}</div>
                    <div><span class="text-gray-600">Retry token:</span> <span class="font-mono text-xs">{{ $temp->retry_token ?? '—' }}</span></div>
                </div>
            @endif
        </section>

        <section class="bg-white shadow rounded-lg p-6 border border-red-100">
            <h2 class="text-base font-semibold text-gray-900">B. Povezana rezervacija</h2>
            @if (!$reservation)
                <div class="text-sm text-gray-600 mt-2">Rezervacija ne postoji za ovaj MTID.</div>
            @else
                <div class="mt-3 grid grid-cols-1 md:grid-cols-2 gap-3 text-sm">
                    <div><span class="text-gray-600">Status:</span> <span class="font-medium">{{ $reservation->status }}</span></div>
                    <div><span class="text-gray-600">Created by admin:</span> {{ $reservation->created_by_admin ? 'DA' : 'NE' }}</div>
                    <div><span class="text-gray-600">Fiscal JIR:</span> {{ $reservation->fiscal_jir ?? '—' }}</div>
                    <div><span class="text-gray-600">Email sent flag:</span> {{ $reservation->email_sent }}</div>
                </div>
                @if ($reservation->id)
                    <div class="mt-3 text-sm">
                        <span class="text-gray-600">Referenca:</span> reservation #{{ $reservation->id }}
                    </div>
                @endif
            @endif
        </section>

        <section class="bg-white shadow rounded-lg p-6 border border-red-100">
            <h2 class="text-base font-semibold text-gray-900">C. Timeline (payments log)</h2>
            @if (!$timelineAvailable)
                <div class="text-sm text-gray-600 mt-2">{{ $timelineNote !== '' ? $timelineNote : 'Detaljni payment logovi nisu dostupni u retention periodu.' }}</div>
            @else
                <div class="mt-3 space-y-2 text-sm">
                    @foreach ($timeline as $e)
                        <div class="rounded border border-red-100 p-3">
                            <div class="flex items-baseline justify-between gap-3">
                                <div class="font-medium">{{ $e['label'] ?? 'payment' }}</div>
                                <div class="text-xs text-gray-500 font-mono">{{ $e['ts'] ?? '' }}</div>
                            </div>
                            <div class="mt-1 text-xs text-gray-600 font-mono break-all">{{ $e['raw'] ?? '' }}</div>
                        </div>
                    @endforeach
                </div>
            @endif
        </section>
    </div>
</x-admin-panel-layout>

