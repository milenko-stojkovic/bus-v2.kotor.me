<x-guest-layout>
    @php
        $locale = app()->getLocale();
        $ui = fn (string $key, ?string $fallback = null) => \App\Support\UiText::t('free_request', $key, $fallback);
        $landingUi = fn (string $key, ?string $fallback = null) => \App\Support\UiText::t('landing', $key, $fallback);
        $minDate = now()->toDateString();
        $maxDate = now()->addDays(90)->toDateString();

        $vehicleLabel = function (\App\Models\VehicleType $vt) use ($locale): string {
            $name = $vt->getTranslatedName($locale);
            $desc = trim((string) ($vt->getTranslatedDescription($locale) ?? ''));
            $label = $name !== '' ? $name : ('#'.$vt->id);
            if ($desc !== '') {
                $label .= ' ('.$desc.')';
            }
            return $label;
        };

        $vehiclesOld = old('vehicles');
        if (!is_array($vehiclesOld) || $vehiclesOld === []) {
            $vehiclesOld = [['license_plate' => '', 'vehicle_type_id' => '']];
        }
    @endphp

    <div class="space-y-6">
        <div class="space-y-1">
            <h1 class="text-lg font-semibold">{{ $ui('title', 'Forma za besplatne rezervacije') }}</h1>
            @php
                $legalTitle = $ui('legal_title', $locale === 'cg' ? 'Ko može podnijeti zahtjev?' : 'Who can submit a request?');
                $legalBody = $ui('legal_body', '');
                $legalNote = $ui('legal_note', '');
                $legalRef = $ui('legal_reference', '');
                $lines = preg_split("/\r?\n/", (string) $legalBody) ?: [];
                $lines = array_values(array_filter(array_map(fn ($l) => trim((string) $l), $lines), fn ($l) => $l !== ''));
                $introLine = $lines[0] ?? '';
                $bulletLines = array_slice($lines, 1);
                $bulletLines = array_map(function (string $l): string {
                    return ltrim($l, "- \t");
                }, $bulletLines);
            @endphp
            <div class="text-sm text-gray-700 space-y-2">
                <div class="font-semibold text-gray-900">{{ $legalTitle }}</div>
                @if ($introLine !== '')
                    <p>{{ $introLine }}</p>
                @endif
                @if (!empty($bulletLines))
                    <ul class="list-disc pl-5 space-y-1">
                        @foreach ($bulletLines as $b)
                            <li>{{ $b }}</li>
                        @endforeach
                    </ul>
                @endif
                @if (trim((string) $legalNote) !== '')
                    <p>{{ $legalNote }}</p>
                @endif
                @if (trim((string) $legalRef) !== '')
                    <div class="text-xs text-gray-500">{{ $legalRef }}</div>
                @endif
            </div>
        </div>

        <div class="rounded-md bg-red-50 p-4 text-sm text-red-900">
            {{ $ui('intro_note', 'Popunjena forma ne znači da je rezervacija automatski napravljena. Podaci se upućuju administratorima koji će prvo da provjere autentičnost podataka, a potom da naprave rezervaciju u skladu sa Vašim željama i našim kapacitetima. Iz tog razloga Vas molimo da što ranije pošaljete zahtjev (popunite formu) kako bi izgledi da dobijete željene termine bili što veći. Sva polja su obavezna.') }}
        </div>

        @if ($errors->any())
            <div class="rounded-md bg-red-50 p-4 text-sm text-red-800 space-y-1">
                @foreach ($errors->all() as $err)
                    <div>{{ $err }}</div>
                @endforeach
            </div>
        @endif
        @if (session('error'))
            <div class="rounded-md bg-red-50 p-3 text-sm text-red-800">{{ session('error') }}</div>
        @endif

        <form method="GET" action="{{ route('free-request.create', [], false) }}" class="space-y-4" id="stepForm">
            <div>
                <x-input-label for="reservation_date" :value="$ui('date', $landingUi('date', 'Datum'))" />
                <input
                    id="reservation_date"
                    name="reservation_date"
                    type="date"
                    min="{{ $minDate }}"
                    max="{{ $maxDate }}"
                    value="{{ $selected_date ?? '' }}"
                    class="mt-1 block w-full rounded-md border-red-200 shadow-sm focus:border-red-500 focus:ring-red-500"
                />
                <x-input-error class="mt-2" :messages="$errors->get('reservation_date')" />
            </div>

            <div>
                <x-input-label for="drop_off_time_slot_id" :value="$ui('arrival_time', $landingUi('arrival_time', 'Vrijeme dolaska'))" />
                <select
                    id="drop_off_time_slot_id"
                    name="drop_off_time_slot_id"
                    class="mt-1 block w-full rounded-md border-red-200 shadow-sm focus:border-red-500 focus:ring-red-500"
                    @disabled(empty($selected_date))
                >
                    <option value="">{{ $ui('select_time_slot', 'Izaberite termin') }}</option>
                    @foreach (($arrival_slots ?? []) as $s)
                        <option value="{{ $s['id'] }}" @selected((int)($arrival_id ?? 0) === (int)$s['id']) @disabled((bool)$s['disabled'])>
                            {{ $s['label'] }}
                        </option>
                    @endforeach
                </select>
                <x-input-error class="mt-2" :messages="$errors->get('drop_off_time_slot_id')" />
            </div>

            <div>
                <x-input-label for="pick_up_time_slot_id" :value="$ui('departure_time', $landingUi('departure_time', 'Vrijeme odlaska'))" />
                <select
                    id="pick_up_time_slot_id"
                    name="pick_up_time_slot_id"
                    class="mt-1 block w-full rounded-md border-red-200 shadow-sm focus:border-red-500 focus:ring-red-500"
                    @disabled($departure_disabled ?? true)
                >
                    <option value="">{{ $ui('select_time_slot', 'Izaberite termin') }}</option>
                    @foreach (($departure_slots ?? []) as $s)
                        <option value="{{ $s['id'] }}" @selected((int)($departure_id ?? 0) === (int)$s['id']) @disabled((bool)$s['disabled'])>
                            {{ $s['label'] }}
                        </option>
                    @endforeach
                </select>
                <p class="mt-1 text-xs text-gray-500">{{ $ui('departure_disabled_hint', 'Vrijeme odlaska je dostupno nakon izbora dolaska.') }}</p>
                <x-input-error class="mt-2" :messages="$errors->get('pick_up_time_slot_id')" />
            </div>
        </form>

        <form method="POST" action="{{ route('free-request.store', [], false) }}" class="space-y-4" x-data="freeRequestForm()">
            @csrf

            <input type="hidden" name="reservation_date" id="post_reservation_date" value="{{ $selected_date ?? '' }}">
            <input type="hidden" name="drop_off_time_slot_id" id="post_drop_off_time_slot_id" value="{{ $arrival_id ?? '' }}">
            <input type="hidden" name="pick_up_time_slot_id" id="post_pick_up_time_slot_id" value="{{ $departure_id ?? '' }}">

            <div>
                <x-input-label for="institution_name" :value="$ui('institution_name', 'Naziv institucije/organizacije')" />
                <x-text-input id="institution_name" name="institution_name" type="text" class="mt-1 block w-full" :value="old('institution_name')" x-model="institution_name" required />
                <p class="mt-1 text-xs text-gray-500">{{ $ui('institution_name_hint', 'Ne naziv autoprevoznika/agencije') }}</p>
                <x-input-error class="mt-2" :messages="$errors->get('institution_name')" />
            </div>

            <div>
                <x-input-label for="country" :value="$ui('country', 'Država')" />
                <select id="country" name="country" x-model="country" class="mt-1 block w-full rounded-md border-red-200 shadow-sm focus:border-red-500 focus:ring-red-500" required>
                    <option value="">{{ $ui('select_country', 'Izaberite državu') }}</option>
                    @foreach (($countries ?? []) as $code => $labels)
                        @php
                            $label = is_array($labels) ? ($labels[$locale] ?? ($labels['en'] ?? $code)) : (string) $labels;
                        @endphp
                        <option value="{{ $code }}" @selected(old('country') === $code)>{{ $label }}</option>
                    @endforeach
                </select>
                <x-input-error class="mt-2" :messages="$errors->get('country')" />
            </div>

            <div>
                <x-input-label for="institution_email" :value="$ui('institution_email', 'Email institucije/organizacije')" />
                <x-text-input id="institution_email" name="institution_email" type="email" class="mt-1 block w-full" :value="old('institution_email')" x-model="institution_email" required />
                <p class="mt-1 text-xs text-gray-500">{{ $ui('institution_email_hint', 'Ne email autoprevoznika/agencije') }}</p>
                <x-input-error class="mt-2" :messages="$errors->get('institution_email')" />
            </div>

            <div>
                <x-input-label for="institution_phone" :value="$ui('institution_phone', 'Telefon institucije/organizacije')" />
                <x-text-input
                    id="institution_phone"
                    name="institution_phone"
                    type="text"
                    class="mt-1 block w-full"
                    :value="old('institution_phone')"
                    x-model="institution_phone"
                    required
                    autocomplete="off"
                    inputmode="tel"
                    placeholder="+382XXXXXXXX"
                    pattern="\\+\\d+"
                />
                <p class="mt-1 text-xs text-gray-500">{{ $ui('institution_phone_hint', 'Ne telefon autoprevoznika/agencije') }}</p>
                <x-input-error class="mt-2" :messages="$errors->get('institution_phone')" />
            </div>

            <div class="space-y-2">
                <div class="text-sm font-semibold text-gray-900">{{ $ui('vehicles_title', 'Vozila') }}</div>

                <template x-for="(row, idx) in vehicles" :key="idx">
                    <div class="grid grid-cols-1 md:grid-cols-12 gap-2 items-start">
                        <div class="md:col-span-6 min-w-0">
                            <x-input-label :value="$ui('license_plate', 'Registarska tablica')" />
                            <input
                                class="mt-1 block w-full rounded-md border-red-200 shadow-sm focus:border-red-500 focus:ring-red-500"
                                type="text"
                                :name="`vehicles[${idx}][license_plate]`"
                                x-model="row.license_plate"
                                required
                                autocomplete="off"
                                inputmode="latin"
                                pattern="[A-Z0-9]+"
                                @input="row.license_plate = normalizePlate(row.license_plate)"
                            />
                        </div>

                        <div class="md:col-span-4 min-w-0">
                            <x-input-label :value="$ui('vehicle_type', 'Kategorija vozila')" />
                            <select
                                class="mt-1 block w-full rounded-md border-red-200 shadow-sm focus:border-red-500 focus:ring-red-500"
                                :name="`vehicles[${idx}][vehicle_type_id]`"
                                x-model="row.vehicle_type_id"
                                required
                            >
                                <option value="">{{ $ui('select_vehicle_category', 'Izaberite kategoriju') }}</option>
                                @foreach (($vehicle_types ?? []) as $vt)
                                    <option value="{{ $vt->id }}">{{ $vehicleLabel($vt) }}</option>
                                @endforeach
                            </select>
                        </div>

                        <div class="md:col-span-2 flex gap-2 md:justify-end pt-6 min-w-0">
                            <button
                                type="button"
                                class="inline-flex items-center justify-center rounded-md bg-red-700 px-3 py-2 text-xs font-semibold uppercase tracking-widest text-white hover:bg-red-800 disabled:opacity-50 disabled:cursor-not-allowed"
                                x-show="idx === vehicles.length - 1"
                                :disabled="vehicles.length >= 9"
                                @click="addVehicle()"
                            >
                                {{ $ui('add_vehicle', 'Dodaj naredno vozilo') }}
                            </button>
                            <button
                                type="button"
                                class="inline-flex items-center justify-center rounded-md border border-red-200 bg-white px-3 py-2 text-xs font-semibold uppercase tracking-widest text-gray-800 hover:bg-red-50"
                                x-show="idx !== vehicles.length - 1"
                                @click="confirmRemove(idx)"
                            >
                                {{ $ui('remove_vehicle', 'Ukloni vozilo') }}
                            </button>
                        </div>
                    </div>
                </template>

                <div x-show="showConfirm" class="fixed inset-0 z-50 flex items-center justify-center">
                    <div class="absolute inset-0 bg-black/50" @click="cancelRemove()"></div>
                    <div class="relative w-[92%] max-w-md rounded-lg bg-white shadow-lg p-4 space-y-3">
                        <div class="font-semibold">{{ $ui('remove_vehicle_confirm', 'Da li si siguran da želite da uklonite to vozilo?') }}</div>
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
            </div>

            <div class="space-y-2">
                <label class="flex items-start gap-2 text-sm">
                    <input type="checkbox" name="accept_privacy" value="1" x-model="accept_privacy" class="mt-1 rounded border-red-200" {{ old('accept_privacy') ? 'checked' : '' }} required>
                    <span>{{ \App\Support\UiText::t('reservation', 'accept_privacy') }}</span>
                </label>
                <x-input-error class="mt-2" :messages="$errors->get('accept_privacy')" />
            </div>

            @php
                $finalNoticeTitleFallback = $locale === 'cg' ? 'Napomena:' : 'Note:';
                $finalNoticeBodyFallbackCg = "Unešena email adresa i broj telefona su ključni za identifikaciju podnosioca zahtjeva.\nBroj telefona mora pripadati odgovornom licu koje je upoznato sa zahtjevom.\n\nDokle god ne dođe do komunikacije sa administratorom, rezervacija neće biti napravljena.\nMolimo Vas da još jednom provjerite unešene podatke.\n\nSami snosite odgovornost za tačnost unešenih podataka.";
                $finalNoticeBodyFallbackEn = "The provided email address and phone number are essential for identifying the request submitter.\nThe phone number must belong to a responsible person familiar with the request.\n\nThe reservation will not be created until communication with the administrator is established.\nPlease double-check the entered information.\n\nYou are responsible for the accuracy of the submitted data.";
                $finalNoticeBodyFallback = $locale === 'cg' ? $finalNoticeBodyFallbackCg : $finalNoticeBodyFallbackEn;
                $finalNoticeTitle = $ui('final_notice_title', $finalNoticeTitleFallback);
                $finalNoticeBody = $ui('final_notice_body', $finalNoticeBodyFallback);
                $finalNoticeParagraphs = preg_split("/\r?\n\r?\n/", trim((string) $finalNoticeBody)) ?: [];
                $finalNoticeParagraphs = array_values(array_filter(array_map('trim', $finalNoticeParagraphs), fn ($p) => $p !== ''));
            @endphp
            <div class="rounded-md border border-red-100 bg-red-50 p-4 text-sm space-y-3 text-gray-700">
                <div class="font-semibold text-gray-900">{{ $finalNoticeTitle }}</div>
                @foreach ($finalNoticeParagraphs as $paragraph)
                    <p class="leading-relaxed">{!! nl2br(e($paragraph)) !!}</p>
                @endforeach
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

    <script>
        function freeRequestForm() {
            return {
                tick: 0,
                vehicles: @json($vehiclesOld),
                showConfirm: false,
                pendingRemoveIdx: null,
                institution_name: @json(old('institution_name', '')),
                country: @json(old('country', '')),
                institution_email: @json(old('institution_email', '')),
                institution_phone: @json(old('institution_phone', '')),
                accept_privacy: @json((bool) old('accept_privacy', false)),
                init() {
                    window.addEventListener('free-request-sync', () => {
                        this.tick++;
                    });

                    const form = document.querySelector('form[action="{{ route('free-request.store', [], false) }}"]');
                    if (form) {
                        form.addEventListener('input', () => { this.tick++; });
                        form.addEventListener('change', () => { this.tick++; });
                    }
                },

                normalizePlate(v) {
                    if (typeof v !== 'string') return '';
                    return v.toUpperCase().replace(/\s+/g, '').replace(/[^A-Z0-9]/g, '');
                },

                addVehicle() {
                    if (this.vehicles.length >= 9) return;
                    this.vehicles.push({ license_plate: '', vehicle_type_id: '' });
                    this.tick++;
                },

                confirmRemove(idx) {
                    if (this.vehicles.length <= 1) {
                        // last row can't be removed; user can clear inputs instead
                        return;
                    }
                    this.pendingRemoveIdx = idx;
                    this.showConfirm = true;
                    this.tick++;
                },

                cancelRemove() {
                    this.showConfirm = false;
                    this.pendingRemoveIdx = null;
                    this.tick++;
                },

                applyRemove() {
                    if (this.pendingRemoveIdx === null) return;
                    this.vehicles.splice(this.pendingRemoveIdx, 1);
                    if (this.vehicles.length < 1) {
                        this.vehicles = [{ license_plate: '', vehicle_type_id: '' }];
                    }
                    this.showConfirm = false;
                    this.pendingRemoveIdx = null;
                    this.tick++;
                },

                get canSubmit() {
                    // Touch tick so Alpine recomputes
                    void this.tick;
                    // reservation date/slots are hidden inputs (must be present)
                    const date = document.getElementById('post_reservation_date')?.value || '';
                    const drop = document.getElementById('post_drop_off_time_slot_id')?.value || '';
                    const pick = document.getElementById('post_pick_up_time_slot_id')?.value || '';
                    if (!date || !drop || !pick) return false;

                    if (!this.institution_name || String(this.institution_name).trim().length < 2) return false;
                    if (!this.country) return false;
                    if (!this.institution_email) return false;
                    if (!this.institution_phone) return false;
                    if (!this.accept_privacy) return false;

                    // vehicle rows must all be filled
                    if (!Array.isArray(this.vehicles) || this.vehicles.length < 1 || this.vehicles.length > 9) return false;
                    for (const row of this.vehicles) {
                        if (!row || !row.license_plate || !row.vehicle_type_id) return false;
                    }
                    return true;
                },
            }
        }

        (function () {
            const stepForm = document.getElementById('stepForm');
            if (!stepForm) return;
            const date = document.getElementById('reservation_date');
            const arrival = document.getElementById('drop_off_time_slot_id');
            const departure = document.getElementById('pick_up_time_slot_id');
            const postDate = document.getElementById('post_reservation_date');
            const postArrival = document.getElementById('post_drop_off_time_slot_id');
            const postDeparture = document.getElementById('post_pick_up_time_slot_id');

            function sync() {
                if (postDate && date) postDate.value = date.value || '';
                if (postArrival && arrival) postArrival.value = arrival.value || '';
                if (postDeparture && departure) postDeparture.value = departure.value || '';
                // trigger Alpine recompute if present
                window.dispatchEvent(new Event('free-request-sync'));
            }

            if (date) date.addEventListener('change', () => { sync(); stepForm.submit(); });
            if (arrival) arrival.addEventListener('change', () => { sync(); stepForm.submit(); });
            if (departure) departure.addEventListener('change', () => { sync(); stepForm.submit(); });

            // initial sync for cases when GET params already selected
            sync();
        })();
    </script>
</x-guest-layout>

