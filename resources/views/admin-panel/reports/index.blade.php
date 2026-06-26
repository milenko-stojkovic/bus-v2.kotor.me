@php
    /** @var string $minDate */
    /** @var string $maxDate */
@endphp

<x-admin-panel-layout :page-title="$pageTitle ?? 'Izvještaji'" nav-active="reports">
    <div class="space-y-6" x-data="reportsWizard()">
        <div>
            <h1 class="text-lg font-semibold text-gray-900">Izvještaji</h1>
            <p class="text-sm text-gray-600 mt-1">Izaberi tip izveštaja i vremenski opseg, zatim generiši PDF u novom tabu.</p>
        </div>

        @if ($errors->any())
            <div class="rounded-md bg-red-50 p-4 text-sm text-red-800 space-y-1">
                @foreach ($errors->all() as $err)
                    <div>{{ $err }}</div>
                @endforeach
            </div>
        @endif

        <div class="bg-white shadow rounded-lg p-6 border border-red-100">
            <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
                <div>
                    <div class="text-sm font-semibold text-gray-900 mb-3">Kada</div>
                    <div class="space-y-2 text-sm text-gray-700">
                        <label class="flex items-center gap-2">
                            <input type="radio" name="when" value="daily" class="rounded border-red-200" x-model="when">
                            <span>Dnevni</span>
                        </label>
                        <label class="flex items-center gap-2">
                            <input type="radio" name="when" value="monthly" class="rounded border-red-200" x-model="when" :disabled="kind === 'advance_obligations'">
                            <span>Mjesečni</span>
                        </label>
                        <label class="flex items-center gap-2">
                            <input type="radio" name="when" value="yearly" class="rounded border-red-200" x-model="when" :disabled="kind === 'advance_obligations'">
                            <span>Godišnji</span>
                        </label>
                        <label class="flex items-center gap-2">
                            <input type="radio" name="when" value="period" class="rounded border-red-200" x-model="when" :disabled="kind === 'advance_obligations'">
                            <span>Period</span>
                        </label>
                    </div>
                    @if ((bool) config('features.advance_payments'))
                        <div class="text-xs text-gray-500 mt-2" x-show="kind === 'advance_obligations'">
                            Za “Obaveze po avansima” koristi se samo dnevni izbor (stanje na dan).
                        </div>
                    @endif
                </div>

                <div>
                    <div class="text-sm font-semibold text-gray-900 mb-3">Kakav</div>
                    <div class="space-y-2 text-sm text-gray-700">
                        <label class="flex items-center gap-2">
                            <input type="radio" name="kind" value="by_payment" class="rounded border-red-200" x-model="kind">
                            <span>Po uplati</span>
                        </label>
                        <label class="flex items-center gap-2">
                            <input type="radio" name="kind" value="by_realization" class="rounded border-red-200" x-model="kind">
                            <span>Po realizaciji</span>
                        </label>
                        <label class="flex items-center gap-2">
                            <input type="radio" name="kind" value="by_vehicle_type" class="rounded border-red-200" x-model="kind">
                            <span>Po tipu vozila</span>
                        </label>
                        <label class="flex items-center gap-2">
                            <input type="radio" name="kind" value="by_reservation_type" class="rounded border-red-200" x-model="kind">
                            <span>Po tipu rezervacije</span>
                        </label>
                        @if ((bool) config('features.advance_payments'))
                            <label class="flex items-center gap-2">
                                <input type="radio" name="kind" value="advance_obligations" class="rounded border-red-200" x-model="kind" @change="when = 'daily'">
                                <span>Obaveze po avansima</span>
                            </label>
                        @endif
                    </div>
                </div>
            </div>

            <div class="mt-6 flex justify-end">
                <button type="button"
                        class="inline-flex items-center px-4 py-2 bg-red-700 border border-transparent rounded-md font-semibold text-xs text-white uppercase tracking-widest disabled:opacity-40 disabled:cursor-not-allowed hover:bg-red-800"
                        :disabled="!canProceed()"
                        @click="step = 2">
                    Izvještaj
                </button>
            </div>
        </div>

        <div class="bg-white shadow rounded-lg p-6 border border-red-100" x-show="step === 2" x-cloak>
            <div class="text-sm font-semibold text-gray-900">Izbor opsega</div>
            <p class="text-sm text-gray-600 mt-1" x-show="kind === 'by_reservation_type'">Opseg po datumu rezervacije (reservation_date); samo plaćene rezervacije.</p>
            <p class="text-sm text-gray-600 mt-1" x-show="kind !== 'advance_obligations' && kind !== 'by_reservation_type'">Opseg je ograničen na datume kreiranja rezervacija (created_at).</p>
            <p class="text-sm text-gray-600 mt-1" x-show="kind === 'advance_obligations'">Snapshot izvještaj: stanje se računa po ledger transakcijama (created_at) zaključno sa krajem izabranog dana.</p>

            <form method="get"
                  target="_blank"
                  action="{{ route('panel_admin.reports.pdf', [], false) }}"
                  class="mt-4 space-y-4">
                <input type="hidden" name="when" :value="when">
                <input type="hidden" name="kind" :value="kind">

                <div class="max-w-sm" x-show="when === 'daily'" x-cloak>
                        <label for="date_display" class="block text-sm font-medium text-gray-700" x-show="kind !== 'advance_obligations'">Datum</label>
                        <label for="date_display" class="block text-sm font-medium text-gray-700" x-show="kind === 'advance_obligations'">Stanje na dan</label>
                        <x-iso-date-input id="date" name="date"
                            x-model="dailyDate"
                            @input="dailyDate = $event.target.value"
                            @change="dailyDate = $event.target.value"
                            :min="$minDate" :max="$maxDate" />
                    </div>

                <template x-if="when === 'monthly'">
                    <div class="grid grid-cols-1 sm:grid-cols-2 gap-4 max-w-md">
                        <div>
                            <x-input-label for="year" value="Godina" />
                            <select id="year" name="year"
                                    x-model="monthYear"
                                    @change="month = ''"
                                    class="mt-1 block w-full rounded-md border-red-200 shadow-sm">
                                <option value="">—</option>
                                @for ($y = $minYear; $y <= $maxYear; $y++)
                                    <option value="{{ $y }}">{{ $y }}</option>
                                @endfor
                            </select>
                        </div>
                        <div>
                            <x-input-label for="month" value="Mjesec" />
                            <select id="month" name="month"
                                    x-model="month"
                                    :disabled="!monthYear"
                                    class="mt-1 block w-full rounded-md border-red-200 shadow-sm disabled:bg-red-50 disabled:text-gray-400">
                                <option value="">—</option>
                                @for ($m = 1; $m <= 12; $m++)
                                    <option value="{{ $m }}" :disabled="!isMonthlyMonthAllowed({{ $m }})">
                                        {{ str_pad((string)$m, 2, '0', STR_PAD_LEFT) }}
                                    </option>
                                @endfor
                            </select>
                        </div>
                    </div>
                </template>

                <template x-if="when === 'yearly'">
                    <div class="max-w-sm">
                        <x-input-label for="year2" value="Godina" />
                        <select id="year2" name="year"
                                x-model="yearlyYear"
                                class="mt-1 block w-full rounded-md border-red-200 shadow-sm">
                            <option value="">—</option>
                            @for ($y = $minYear; $y <= $maxYear; $y++)
                                <option value="{{ $y }}">{{ $y }}</option>
                            @endfor
                        </select>
                    </div>
                </template>

                <div x-show="when === 'period'" x-cloak class="grid grid-cols-1 sm:grid-cols-2 gap-4 max-w-md">
                        <div>
                            <x-input-label for="date_from_display" value="Od" />
                            <x-iso-date-input id="date_from" name="date_from"
                                x-model="dateFrom"
                                @input="dateFrom = $event.target.value"
                                @change="dateFrom = $event.target.value"
                                :min="$minDate" :max="$maxDate" />
                        </div>
                        <div>
                            <x-input-label for="date_to_display" value="Do" />
                            <x-iso-date-input id="date_to" name="date_to"
                                x-model="dateTo"
                                @input="dateTo = $event.target.value"
                                @change="dateTo = $event.target.value"
                                :min="$minDate" :max="$maxDate" />
                        </div>
                    </div>

                <div class="flex justify-end gap-2 pt-2">
                    <a href="{{ route('panel_admin.reports', [], false) }}"
                       class="inline-flex items-center px-4 py-2 border border-red-200 rounded-md font-semibold text-xs text-gray-700 uppercase tracking-widest hover:bg-red-50"
                       @click.prevent="resetAll()">
                        Odkaži
                    </a>
                    <button type="submit"
                            :disabled="!canGeneratePdf()"
                            class="inline-flex items-center px-4 py-2 bg-red-700 border border-transparent rounded-md font-semibold text-xs text-white uppercase tracking-widest shadow-sm hover:bg-red-600 focus:outline-none focus:ring-2 focus:ring-red-500 focus:ring-offset-2 disabled:opacity-40 disabled:cursor-not-allowed disabled:hover:bg-red-700">
                        PDF
                    </button>
                </div>
            </form>
        </div>
    </div>

    <script>
        function reportsWizard() {
            return {
                step: 1,
                when: '',
                kind: '',
                dailyDate: '',
                dateFrom: '',
                dateTo: '',
                monthYear: '',
                month: '',
                yearlyYear: '',
                minDate: @json($minDate),
                maxDate: @json($maxDate),
                canProceed() {
                    return this.when !== '' && this.kind !== '';
                },
                isoHiddenValue(id) {
                    const el = document.getElementById(id);
                    if (!el || !el.hasAttribute('data-iso-date-hidden')) {
                        return '';
                    }

                    return el.value || '';
                },
                canGeneratePdf() {
                    if (this.step !== 2) return false;
                    if (!this.canProceed()) return false;

                    if (this.when === 'daily') {
                        const d = this.dailyDate || this.isoHiddenValue('date');

                        return d !== '';
                    }
                    if (this.when === 'monthly') {
                        return (this.monthYear || '') !== '' && (this.month || '') !== '';
                    }
                    if (this.when === 'yearly') {
                        return (this.yearlyYear || '') !== '';
                    }
                    if (this.when === 'period') {
                        const from = this.dateFrom || this.isoHiddenValue('date_from');
                        const to = this.dateTo || this.isoHiddenValue('date_to');
                        if (from === '' || to === '') return false;

                        return from <= to;
                    }

                    return false;
                },
                isMonthlyMonthAllowed(m) {
                    const y = parseInt(this.monthYear || '0', 10);
                    if (!y) return false;
                    // monthStart = YYYY-MM-01, monthEnd = last day of month
                    const start = new Date(Date.UTC(y, m - 1, 1));
                    const end = new Date(Date.UTC(y, m, 0));
                    const min = new Date(this.minDate + 'T00:00:00Z');
                    const max = new Date(this.maxDate + 'T00:00:00Z');
                    return end >= min && start <= max;
                },
                resetAll() {
                    this.step = 1;
                    this.when = '';
                    this.kind = '';
                    this.dailyDate = '';
                    this.dateFrom = '';
                    this.dateTo = '';
                    this.monthYear = '';
                    this.month = '';
                    this.yearlyYear = '';
                    window.location = @json(route('panel_admin.reports', [], false));
                },
            };
        }
    </script>
</x-admin-panel-layout>

