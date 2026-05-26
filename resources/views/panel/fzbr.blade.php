@php
    $locale = $locale ?? app()->getLocale();
    $ui = fn (string $key, ?string $fallback = null) => \App\Support\UiText::t('free_request', $key, $fallback);
    $p = fn (string $key, ?string $fallback = null) => \App\Support\UiText::t('panel', $key, $fallback);

    $minDate = now()->toDateString();
    $maxDate = now()->addDays(90)->toDateString();

    $descFallbackCg = "Naknadu za ekonomsko iskorišćavanje kulturnih dobara ne plaćaju obveznici naknade pod uslovom da organizuju prevoz putnika koja su:\n- lica sa teškim čulnim i tjelesnim smetnjama;\n- učesnici školskih ekskurzija, odnosno učenici i studenti čiji boravak organizuju škole i fakulteti u okviru redovnih programa, održavanja obrazovnih, sportskih i kulturnih manifestacija sa teritorije Crne Gore;\n- strani državljani koji su po međunarodnim konvencijama i sporazumima oslobođeni plaćanja taksi i koji organizovano, preko zvaničnih humanitarnih organizacija, dolaze radi pružanja humanitarne pomoći.\n\nZa ostvarivanje prava na besplatnu rezervaciju potrebno je dostaviti dokumentaciju kojom se potvrđuje ispunjavanje uslova za oslobađanje od plaćanja naknade.\n\nU slučaju školskih ekskurzija i sličnih organizovanih posjeta, škola ili druga obrazovna ustanova prethodno se obraća Sekretarijatu za lokalne prihode, budžet i finansije Opštine Kotor putem e-mail adrese: prihodi@kotor.me.\n\nNakon dobijanja odobrenja od strane Sekretarijata, škola dostavlja odobrenje agenciji ili prevozniku koji podnosi zahtjev kroz FZBR formular.\n\nUz formular je potrebno priložiti dokumentaciju kojom se potvrđuje angažovanje prevoznika, kao i odobrenje Sekretarijata. Ukoliko u trenutku podnošenja ne raspolažete kompletnom dokumentacijom, možete poslati ono što imate, a preostalu dokumentaciju dostaviti naknadno.\n\nBesplatna rezervacija neće biti odobrena dok sva neophodna dokumentacija ne bude dostavljena i pregledana od strane administratora.";
    $descFallbackEn = "The economic use fee for cultural assets is not paid by liable parties when they organise passenger transport for:\n- persons with severe sensory and physical disabilities;\n- participants in school excursions, i.e. pupils and students whose stay is organised by schools and faculties under regular programmes, or educational, sports and cultural events held in Montenegro;\n- foreign nationals who are exempt from paying fees under international conventions and agreements and who arrive in an organised manner through official humanitarian organisations to provide humanitarian aid.\n\nTo exercise the right to a free reservation, documentation must be submitted proving that the conditions for exemption from the fee are met.\n\nFor school excursions and similar organised visits, the school or other educational institution must first contact the Secretariat for Local Revenues, Budget and Finance of the Municipality of Kotor by e-mail: prihodi@kotor.me.\n\nAfter receiving approval from the Secretariat, the school forwards the approval to the agency or carrier that submits the request via the FZBR form.\n\nThe form should be accompanied by documentation proving the engagement of the carrier and the approval of the Secretariat. If you do not have complete documentation at the time of submission, you may send what you have now and provide the rest later.\n\nA free reservation will not be approved until all required documentation has been submitted and reviewed by an administrator.";

    $instructionFallbackCg = 'Nakon slanja formulara, zahtjev dolazi na pregled i odobravanje administratoru Bus Kotor servisa. Odobravanje zavisi od raspoloživih kapaciteta za traženi datum, kao i od kompletiranosti dostavljene dokumentacije. Molimo Vas da zahtjev podnesete što ranije — preporučeno najmanje jedan dan prije željenog datuma rezervacije.';
    $instructionFallbackEn = 'After submitting the form, the request is sent to the Bus Kotor service administrator for review and approval. Approval depends on available capacity for the requested date as well as on the completeness of the submitted documentation. Please submit your request as early as possible — we recommend at least one day before the desired reservation date.';

    $title = $ui('fzbr_title', 'Formular za besplatnu rezervaciju');
    $desc = $ui('fzbr_description', $locale === 'cg' ? $descFallbackCg : $descFallbackEn);
    $instruction = trim((string) $ui('fzbr_instruction', $locale === 'cg' ? $instructionFallbackCg : $instructionFallbackEn));
    $descLines = preg_split("/\\r?\\n/", trim((string) $desc)) ?: [];
    $descLines = array_values(array_filter(array_map('trim', $descLines), fn ($l) => $l !== ''));

    $uploadLabel = $ui('documents_label', 'Osnov za zahtjev za besplatnu rezervaciju');
    $uploadHint = $ui('documents_hint', "Priložite dokumentaciju kojom dokazujete pravo na besplatnu rezervaciju (ugovor, angažovanje škole ili institucije, odobrenje Sekretarijata za lokalne prihode, budžet i finansije i sl.).\n\nUkoliko trenutno ne raspolažete kompletnom dokumentacijom, možete poslati ono što imate, a preostalu dokumentaciju dostaviti naknadno.");
@endphp

<x-app-layout>
    <x-slot name="header">
        <h2 class="font-semibold text-xl text-gray-800 leading-tight">FZBR</h2>
    </x-slot>

    <div class="py-6" x-data="fzbrForm()">
        <div class="max-w-3xl mx-auto sm:px-6 lg:px-8 space-y-6">
            @if (session('status'))
                <div class="rounded-md bg-red-50 p-4 text-sm text-red-900">{{ session('status') }}</div>
            @endif
            @if ($errors->any())
                <div class="rounded-md bg-red-50 p-4 text-sm text-red-800 space-y-1">
                    @foreach ($errors->all() as $err)
                        <div>{{ $err }}</div>
                    @endforeach
                </div>
            @endif

            <div class="bg-white shadow sm:rounded-lg p-6 space-y-3">
                <div class="space-y-1">
                    <div class="text-lg font-semibold text-gray-900">{{ $title }}</div>
                </div>

                <div class="text-sm text-gray-700 space-y-2">
                    @foreach ($descLines as $line)
                        @if (str_starts_with($line, '-'))
                            @break
                        @endif
                    @endforeach
                    @php
                        // Split into: intro (before first bullet), bullets, outro (after bullets).
                        $intro = [];
                        $bullets = [];
                        $outro = [];
                        $seenBullet = false;
                        foreach ($descLines as $l) {
                            if (str_starts_with($l, '-')) {
                                $seenBullet = true;
                                $bullets[] = ltrim($l, "- \t");
                                continue;
                            }
                            if (! $seenBullet) {
                                $intro[] = $l;
                            } else {
                                $outro[] = $l;
                            }
                        }
                    @endphp
                    @foreach ($intro as $pLine)
                        <p>{{ $pLine }}</p>
                    @endforeach
                    @if (!empty($bullets))
                        <ul class="list-disc pl-5 space-y-1">
                            @foreach ($bullets as $b)
                                <li>{{ $b }}</li>
                            @endforeach
                        </ul>
                    @endif
                    @foreach ($outro as $pLine)
                        <p>{{ $pLine }}</p>
                    @endforeach
                </div>

                @if ($instruction !== '')
                    <div class="rounded-md bg-red-50 border border-red-100 p-3 text-sm text-gray-700">
                        {{ $instruction }}
                    </div>
                @endif
            </div>

            {{-- Cjelina 1: Datum + segmenti (dolazak/odlazak) + vozila --}}
            <div class="bg-white shadow sm:rounded-lg p-6 space-y-4">
                <div class="grid grid-cols-1 sm:grid-cols-3 gap-3 items-start">
                    <div
                        class="sm:col-span-1"
                        @change="
                            if ($event.target && $event.target.id === 'reservation_date') {
                                selectedDate = $event.target.value || '';
                                onDateChange();
                            }
                        "
                    >
                        <x-iso-date-input
                            id="reservation_date"
                            name="reservation_date"
                            :label="\App\Support\UiText::t('reservation', 'date')"
                            :value="old('reservation_date', '')"
                            :min="$minDate"
                            :max="$maxDate"
                            form="fzbrPostForm"
                            required
                        />
                    </div>
                    <div class="sm:col-span-2 flex sm:justify-end pt-6">
                        <button
                            type="button"
                            class="inline-flex items-center justify-center rounded-md bg-red-700 px-3 py-2 text-xs font-semibold uppercase tracking-widest text-white hover:bg-red-800 disabled:opacity-50 disabled:cursor-not-allowed"
                            :disabled="segments.length >= 5"
                            @click="addSegment()"
                        >
                            {{ $ui('add_segment', $locale === 'en' ? 'Add arrival and departure' : 'Dodaj dolazak i odlazak') }}
                        </button>
                    </div>
                </div>

                <template x-for="(seg, sIdx) in segments" :key="seg.key">
                    <div class="rounded-md border border-red-100 p-4 space-y-3">
                        <div class="flex items-start justify-between gap-3">
                            <div class="text-sm font-semibold text-gray-900">
                                {{ $ui('segment_title', $locale === 'en' ? 'Arrival and departure' : 'Dolazak i odlazak') }} <span x-text="sIdx + 1"></span>
                            </div>
                            <button
                                type="button"
                                class="text-xs font-semibold uppercase tracking-widest text-gray-700 hover:text-gray-900"
                                x-show="segments.length > 1"
                                @click="removeSegment(sIdx)"
                            >
                                {{ $ui('remove_segment', $locale === 'en' ? 'Remove segment' : 'Ukloni segment') }}
                            </button>
                        </div>

                        <div class="grid grid-cols-1 sm:grid-cols-2 gap-3">
                            <div>
                                <label class="block font-medium text-sm text-gray-700" x-bind:for="'seg_' + sIdx + '_drop'">
                                    {{ \App\Support\UiText::t('reservation', 'arrival_time') }}
                                </label>
                                <select
                                    class="mt-1 block w-full rounded-md border-red-200 shadow-sm focus:border-red-500 focus:ring-red-500"
                                    :id="'seg_' + sIdx + '_drop'"
                                    :name="'segments[' + sIdx + '][drop_off_time_slot_id]'"
                                    x-model="seg.drop"
                                    form="fzbrPostForm"
                                    required
                                    :disabled="!selectedDate"
                                    @change="onArrivalChange(sIdx)"
                                >
                                    <option value="">{{ \App\Support\UiText::t('reservation', 'select_time_slot') }}</option>
                                    <template x-for="opt in arrivalSlots" :key="opt.id">
                                        <option :value="opt.id" :disabled="!!opt.disabled" x-text="opt.label"></option>
                                    </template>
                                </select>
                            </div>

                            <div>
                                <label class="block font-medium text-sm text-gray-700" x-bind:for="'seg_' + sIdx + '_pick'">
                                    {{ \App\Support\UiText::t('reservation', 'departure_time') }}
                                </label>
                                <select
                                    class="mt-1 block w-full rounded-md border-red-200 shadow-sm focus:border-red-500 focus:ring-red-500"
                                    :id="'seg_' + sIdx + '_pick'"
                                    :name="'segments[' + sIdx + '][pick_up_time_slot_id]'"
                                    x-model="seg.pick"
                                    form="fzbrPostForm"
                                    required
                                    :disabled="!selectedDate || !seg.drop"
                                >
                                    <option value="">{{ \App\Support\UiText::t('reservation', 'select_time_slot') }}</option>
                                    <template x-for="opt in (seg.departureSlots || [])" :key="opt.id">
                                        <option :value="opt.id" :disabled="!!opt.disabled" x-text="opt.label"></option>
                                    </template>
                                </select>
                            </div>
                        </div>

                        <div class="pt-1 space-y-2">
                            <div class="text-sm font-semibold text-gray-900">{{ $ui('vehicles_title', 'Vozila') }}</div>

                            <template x-for="(row, vIdx) in seg.rows" :key="`${seg.key}_${vIdx}`">
                                <div class="grid grid-cols-1 md:grid-cols-12 gap-2 items-start">
                                    <div class="md:col-span-10 min-w-0">
                                        <select
                                            class="mt-1 block w-full rounded-md border-red-200 shadow-sm focus:border-red-500 focus:ring-red-500"
                                            :name="'segments[' + sIdx + '][vehicles][' + vIdx + ']'"
                                            form="fzbrPostForm"
                                            x-model="row.vehicle_id"
                                            @change="onVehiclesChanged(sIdx)"
                                            required
                                        >
                                            <option value="">{{ $ui('select_vehicle', 'Izaberite vozilo') }}</option>
                                            <template x-for="opt in optionsForVehicle(sIdx, vIdx)" :key="opt.id">
                                                <option :value="opt.id" x-text="opt.label"></option>
                                            </template>
                                        </select>
                                    </div>
                                    <div class="md:col-span-2 flex gap-2 md:justify-end pt-6 min-w-0">
                                        <button
                                            type="button"
                                            class="inline-flex items-center justify-center rounded-md bg-red-700 px-3 py-2 text-xs font-semibold uppercase tracking-widest text-white hover:bg-red-800 disabled:opacity-50 disabled:cursor-not-allowed"
                                            x-show="vIdx === seg.rows.length - 1"
                                            :disabled="!canAddVehicle(sIdx, vIdx)"
                                            @click="addVehicleRow(sIdx)"
                                        >
                                            {{ $ui('add_vehicle', 'Dodaj vozilo') }}
                                        </button>
                                        <button
                                            type="button"
                                            class="inline-flex items-center justify-center rounded-md border border-red-200 bg-white px-3 py-2 text-xs font-semibold uppercase tracking-widest text-gray-800 hover:bg-red-50"
                                            x-show="vIdx !== seg.rows.length - 1"
                                            @click="confirmRemoveVehicle(sIdx, vIdx)"
                                        >
                                            {{ $ui('remove_vehicle', 'Ukloni vozilo') }}
                                        </button>
                                    </div>
                                </div>
                            </template>

                            @php
                                $maxSegVehicles = (int) ($maxVehiclesPerSegment ?? 0);
                                if ($maxSegVehicles <= 0) {
                                    $maxSegVehicles = 9;
                                }
                                $limitTpl = (string) $ui(
                                    'vehicles_limit_dynamic',
                                    $locale === 'en'
                                        ? 'Maximum number of vehicles per segment: %1$d.'
                                        : 'Maksimalan broj vozila po segmentu: %1$d.',
                                );
                                $limitText = sprintf($limitTpl, $maxSegVehicles);
                            @endphp
                            <p class="text-xs text-gray-500">{{ $limitText }}</p>
                        </div>
                    </div>
                </template>
            </div>

            <div x-show="showConfirm" class="fixed inset-0 z-50 flex items-center justify-center">
                <div class="absolute inset-0 bg-black/50" @click="cancelRemove()"></div>
                <div class="relative w-[92%] max-w-md rounded-lg bg-white shadow-lg p-4 space-y-3" @click.stop>
                    <div class="font-semibold">{{ $ui('remove_vehicle_confirm', 'Da li si siguran da želiš da ukloniš ovo vozilo?') }}</div>
                    <div class="flex justify-end gap-2">
                        <button type="button" class="rounded-md border border-red-200 bg-white px-3 py-2 text-xs font-semibold uppercase tracking-widest text-gray-800 hover:bg-red-50" @click="cancelRemove()">
                            {{ $ui('remove_vehicle_no', 'Ne') }}
                        </button>
                        <button type="button" class="rounded-md bg-red-600 px-3 py-2 text-xs font-semibold uppercase tracking-widest text-white hover:bg-red-700" @click="applyRemove()">
                            {{ $ui('remove_vehicle_yes', 'Da') }}
                        </button>
                    </div>
                </div>
            </div>

            {{-- Cjelina 2: Dokumenta + privatnost + submit --}}
            <form method="POST" id="fzbrPostForm" action="{{ route('panel.fzbr.store', [], false) }}" enctype="multipart/form-data" class="space-y-4 bg-white shadow sm:rounded-lg p-6">
                @csrf

                <div class="space-y-2">
                    <x-input-label for="documents" :value="$uploadLabel" />
                    <input
                        id="documents"
                        name="documents[]"
                        type="file"
                        multiple
                        accept="image/*,application/pdf"
                        class="block w-full text-sm text-gray-700 file:mr-4 file:rounded-md file:border-0 file:bg-red-700 file:px-3 file:py-2 file:text-xs file:font-semibold file:uppercase file:tracking-widest file:text-white hover:file:bg-red-800"
                        required
                    />
                    <p class="text-xs text-gray-600 whitespace-pre-line">{{ $uploadHint }}</p>
                    <p class="text-xs text-gray-500">{{ $ui('documents_limit', 'Ukupna veličina svih fajlova: do 10 MB. Dozvoljene slike i PDF.') }}</p>
                    <x-input-error class="mt-2" :messages="$errors->get('documents')" />
                </div>

                <div class="space-y-2">
                    <label class="flex items-start gap-2 text-sm">
                        <input type="checkbox" name="accept_privacy" value="1" class="mt-1 rounded border-red-200" {{ old('accept_privacy') ? 'checked' : '' }} required>
                        <span>{{ \App\Support\UiText::t('reservation', 'accept_privacy') }}</span>
                    </label>
                    <x-input-error class="mt-2" :messages="$errors->get('accept_privacy')" />
                </div>

                <button
                    type="submit"
                    class="inline-flex items-center px-4 py-2 bg-red-700 border border-transparent rounded-md font-semibold text-xs text-white uppercase tracking-widest hover:bg-red-800 disabled:opacity-50 disabled:cursor-not-allowed"
                    :disabled="!canSubmit"
                >
                    {{ $ui('submit', 'Podnesi zahtjev') }}
                </button>
            </form>
        </div>
    </div>

    @php
        $fzbrVehicleOptions = ($userVehicles ?? collect())->map(function (\App\Models\Vehicle $v) use ($locale): array {
            $vtName = $v->vehicleType?->getTranslatedName($locale) ?: ('#'.$v->vehicle_type_id);
            $vtDesc = trim((string) ($v->vehicleType?->getTranslatedDescription($locale) ?? ''));
            $label = $v->license_plate.' — '.($vtDesc !== '' ? ($vtName.' ('.$vtDesc.')') : $vtName);

            return [
                'id' => (int) $v->id,
                'label' => $label,
            ];
        })->values();
    @endphp

    <script>
        function fzbrForm() {
            const all = @json($fzbrVehicleOptions);
            const maxVehiclesPerSegment = {{ (int) ($maxVehiclesPerSegment ?? 0) }};

            return {
                tick: 0,
                maxVehiclesPerSegment,
                selectedDate: '',
                arrivalSlots: [],
                segments: [
                    { key: 'seg_1', drop: '', pick: '', departureSlots: [], rows: [{ vehicle_id: '' }] },
                ],

                showConfirm: false,
                pending: { sIdx: null, vIdx: null },

                init() {
                    const docs = document.getElementById('documents');
                    if (docs) {
                        docs.addEventListener('change', () => { this.tick++; });
                        docs.addEventListener('input', () => { this.tick++; });
                    }
                    const privacy = document.querySelector('input[name="accept_privacy"]');
                    if (privacy) {
                        privacy.addEventListener('change', () => { this.tick++; });
                        privacy.addEventListener('input', () => { this.tick++; });
                    }
                    const date = document.getElementById('reservation_date');
                    if (date) {
                        date.addEventListener('change', () => { this.tick++; });
                        date.addEventListener('input', () => { this.tick++; });
                    }

                    // initial load if date prefilled (browser restore / old input)
                    const initial = document.getElementById('reservation_date')?.value || '';
                    if (initial) {
                        this.selectedDate = initial;
                        this.onDateChange();
                    }
                },

                async fetchSlots(params) {
                    const qs = new URLSearchParams(params);
                    const res = await fetch('{{ route('panel.fzbr.slots', [], false) }}' + '?' + qs.toString(), {
                        headers: { 'Accept': 'application/json' },
                        credentials: 'same-origin',
                    });
                    if (!res.ok) throw new Error('slots fetch failed');
                    return await res.json();
                },

                segmentVehicleRequired(seg) {
                    const rows = Array.isArray(seg?.rows) ? seg.rows : [];
                    const selected = rows.map(r => String(r?.vehicle_id || '')).filter(v => v !== '');
                    if (selected.length > 0) return selected.length;
                    return Math.max(1, rows.length);
                },

                maxSegmentVehicleRequired() {
                    let m = 1;
                    for (const seg of this.segments) {
                        m = Math.max(m, this.segmentVehicleRequired(seg));
                    }
                    return m;
                },

                // opts.resetDepartures: default true (e.g. date change). Pass false when only vehicle counts changed
                // so departure stays selected if still valid. (No Blade double-brace syntax in this file's scripts.)
                async onDateChange(opts = {}) {
                    const resetDepartures = opts.resetDepartures !== false;
                    const date = String(this.selectedDate || '');
                    if (!date) {
                        this.arrivalSlots = [];
                        for (const seg of this.segments) {
                            seg.drop = '';
                            seg.pick = '';
                            seg.departureSlots = [];
                        }
                        return;
                    }

                    try {
                        const required = String(this.maxSegmentVehicleRequired());
                        const data = await this.fetchSlots({ reservation_date: date, required });
                        this.arrivalSlots = Array.isArray(data.arrival_slots) ? data.arrival_slots : [];

                        for (let i = 0; i < this.segments.length; i++) {
                            const seg = this.segments[i];
                            const allowedArrival = new Set(this.arrivalSlots.filter(s => !s.disabled).map(s => String(s.id)));
                            if (seg.drop && !allowedArrival.has(String(seg.drop))) {
                                seg.drop = '';
                            }
                            if (!seg.drop) {
                                seg.pick = '';
                                seg.departureSlots = [];
                                continue;
                            }
                            if (resetDepartures) {
                                seg.pick = '';
                                seg.departureSlots = [];
                            }
                            await this.onArrivalChange(i);
                        }
                    } catch (e) {
                        // fail closed: no slots selectable if endpoint fails
                        this.arrivalSlots = [];
                        for (const seg of this.segments) {
                            seg.drop = '';
                            seg.pick = '';
                            seg.departureSlots = [];
                        }
                    }
                },

                async onArrivalChange(sIdx) {
                    const date = String(this.selectedDate || '');
                    const seg = this.segments[sIdx];
                    if (!date || !seg) return;
                    const drop = String(seg.drop || '');
                    if (!drop) {
                        seg.pick = '';
                        seg.departureSlots = [];
                        return;
                    }

                    try {
                        const required = String(this.segmentVehicleRequired(seg));
                        const data = await this.fetchSlots({ reservation_date: date, drop_off_time_slot_id: drop, required });
                        seg.departureSlots = Array.isArray(data.departure_slots) ? data.departure_slots : [];
                        const allowed = new Set(seg.departureSlots.filter(s => !s.disabled).map(s => String(s.id)));
                        if (seg.pick && !allowed.has(String(seg.pick))) {
                            seg.pick = '';
                        }
                    } catch (e) {
                        seg.pick = '';
                        seg.departureSlots = [];
                    }
                },

                addSegment() {
                    if (this.segments.length >= 5) return;
                    const n = this.segments.length + 1;
                    this.segments.push({ key: `seg_${Date.now()}_${n}`, drop: '', pick: '', departureSlots: [], rows: [{ vehicle_id: '' }] });
                },

                removeSegment(sIdx) {
                    if (this.segments.length <= 1) return;
                    this.segments.splice(sIdx, 1);
                    if (this.selectedDate) {
                        this.onDateChange();
                    }
                },

                selectedIdsInSegment(sIdx) {
                    const seg = this.segments[sIdx];
                    if (!seg) return [];
                    return seg.rows.map(r => String(r.vehicle_id || '')).filter(v => v !== '');
                },

                optionsForVehicle(sIdx, vIdx) {
                    const seg = this.segments[sIdx];
                    const current = String(seg?.rows?.[vIdx]?.vehicle_id || '');
                    const selected = new Set(this.selectedIdsInSegment(sIdx).filter(v => v !== current));
                    return all.filter(o => !selected.has(String(o.id)) || String(o.id) === current);
                },

                canAddVehicle(sIdx, vIdx) {
                    const seg = this.segments[sIdx];
                    if (!seg) return false;
                    if (this.maxVehiclesPerSegment > 0 && seg.rows.length >= this.maxVehiclesPerSegment) return false;
                    const v = String(seg.rows[vIdx]?.vehicle_id || '');
                    return v !== '';
                },

                addVehicleRow(sIdx) {
                    const seg = this.segments[sIdx];
                    if (!seg) return;
                    if (this.maxVehiclesPerSegment > 0 && seg.rows.length >= this.maxVehiclesPerSegment) return;
                    const last = seg.rows[seg.rows.length - 1];
                    if (!last || !String(last.vehicle_id || '')) return;
                    seg.rows.push({ vehicle_id: '' });
                    this.onVehiclesChanged(sIdx);
                },

                confirmRemoveVehicle(sIdx, vIdx) {
                    this.pending = { sIdx, vIdx };
                    this.showConfirm = true;
                },

                cancelRemove() {
                    this.showConfirm = false;
                    this.pending = { sIdx: null, vIdx: null };
                },

                applyRemove() {
                    const { sIdx, vIdx } = this.pending || {};
                    if (sIdx === null || vIdx === null) return;
                    const seg = this.segments[sIdx];
                    if (!seg) return;
                    if (seg.rows.length <= 1) {
                        seg.rows[0].vehicle_id = '';
                    } else {
                        seg.rows.splice(vIdx, 1);
                        if (seg.rows.length < 1) seg.rows = [{ vehicle_id: '' }];
                    }
                    this.showConfirm = false;
                    this.pending = { sIdx: null, vIdx: null };
                    this.onVehiclesChanged(sIdx);
                },

                async onVehiclesChanged(sIdx) {
                    void this.tick;
                    // Changing required vehicle count can change which slots are eligible.
                    if (!this.selectedDate) return;
                    // Do not wipe departure picks: onArrivalChange() revalidates against new capacity.
                    await this.onDateChange({ resetDepartures: false });
                },

                get canSubmit() {
                    void this.tick;
                    const date = document.getElementById('reservation_date')?.value || '';
                    if (!date) return false;

                    if (!this.segments || this.segments.length < 1 || this.segments.length > 5) return false;
                    for (const seg of this.segments) {
                        if (!String(seg.drop || '') || !String(seg.pick || '')) return false;
                        const ids = (seg.rows || []).map(r => String(r.vehicle_id || '')).filter(v => v !== '');
                        if (ids.length < 1) return false;
                    }

                    const docs = document.getElementById('documents');
                    if (!docs || !docs.files || docs.files.length < 1) return false;
                    const privacy = document.querySelector('input[name="accept_privacy"]');
                    if (!privacy || !privacy.checked) return false;
                    return true;
                },
            }
        }
    </script>
</x-app-layout>

