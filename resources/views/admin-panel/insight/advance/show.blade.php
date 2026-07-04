@php
    /** @var array $case */
    $mtid = (string)($case['merchant_transaction_id'] ?? '');
    $topup = $case['topup'] ?? null;
    $agency = $case['agency'] ?? null;
    $ledgerTopup = $case['ledger_topup'] ?? null;
    $ledgerRows = collect($case['ledger_rows'] ?? []);
    $reservation = $case['reservation'] ?? null;
    $timeline = (array)($case['timeline'] ?? []);
    $timelineAvailable = (bool)($case['timeline_available'] ?? false);
    $timelineNote = (string)($case['timeline_note'] ?? '');

    $fmtDate = fn ($d) => $d ? $d->format('d.m.Y. H:i') : '—';
    $fmtDay = fn ($d) => $d ? $d->format('d.m.Y.') : '—';

    $copyLines = [];
    $copyLines[] = 'MTID: '.$mtid;
    if ($topup) {
        $copyLines[] = 'Topup id: '.$topup->id;
        $copyLines[] = 'Topup status: '.$topup->status;
        $copyLines[] = 'Amount: '.(string) $topup->amount.' EUR';
        $copyLines[] = 'Created at: '.$fmtDate($topup->created_at);
        $copyLines[] = 'Paid at: '.$fmtDate($topup->paid_at);
        $copyLines[] = 'Failed at: '.$fmtDate($topup->failed_at);
        $copyLines[] = 'Confirmation sent: '.$fmtDate($topup->confirmation_sent_at);
        $copyLines[] = 'Confirmation email: '.($topup->confirmation_email ?? '—');
    }
    if ($agency) {
        $copyLines[] = 'Agencija: '.($agency->name ?? '').' <'.($agency->email ?? '').'>';
        $copyLines[] = 'Agency user id: '.($agency->id ?? '—');
    }
    if ($ledgerTopup) {
        $copyLines[] = 'Ledger topup id: '.$ledgerTopup->id;
    }
    if ($reservation) {
        $copyLines[] = 'Reservation (same MTID): #'.$reservation->id.' status='.$reservation->status;
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

    $bankPayloadJson = '';
    if ($topup && is_array($topup->bank_payload) && $topup->bank_payload !== []) {
        $bankPayloadJson = json_encode($topup->bank_payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?: '';
    }
@endphp

<x-admin-panel-layout :page-title="$pageTitle ?? 'Uvid — avans'" nav-active="insight">
    <div class="space-y-6" x-data="{copied:false}">
        @include('admin-panel.insight._tabs', ['insightTab' => $insightTab ?? 'advance'])

        @php
            $rq = (string) request()->query('rq', '');
            $backUrl = route('panel_admin.insight.advance', [], false);
            if ($rq !== '') {
                $backUrl .= '?'.$rq;
            }
        @endphp
        <div class="flex flex-wrap items-start justify-between gap-3">
            <div>
                <h1 class="text-lg font-semibold text-gray-900">Uvid — avans — <span class="font-mono text-sm">{{ $mtid }}</span></h1>
                <p class="text-sm text-gray-600 mt-1">Detalj jednog pokušaja avansne uplate (read-only).</p>
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

        <section class="bg-white shadow rounded-lg p-6 border border-red-100">
            <h2 class="text-base font-semibold text-gray-900">A. Topup (agency_advance_topups)</h2>
            @if (!$topup)
                <div class="text-sm text-gray-600 mt-2">Nema topup zapisa za ovaj MTID.</div>
            @else
                <div class="mt-3 grid grid-cols-1 md:grid-cols-2 gap-3 text-sm">
                    <div><span class="text-gray-600">ID:</span> {{ $topup->id }}</div>
                    <div><span class="text-gray-600">Status:</span> <span class="font-medium">{{ $topup->status }}</span></div>
                    <div><span class="text-gray-600">Iznos:</span> {{ number_format((float) $topup->amount, 2, '.', '') }} EUR</div>
                    <div><span class="text-gray-600">Created at:</span> {{ $fmtDate($topup->created_at) }}</div>
                    <div><span class="text-gray-600">Updated at:</span> {{ $fmtDate($topup->updated_at) }}</div>
                    <div><span class="text-gray-600">Paid at:</span> {{ $fmtDate($topup->paid_at) }}</div>
                    <div><span class="text-gray-600">Failed at:</span> {{ $fmtDate($topup->failed_at) }}</div>
                    <div><span class="text-gray-600">Potvrda poslata:</span> {{ $fmtDate($topup->confirmation_sent_at) }}</div>
                    <div class="md:col-span-2"><span class="text-gray-600">Email potvrde:</span> {{ $topup->confirmation_email ?? '—' }}</div>
                </div>
            @endif
        </section>

        <section class="bg-white shadow rounded-lg p-6 border border-red-100">
            <h2 class="text-base font-semibold text-gray-900">B. Agencija</h2>
            @if (!$agency)
                <div class="text-sm text-gray-600 mt-2">Agencija nije pronađena.</div>
            @else
                <div class="mt-3 grid grid-cols-1 md:grid-cols-2 gap-3 text-sm">
                    <div><span class="text-gray-600">Ime:</span> {{ $agency->name ?? '—' }}</div>
                    <div><span class="text-gray-600">Email:</span> {{ $agency->email ?? '—' }}</div>
                    <div><span class="text-gray-600">User ID:</span> {{ $agency->id }}</div>
                </div>
                <div class="mt-3 text-sm">
                    <a class="text-red-800 underline" href="{{ route('panel_admin.agencies.show', ['user' => $agency->id], false) }}">Otvori detalj agencije</a>
                </div>
            @endif
        </section>

        <section class="bg-white shadow rounded-lg p-6 border border-red-100">
            <h2 class="text-base font-semibold text-gray-900">C. Ledger (agency_advance_transactions)</h2>
            @if ($ledgerRows->isEmpty())
                <div class="text-sm text-gray-600 mt-2">Nema ledger redova za ovaj MTID.</div>
            @else
                <div class="mt-3 overflow-x-auto">
                    <table class="min-w-full text-sm">
                        <thead class="text-xs text-gray-500 uppercase">
                        <tr class="border-b">
                            <th class="py-2 pr-4 text-left">ID</th>
                            <th class="py-2 pr-4 text-left">Type</th>
                            <th class="py-2 pr-4 text-left">Amount</th>
                            <th class="py-2 pr-4 text-left">Reference</th>
                            <th class="py-2 pr-4 text-left">Note</th>
                            <th class="py-2 pr-4 text-left">Created</th>
                        </tr>
                        </thead>
                        <tbody class="divide-y">
                        @foreach ($ledgerRows as $tx)
                            <tr>
                                <td class="py-2 pr-4">{{ $tx->id }}</td>
                                <td class="py-2 pr-4">{{ $tx->type }}</td>
                                <td class="py-2 pr-4 whitespace-nowrap">{{ number_format((float) $tx->amount, 2, '.', '') }} EUR</td>
                                <td class="py-2 pr-4 text-xs">{{ $tx->reference_type ?? '—' }} #{{ $tx->reference_id ?? '—' }}</td>
                                <td class="py-2 pr-4 text-xs">{{ $tx->note ?? '—' }}</td>
                                <td class="py-2 pr-4 text-xs whitespace-nowrap">{{ $fmtDate($tx->created_at) }}</td>
                            </tr>
                        @endforeach
                        </tbody>
                    </table>
                </div>
            @endif
        </section>

        @if ($reservation)
            <section class="bg-white shadow rounded-lg p-6 border border-red-100">
                <h2 class="text-base font-semibold text-gray-900">D. Rezervacija (isti MTID)</h2>
                <p class="text-sm text-gray-600 mt-1">Ovaj MTID se takođe pojavljuje na rezervaciji (npr. plaćanje iz avansa ili konverzija late_success).</p>
                <div class="mt-3 grid grid-cols-1 md:grid-cols-2 gap-3 text-sm">
                    <div><span class="text-gray-600">Reservation ID:</span> {{ $reservation->id }}</div>
                    <div><span class="text-gray-600">Status:</span> {{ $reservation->status }}</div>
                    <div><span class="text-gray-600">Payment method:</span> {{ $reservation->payment_method ?? '—' }}</div>
                    <div><span class="text-gray-600">Datum rezervacije:</span> {{ $fmtDay($reservation->reservation_date) }}</div>
                </div>
            </section>
        @endif

        @if ($bankPayloadJson !== '')
            <section class="bg-white shadow rounded-lg p-6 border border-red-100">
                <h2 class="text-base font-semibold text-gray-900">E. Bank payload</h2>
                <pre class="mt-3 text-xs font-mono bg-gray-50 border border-red-100 rounded p-3 overflow-x-auto whitespace-pre-wrap break-all">{{ $bankPayloadJson }}</pre>
            </section>
        @endif

        <section class="bg-white shadow rounded-lg p-6 border border-red-100">
            <h2 class="text-base font-semibold text-gray-900">F. Timeline (payments log)</h2>
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
                            <div class="mt-1 text-xs text-gray-600 font-mono break-all whitespace-pre-wrap">{{ $e['raw'] ?? '' }}</div>
                        </div>
                    @endforeach
                </div>
            @endif
        </section>
    </div>
</x-admin-panel-layout>
