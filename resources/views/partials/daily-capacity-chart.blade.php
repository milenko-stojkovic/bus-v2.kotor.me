@php
    /** @var array{date:string,capacity:int,slots:array<int,array<string,mixed>>,meta:array{max_total:int,reservations_total:int}} $dataset */
    $dataset = $dataset ?? ['date' => '', 'capacity' => 0, 'slots' => [], 'meta' => ['max_total' => 0, 'reservations_total' => 0]];
    $title = $title ?? 'Kapacitet';
    $chartId = $chartId ?? ('daily-capacity-'.\Illuminate\Support\Str::random(8));
    $scriptId = $scriptId ?? ($chartId.'-data');

    $dateLabel = (string) ($dataset['date'] ?? '');
    if ($dateLabel !== '') {
        try {
            $dateLabel = \Carbon\Carbon::parse($dateLabel)->format('d.m.Y.');
        } catch (\Throwable) {
            // keep raw label
        }
    }
@endphp

<section class="bg-white shadow rounded-lg p-4 sm:p-6">
    <div class="flex flex-wrap items-start justify-between gap-3 mb-3">
        <div class="min-w-0">
            <h2 class="text-lg font-semibold text-gray-900">{{ $title }}</h2>
            <div class="text-sm text-gray-600">
                Datum: <span class="font-medium">{{ $dateLabel }}</span>
            </div>
        </div>
        <div class="text-xs text-gray-500 text-right">
            <div>
                Kapacitet: <span class="font-semibold">{{ (int) ($dataset['capacity'] ?? 0) }}</span>
            </div>
            <div class="mt-0.5">
                Ukupno rezervacija: <span class="font-semibold">{{ (int) ($dataset['meta']['reservations_total'] ?? 0) }}</span>
                <span class="text-gray-400">(plaćene + besplatne)</span>
            </div>
        </div>
    </div>

    <div class="relative">
        <canvas
            id="{{ $chartId }}"
            class="js-daily-capacity-chart w-full"
            data-capacity-chart-script-id="{{ $scriptId }}"
            data-capacity-chart-title="{{ $title }}"
            height="140"
        ></canvas>
    </div>

    <script type="application/json" id="{{ $scriptId }}">{!! json_encode($dataset, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) !!}</script>

    <div class="mt-3 text-xs text-gray-600 flex flex-wrap gap-x-4 gap-y-2">
        <span class="inline-flex items-center gap-2">
            <span class="inline-block w-3 h-3 rounded-sm" style="background:#1f6feb"></span>
            Rezervisano
        </span>
        <span class="inline-flex items-center gap-2">
            <span class="inline-block w-3 h-3 rounded-sm" style="background:#f2cc60"></span>
            Pending
        </span>
        <span class="inline-flex items-center gap-2">
            <span class="inline-block w-6 h-[2px]" style="background:#111827"></span>
            Kapacitet
        </span>
    </div>
</section>

