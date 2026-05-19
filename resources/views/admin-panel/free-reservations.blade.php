<x-admin-panel-layout :page-title="$pageTitle ?? 'Besplatne rezervacije'" :nav-active="$navActive ?? 'free-reservations'">
    @php
        $minDate = now()->toDateString();
        $maxDate = now()->addDays(90)->toDateString();
        $locale = 'cg';
        $ui = fn (string $key) => \App\Support\UiText::t('reservation', $key);
    @endphp

    <div class="space-y-6 max-w-7xl">
        <div class="space-y-1">
            <h1 class="text-lg font-semibold text-gray-900">Besplatna rezervacija</h1>
            <p class="text-sm text-gray-600">Kreiranje besplatne rezervacije za gosta (bez plaćanja). Sva polja i pravila termina kao na javnoj formi; jezik interfejsa i potvrde: crnogorski.</p>
        </div>

        @if (session('status'))
            <div class="rounded-md bg-red-50 p-4 text-sm text-red-900">{{ session('status') }}</div>
        @endif
        @if (session('error'))
            <div class="rounded-md bg-red-50 p-4 text-sm text-red-800">{{ session('error') }}</div>
        @endif

        <form method="GET" action="{{ route('panel_admin.free-reservations', [], false) }}" class="space-y-4" id="adminFreeStepForm">
            <div>
                <x-input-label for="reservation_date" :value="$ui('date')" />
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
                <x-input-label for="drop_off_time_slot_id" :value="$ui('arrival_time')" />
                <select
                    id="drop_off_time_slot_id"
                    name="drop_off_time_slot_id"
                    class="mt-1 block w-full rounded-md border-red-200 shadow-sm focus:border-red-500 focus:ring-red-500"
                    @disabled(empty($selected_date))
                >
                    <option value="">{{ $ui('select_time_slot') }}</option>
                    @foreach (($arrival_slots ?? []) as $s)
                        <option value="{{ $s['id'] }}" @selected((int)($arrival_id ?? 0) === (int)$s['id']) @disabled((bool)$s['disabled'])>
                            {{ $s['label'] }}
                        </option>
                    @endforeach
                </select>
                <x-input-error class="mt-2" :messages="$errors->get('drop_off_time_slot_id')" />
            </div>

            <div>
                <x-input-label for="pick_up_time_slot_id" :value="$ui('departure_time')" />
                <select
                    id="pick_up_time_slot_id"
                    name="pick_up_time_slot_id"
                    class="mt-1 block w-full rounded-md border-red-200 shadow-sm focus:border-red-500 focus:ring-red-500"
                    @disabled($departure_disabled ?? true)
                >
                    <option value="">{{ $ui('select_time_slot') }}</option>
                    @foreach (($departure_slots ?? []) as $s)
                        <option value="{{ $s['id'] }}" @selected((int)($departure_id ?? 0) === (int)$s['id']) @disabled((bool)$s['disabled'])>
                            {{ $s['label'] }}
                        </option>
                    @endforeach
                </select>
                <p class="mt-1 text-xs text-gray-500">{{ $ui('departure_disabled_hint') }}</p>
                <x-input-error class="mt-2" :messages="$errors->get('pick_up_time_slot_id')" />
            </div>

            <div>
                <x-input-label for="vehicle_type_id_step" :value="$ui('vehicle_category')" />
                <select
                    id="vehicle_type_id_step"
                    name="vehicle_type_id"
                    class="mt-1 block w-full rounded-md border-red-200 shadow-sm focus:border-red-500 focus:ring-red-500"
                    @disabled(empty($selected_date) || empty($arrival_id) || empty($departure_id))
                >
                    <option value="">{{ $ui('select_vehicle_category') }}</option>
                    @foreach (($vehicle_types ?? []) as $vt)
                        <option value="{{ $vt->id }}" @selected((int) old('vehicle_type_id', request('vehicle_type_id')) === (int) $vt->id)>
                            {{ $vt->formatLabel($locale, 'EUR') }}
                        </option>
                    @endforeach
                </select>
                <x-input-error class="mt-2" :messages="$errors->get('vehicle_type_id')" />
            </div>
        </form>

        <form method="POST" action="{{ route('panel_admin.free-reservations.store', [], false) }}" class="space-y-4 bg-white shadow rounded-lg p-5 border border-red-100">
            @csrf

            <input type="hidden" name="reservation_date" value="{{ $selected_date ?? '' }}">
            <input type="hidden" name="drop_off_time_slot_id" value="{{ $arrival_id ?? '' }}">
            <input type="hidden" name="pick_up_time_slot_id" value="{{ $departure_id ?? '' }}">
            <input type="hidden" name="vehicle_type_id" value="{{ old('vehicle_type_id', request('vehicle_type_id')) }}">

            <div>
                <x-input-label for="name" :value="$ui('name')" />
                <x-text-input id="name" name="name" type="text" class="mt-1 block w-full" :value="old('name', request('name'))" required />
                <x-input-error class="mt-2" :messages="$errors->get('name')" />
            </div>

            <div>
                <x-input-label for="country" :value="$ui('country')" />
                <select id="country" name="country" class="mt-1 block w-full rounded-md border-red-200 shadow-sm focus:border-red-500 focus:ring-red-500" required>
                    <option value="">{{ $ui('select_country') }}</option>
                    @foreach (($countries ?? []) as $code => $labels)
                        @php
                            $label = is_array($labels) ? ($labels[$locale] ?? ($labels['en'] ?? $code)) : (string) $labels;
                        @endphp
                        <option value="{{ $code }}" @selected(old('country', request('country')) === $code)>{{ $label }}</option>
                    @endforeach
                </select>
                <x-input-error class="mt-2" :messages="$errors->get('country')" />
            </div>

            <div>
                <x-input-label for="license_plate" :value="$ui('registration_plates')" />
                <x-text-input
                    id="license_plate"
                    name="license_plate"
                    type="text"
                    class="mt-1 block w-full"
                    :value="old('license_plate', request('license_plate'))"
                    required
                    autocomplete="off"
                    inputmode="latin"
                    pattern="[A-Z0-9]+"
                />
                <x-input-error class="mt-2" :messages="$errors->get('license_plate')" />
            </div>

            <div>
                <x-input-label for="email" :value="$ui('email')" />
                <x-text-input id="email" name="email" type="email" class="mt-1 block w-full" :value="old('email', request('email'))" required />
                <x-input-error class="mt-2" :messages="$errors->get('email')" />
            </div>

            <x-primary-button type="submit" id="adminFreeSubmitBtn" disabled>
                Napravi besplatnu rezervaciju
            </x-primary-button>
        </form>

        {{-- Step 2: pregled pristiglih zahtjeva (read-only). --}}
        <div class="pt-2 space-y-3">
            <div class="space-y-1">
                <h2 class="text-lg font-semibold text-gray-900">Pristigli FZBR zahtjevi</h2>
                <p class="text-sm text-gray-600">Prikazani su samo aktivni zahtjevi (submitted/updated).</p>
            </div>

            @php
                /** @var \Illuminate\Support\Collection<int, \App\Models\FreeReservationRequest> $freeReservationRequests */
                $freeReservationRequests = $freeReservationRequests ?? collect();
            @endphp

            @forelse ($freeReservationRequests as $req)
                <div class="bg-white shadow rounded-lg p-5 border border-red-100 space-y-3">
                    @if (! (bool) ($req->can_fulfill ?? false))
                        <div class="rounded-md bg-red-50 p-3 text-sm text-red-900 border border-red-200">
                            Za traženi datum i termine nema dovoljno slobodnih kapaciteta za ovaj zahtjev.
                        </div>
                    @endif

                    <div class="flex items-start justify-between gap-4">
                        <div class="space-y-1">
                            <div class="text-base font-semibold text-gray-900">{{ $req->institution_name }}</div>
                            <div class="text-sm text-gray-700">{{ $req->institution_email }}</div>
                        </div>
                        <div class="text-xs text-gray-600 text-right">
                            <div><span class="font-semibold">Status:</span> {{ $req->status }}</div>
                            <div><span class="font-semibold">Locale:</span> {{ $req->locale }}</div>
                            <div><span class="font-semibold">Podnijeto:</span> {{ $req->created_at?->format('d.m.Y. H:i') }}</div>
                        </div>
                    </div>

                    <div class="grid grid-cols-1 sm:grid-cols-2 gap-3 text-sm">
                        <div>
                            <div class="text-xs font-medium text-gray-500">Država</div>
                            <div class="mt-1 text-gray-900">{{ $req->country }}</div>
                        </div>
                        <div>
                            <div class="text-xs font-medium text-gray-500">Datum</div>
                            <div class="mt-1 text-gray-900">{{ $req->reservation_date?->format('d.m.Y.') }}</div>
                        </div>
                    </div>

                    @php
                        $totalVehicles = (int) ($req->segments?->sum(fn ($s) => (int) $s->vehicles?->count()) ?? 0);
                    @endphp
                    <div class="text-sm space-y-3">
                        <div class="text-xs font-medium text-gray-500">Segmenti ({{ $req->segments->count() }}) / Vozila ukupno ({{ $totalVehicles }})</div>
                        <div class="space-y-3">
                            @foreach ($req->segments as $i => $seg)
                                @php
                                    $segVehicles = $seg->vehicles ?? collect();
                                @endphp
                                <div class="rounded-md border border-red-100 p-3 bg-red-50 space-y-2">
                                    <div class="flex flex-wrap items-center justify-between gap-2">
                                        <div class="font-semibold text-gray-900">Dolazak i odlazak {{ $i + 1 }}</div>
                                        <div class="text-xs text-gray-600">
                                            <span class="font-semibold">Vozila:</span> {{ $segVehicles->count() }}
                                        </div>
                                    </div>
                                    <div class="grid grid-cols-1 sm:grid-cols-2 gap-2 text-sm">
                                        <div>
                                            <div class="text-xs font-medium text-gray-500">Vrijeme dolaska</div>
                                            <div class="mt-1 text-gray-900">{{ $seg->dropOffTimeSlot?->time_slot ?? '—' }}</div>
                                        </div>
                                        <div>
                                            <div class="text-xs font-medium text-gray-500">Vrijeme odlaska</div>
                                            <div class="mt-1 text-gray-900">{{ $seg->pickUpTimeSlot?->time_slot ?? '—' }}</div>
                                        </div>
                                    </div>
                                    <div class="space-y-1">
                                        @foreach ($segVehicles as $v)
                                            @php
                                                $vtName = $v->vehicle_type_label
                                                    ?: ($v->vehicleType?->getTranslatedName('cg') ?: ('#'.$v->vehicle_type_id));
                                                $vtDesc = trim((string) ($v->vehicleType?->getTranslatedDescription('cg') ?? ''));
                                            @endphp
                                            <div class="flex items-start justify-between gap-3">
                                                <div class="font-semibold text-gray-900">{{ $v->license_plate }}</div>
                                                <div class="text-gray-700 text-right">
                                                    {{ $vtName }}@if ($vtDesc !== '') ({{ $vtDesc }})@endif
                                                </div>
                                            </div>
                                        @endforeach
                                    </div>

                                    @php
                                        $segAvail = collect($req->segments_availability ?? [])->firstWhere('segment_id', (int) $seg->id);
                                    @endphp
                                    @if (is_array($segAvail))
                                        <div class="text-xs text-gray-700 pt-1">
                                            <span class="font-semibold">Availability:</span>
                                            @if (($segAvail['can_fulfill'] ?? false))
                                                OK
                                            @else
                                                Nema kapaciteta
                                            @endif
                                            (required: {{ (int) ($segAvail['required'] ?? 0) }}, min_available: {{ $segAvail['min_available'] ?? '—' }})
                                        </div>
                                    @endif
                                </div>
                            @endforeach
                        </div>
                    </div>

                    <div class="text-sm">
                        <div class="text-xs font-medium text-gray-500">Dokumenta ({{ $req->attachments->count() }})</div>
                        @if ($req->attachments->isEmpty())
                            <div class="mt-1 text-gray-700">—</div>
                        @else
                            <ul class="mt-2 space-y-1">
                                @foreach ($req->attachments as $a)
                                    <li class="flex flex-wrap items-center justify-between gap-2">
                                        <div class="text-gray-900">
                                            {{ $a->original_name }}
                                            <span class="text-xs text-gray-500">({{ $a->mime_type ?: '—' }}, {{ number_format(((int) $a->size_bytes) / 1024, 0) }} KB)</span>
                                        </div>
                                        <a
                                            href="{{ route('panel_admin.free-reservation-requests.attachments.preview', ['freeReservationRequest' => $req->id, 'attachment' => $a->id], false) }}"
                                            target="_blank"
                                            rel="noopener"
                                            class="text-xs font-semibold uppercase tracking-widest text-red-700 hover:text-red-900 underline"
                                        >
                                            Pregledaj
                                        </a>
                                    </li>
                                @endforeach
                            </ul>
                        @endif
                    </div>

                    <div class="pt-2 flex flex-wrap gap-2">
                        <form method="POST" action="{{ route('panel_admin.free-reservation-requests.fulfill', ['freeReservationRequest' => $req->id], false) }}">
                            @csrf
                            <input type="hidden" name="confirm" value="1">
                            <button
                                type="submit"
                                class="inline-flex items-center justify-center rounded-md bg-red-700 px-3 py-2 text-xs font-semibold uppercase tracking-widest text-white hover:bg-red-800 disabled:opacity-50 disabled:cursor-not-allowed"
                                @disabled(! (bool) ($req->can_fulfill ?? false))
                                onclick="return confirm('Da li si siguran da želiš da napraviš ovu/e admin free rezervaciju/e?');"
                            >
                                Napravi besplatnu/e rezervaciju/e
                            </button>
                        </form>

                        <details class="w-full">
                            <summary class="inline-flex cursor-pointer select-none items-center justify-center rounded-md border border-red-200 bg-white px-3 py-2 text-xs font-semibold uppercase tracking-widest text-gray-800 hover:bg-red-50">
                                Izmijeni zahtjev
                            </summary>
                            <div class="mt-3 rounded-md border border-red-100 p-3 bg-red-50 space-y-2">
                                <form method="POST" action="{{ route('panel_admin.free-reservation-requests.update', ['freeReservationRequest' => $req->id], false) }}" class="grid grid-cols-1 sm:grid-cols-2 gap-3 items-end">
                                    @csrf
                                    @method('PUT')
                                    <div>
                                        <label class="block text-xs font-medium text-gray-600">Datum</label>
                                        <input type="date" name="reservation_date" value="{{ $req->reservation_date?->toDateString() }}" min="{{ now()->toDateString() }}" max="{{ now()->addDays(90)->toDateString() }}" class="mt-1 block w-full rounded-md border-red-200 shadow-sm focus:border-red-500 focus:ring-red-500" required>
                                    </div>
                                    <div class="sm:col-span-2">
                                        <div class="text-xs font-medium text-gray-600">Segmenti</div>
                                        <div class="mt-2 space-y-2">
                                            @foreach ($req->segments as $i => $seg)
                                                <div class="grid grid-cols-1 sm:grid-cols-2 gap-2">
                                                    <input type="hidden" name="segments[{{ $i }}][id]" value="{{ $seg->id }}">
                                                    <div>
                                                        <label class="block text-xs font-medium text-gray-600">Dolazak {{ $i + 1 }}</label>
                                                        <select name="segments[{{ $i }}][drop_off_time_slot_id]" class="mt-1 block w-full rounded-md border-red-200 shadow-sm focus:border-red-500 focus:ring-red-500" required>
                                                            @foreach (\App\Models\ListOfTimeSlot::query()->orderBy('id')->get() as $s)
                                                                <option value="{{ $s->id }}" @selected((int)$seg->drop_off_time_slot_id === (int)$s->id)>{{ $s->time_slot }}</option>
                                                            @endforeach
                                                        </select>
                                                    </div>
                                                    <div>
                                                        <label class="block text-xs font-medium text-gray-600">Odlazak {{ $i + 1 }}</label>
                                                        <select name="segments[{{ $i }}][pick_up_time_slot_id]" class="mt-1 block w-full rounded-md border-red-200 shadow-sm focus:border-red-500 focus:ring-red-500" required>
                                                            @foreach (\App\Models\ListOfTimeSlot::query()->orderBy('id')->get() as $s)
                                                                <option value="{{ $s->id }}" @selected((int)$seg->pick_up_time_slot_id === (int)$s->id)>{{ $s->time_slot }}</option>
                                                            @endforeach
                                                        </select>
                                                    </div>
                                                </div>
                                            @endforeach
                                        </div>
                                    </div>
                                    <div class="flex flex-wrap gap-2 justify-end sm:col-span-2">
                                        <button
                                            type="button"
                                            class="inline-flex items-center justify-center rounded-md border border-red-200 bg-white px-3 py-2 text-xs font-semibold uppercase tracking-widest text-gray-800 hover:bg-red-50"
                                            onclick="this.closest('details').open = false"
                                        >
                                            Otkaži
                                        </button>
                                        <button type="submit" class="inline-flex items-center justify-center rounded-md bg-red-700 px-3 py-2 text-xs font-semibold uppercase tracking-widest text-white hover:bg-red-800">Izmijeni</button>
                                    </div>
                                </form>
                            </div>
                        </details>

                        <form method="POST" action="{{ route('panel_admin.free-reservation-requests.reject', ['freeReservationRequest' => $req->id], false) }}">
                            @csrf
                            @method('DELETE')
                            <input type="hidden" name="confirm" value="1">
                            <button
                                type="submit"
                                class="inline-flex items-center justify-center rounded-md bg-red-600 px-3 py-2 text-xs font-semibold uppercase tracking-widest text-white hover:bg-red-700"
                                onclick="return confirm('Da li si siguran da želiš da odbaciš ovaj zahtjev?');"
                            >
                                Odbaci zahtjev
                            </button>
                        </form>
                    </div>
                </div>
            @empty
                <div class="rounded-md bg-red-50 p-4 text-sm text-gray-700 border border-red-100">
                    Trenutno nema aktivnih zahtjeva.
                </div>
            @endforelse
        </div>

        @php
            $fzbrReview = $fzbrReview ?? 'approved';
            $fzbrDateFrom = $fzbrDateFrom ?? now()->timezone('Europe/Podgorica')->toDateString();
            $fzbrDateTo = $fzbrDateTo ?? now()->timezone('Europe/Podgorica')->toDateString();
            $fzbrReviewRequests = $fzbrReviewRequests ?? collect();
            $fzbrStatusLabel = static fn (string $s) => match ($s) {
                \App\Models\FreeReservationRequest::STATUS_FULFILLED => 'fulfilled',
                \App\Models\FreeReservationRequest::STATUS_REJECTED => 'rejected',
                default => $s,
            };
        @endphp

        <div class="border-t border-red-100 pt-8 mt-8 space-y-4">
            <div>
                <h2 class="text-lg font-semibold text-gray-900">Pregled besplatnih rezervacija po FZBR</h2>
                <p class="text-sm text-gray-600 mt-1">Zahtjevi u terminalnom statusu; filtar po <code class="text-xs bg-red-50 px-1 rounded">updated_at</code> (Europe/Podgorica). Dokumenti: lokalno ili privremeni restore sa MEGA (bez javnog linka).</p>
            </div>

            @if ($errors->has('fzbr_date_from') || $errors->has('fzbr_date_to'))
                <div class="rounded-md bg-red-50 p-4 text-sm text-red-800 space-y-1">
                    @foreach (['fzbr_date_from', 'fzbr_date_to'] as $f)
                        @foreach ($errors->get($f) as $err)
                            <div>{{ $err }}</div>
                        @endforeach
                    @endforeach
                </div>
            @endif

            <form method="get" action="{{ route('panel_admin.free-reservations', [], false) }}" class="bg-white shadow rounded-lg p-5 border border-red-100 space-y-4">
                <fieldset class="space-y-2">
                    <legend class="text-sm font-medium text-gray-700">Vrsta pregleda</legend>
                    <div class="flex flex-wrap gap-4 text-sm">
                        <label class="inline-flex items-center gap-2 cursor-pointer">
                            <input type="radio" name="fzbr_review" value="approved" @checked(old('fzbr_review', $fzbrReview) === 'approved') class="rounded-full border-red-200 text-red-600 shadow-sm" />
                            <span>Odobreni</span>
                        </label>
                        <label class="inline-flex items-center gap-2 cursor-pointer">
                            <input type="radio" name="fzbr_review" value="rejected" @checked(old('fzbr_review', $fzbrReview) === 'rejected') class="rounded-full border-red-200 text-red-600 shadow-sm" />
                            <span>Odbijeni</span>
                        </label>
                    </div>
                </fieldset>
                <div class="grid grid-cols-1 md:grid-cols-3 gap-4 items-end">
                    <div>
                        <x-input-label for="fzbr_date_from" value="Datum od" />
                        <input type="date" id="fzbr_date_from" name="fzbr_date_from"
                               value="{{ old('fzbr_date_from', $fzbrDateFrom) }}"
                               class="mt-1 block w-full rounded-md border-red-200 shadow-sm" />
                    </div>
                    <div>
                        <x-input-label for="fzbr_date_to" value="Datum do" />
                        <input type="date" id="fzbr_date_to" name="fzbr_date_to"
                               value="{{ old('fzbr_date_to', $fzbrDateTo) }}"
                               class="mt-1 block w-full rounded-md border-red-200 shadow-sm" />
                    </div>
                    <div class="flex gap-2 justify-start md:justify-end">
                        <button type="submit" class="inline-flex items-center px-4 py-2 bg-red-700 border border-transparent rounded-md font-semibold text-xs text-white uppercase tracking-widest hover:bg-red-800">
                            FILTRIRAJ
                        </button>
                    </div>
                </div>
            </form>

            <div class="bg-white shadow rounded-lg border border-red-100 overflow-x-auto">
                <table class="min-w-full divide-y divide-gray-200 text-sm">
                    <thead class="bg-red-50">
                        <tr>
                            <th class="px-3 py-2 text-left font-semibold text-gray-700">id</th>
                            <th class="px-3 py-2 text-left font-semibold text-gray-700">status</th>
                            <th class="px-3 py-2 text-left font-semibold text-gray-700 min-w-[14rem] sm:min-w-[18rem] w-[22%]">Agencija / ustanova</th>
                            <th class="px-3 py-2 text-left font-semibold text-gray-700 min-w-[14rem] sm:min-w-[20rem] w-[24%]">Email</th>
                            <th class="px-3 py-2 text-left font-semibold text-gray-700">reservation_date</th>
                            <th class="px-3 py-2 text-left font-semibold text-gray-700">Dolazak (slot)</th>
                            <th class="px-3 py-2 text-left font-semibold text-gray-700">Odlazak (slot)</th>
                            <th class="px-3 py-2 text-left font-semibold text-gray-700">Tablice (vozila)</th>
                            <th class="px-3 py-2 text-left font-semibold text-gray-700">Prilozi</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-gray-100">
                        @forelse ($fzbrReviewRequests as $r)
                            @php
                                $firstSeg = $r->segments->first();
                                $dropLabel = $firstSeg?->dropOffTimeSlot?->time_slot ?? $r->dropOffTimeSlot?->time_slot ?? '—';
                                $pickLabel = $firstSeg?->pickUpTimeSlot?->time_slot ?? $r->pickUpTimeSlot?->time_slot ?? '—';
                                $plates = $r->segments->flatMap(fn ($s) => $s->vehicles->pluck('license_plate'))->filter()->unique()->implode(', ');
                                $agencyLine = $r->user?->name ? ($r->user->name.' / '.$r->institution_name) : $r->institution_name;
                                $emailLine = $r->user?->email ? ($r->user->email.' / '.$r->institution_email) : $r->institution_email;
                            @endphp
                            <tr class="hover:bg-red-50">
                                <td class="px-3 py-2 whitespace-nowrap text-gray-900">{{ $r->id }}</td>
                                <td class="px-3 py-2 whitespace-nowrap text-gray-900">{{ $fzbrStatusLabel($r->status) }}</td>
                                <td class="px-3 py-2 text-gray-900 min-w-[14rem] sm:min-w-[18rem] align-top">{{ $agencyLine }}</td>
                                <td class="px-3 py-2 text-gray-700 align-top min-w-[14rem] sm:min-w-[20rem] break-words">
                                    {{ $emailLine }}
                                </td>
                                <td class="px-3 py-2 whitespace-nowrap text-gray-900">{{ $r->reservation_date?->format('Y-m-d') ?? '—' }}</td>
                                <td class="px-3 py-2 text-gray-800">{{ $dropLabel }}</td>
                                <td class="px-3 py-2 text-gray-800">{{ $pickLabel }}</td>
                                <td class="px-3 py-2 text-gray-800">{{ $plates !== '' ? $plates : '—' }}</td>
                                <td class="px-3 py-2 whitespace-nowrap">
                                    @forelse ($r->attachments as $att)
                                        <div class="mb-1">
                                            <a href="{{ route('panel_admin.fzbr-attachments.preview', ['freeReservationRequestAttachment' => $att->id], false) }}"
                                               target="_blank" rel="noopener noreferrer"
                                               class="text-red-600 hover:text-red-800 underline text-xs"
                                               title="{{ $att->original_name }}">
                                                Dokument
                                                <span class="text-gray-500">({{ $att->mime_type ?: 'fajl' }})</span>
                                            </a>
                                        </div>
                                    @empty
                                        <span class="text-gray-400">—</span>
                                    @endforelse
                                </td>
                            </tr>
                        @empty
                            <tr>
                                <td colspan="9" class="px-3 py-6 text-center text-gray-500">Nema zahtjeva u izabranom periodu i vrsti pregleda.</td>
                            </tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        </div>
    </div>

    <script>
        (function () {
            const form = document.getElementById('adminFreeStepForm');
            if (!form) return;
            const date = document.getElementById('reservation_date');
            const arrival = document.getElementById('drop_off_time_slot_id');
            const departure = document.getElementById('pick_up_time_slot_id');
            const vehicleType = document.getElementById('vehicle_type_id_step');
            if (date) date.addEventListener('change', () => form.submit());
            if (arrival) arrival.addEventListener('change', () => form.submit());
            if (departure) departure.addEventListener('change', () => form.submit());
            if (vehicleType) vehicleType.addEventListener('change', () => form.submit());

            const submitBtn = document.getElementById('adminFreeSubmitBtn');
            const postForm = submitBtn ? submitBtn.closest('form') : null;
            const lp = document.getElementById('license_plate');
            if (lp) {
                lp.addEventListener('input', () => {
                    const v = (lp.value || '').toUpperCase().replace(/[^A-Z0-9]/g, '');
                    if (lp.value !== v) lp.value = v;
                });
            }

            function computePostValid() {
                if (!postForm) return false;
                const required = Array.from(postForm.querySelectorAll('[required]'));
                for (const el of required) {
                    if (!el.value || String(el.value).trim() === '') return false;
                    if (el.getAttribute('pattern')) {
                        try {
                            const re = new RegExp('^' + el.getAttribute('pattern') + '$');
                            if (!re.test(el.value)) return false;
                        } catch (e) {}
                    }
                }
                const vt = postForm.querySelector('input[name="vehicle_type_id"]');
                const d = postForm.querySelector('input[name="reservation_date"]');
                const a = postForm.querySelector('input[name="drop_off_time_slot_id"]');
                const p = postForm.querySelector('input[name="pick_up_time_slot_id"]');
                return !!(vt && vt.value && d && d.value && a && a.value && p && p.value);
            }

            function refresh() {
                if (!submitBtn) return;
                submitBtn.disabled = !computePostValid();
            }

            if (postForm) {
                postForm.addEventListener('input', refresh);
                postForm.addEventListener('change', refresh);
                refresh();
            }
        })();
    </script>
</x-admin-panel-layout>
