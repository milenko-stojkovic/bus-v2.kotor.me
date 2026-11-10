@php
    /** @var \Carbon\Carbon $capacityEffectiveFrom */
@endphp

<x-admin-panel-layout :page-title="$pageTitle ?? 'Podešavanja'" nav-active="settings">
    <div class="space-y-8" x-data="{
        capEditing: false,
        capOriginal: @js((string) $capacity),
        emailAddOpen: @json($errors->has('email')),
        incidentEmailAddOpen: @json($errors->has('limo_incident_email')),
        deleteOpen: false,
        deleteEmail: '',
        deleteUrl: '',
        priceDeleteOpen: false,
    }">
        <div>
            <h1 class="text-lg font-semibold text-gray-900">Podešavanja</h1>
            <p class="text-sm text-gray-600 mt-1">Kapacitet, liste email adresa i zakazana promjena cijena.</p>
        </div>

        @if (session('status'))
            <div class="rounded-md bg-red-50 p-4 text-sm text-red-900">{{ session('status') }}</div>
        @endif
        @if (session('error'))
            <div class="rounded-md bg-red-50 p-4 text-sm text-red-800">{{ session('error') }}</div>
        @endif
        @if ($errors->any())
            <div class="rounded-md bg-red-50 p-4 text-sm text-red-800 space-y-1">
                @foreach ($errors->all() as $err)
                    <div>{{ $err }}</div>
                @endforeach
            </div>
        @endif

        <section class="bg-white shadow rounded-lg p-6 border border-red-100">
            <div class="flex items-start justify-between gap-4 flex-wrap">
                <div>
                    <h2 class="text-base font-semibold text-gray-900">Kapacitet</h2>
                    <p class="text-sm text-gray-600 mt-1">Promena ne važi retroaktivno. Važi od {{ $capacityEffectiveFrom->format('d.m.Y.') }}.</p>
                </div>
            </div>

            <form method="POST" action="{{ route('panel_admin.settings.capacity.update', [], false) }}"
                  class="mt-4 flex flex-wrap items-end gap-3">
                @csrf
                @method('PUT')

                <div class="min-w-[240px]">
                    <x-input-label for="available_parking_slots" value="Kotor (Benovo)" />
                    <x-text-input
                        id="available_parking_slots"
                        name="available_parking_slots"
                        type="text"
                        class="mt-1 block w-full"
                        x-bind:readonly="!capEditing"
                        x-bind:class="capEditing ? '' : 'bg-red-50 text-gray-700'"
                        x-bind:value="capEditing ? (document.getElementById('available_parking_slots')?.value ?? capOriginal) : capOriginal"
                        value="{{ old('available_parking_slots', $capacity) }}"
                        inputmode="numeric"
                        pattern="\\d*"
                    />
                    <x-input-error class="mt-2" :messages="$errors->get('available_parking_slots')" />
                </div>

                <div class="flex flex-wrap gap-2 items-center">
                    <button type="button"
                            x-show="!capEditing"
                            class="inline-flex items-center px-4 py-2 border border-red-200 rounded-md font-semibold text-xs text-gray-700 uppercase tracking-widest hover:bg-red-50"
                            @click="capEditing = true; capOriginal = document.getElementById('available_parking_slots')?.value ?? capOriginal;">
                        Promjeni
                    </button>

                    <button type="submit"
                            x-show="capEditing"
                            class="inline-flex items-center px-4 py-2 bg-red-700 border border-transparent rounded-md font-semibold text-xs text-white uppercase tracking-widest hover:bg-red-800">
                        Primjeni
                    </button>
                    <button type="button"
                            x-show="capEditing"
                            class="inline-flex items-center px-4 py-2 border border-red-200 rounded-md font-semibold text-xs text-gray-700 uppercase tracking-widest hover:bg-red-50"
                            @click="
                                capEditing = false;
                                const el = document.getElementById('available_parking_slots');
                                if (el) el.value = capOriginal;
                            ">
                        Odkaži
                    </button>
                </div>
            </form>
        </section>

        <section class="bg-white shadow rounded-lg p-6 border border-red-100">
            <div class="flex items-start justify-between gap-4 flex-wrap">
                <div>
                    <h2 class="text-base font-semibold text-gray-900">Izvještaji - email adrese</h2>
                    <p class="text-sm text-gray-600 mt-1">Lista adresa na koje se šalju izveštaji. Dozvoljeno je da lista bude prazna.</p>
                </div>
                <button type="button"
                        id="settings-report-email-add"
                        class="inline-flex items-center px-4 py-2 bg-red-700 border border-transparent rounded-md font-semibold text-xs text-white uppercase tracking-widest hover:bg-red-800"
                        @click="emailAddOpen = true">
                    Dodaj email adresu
                </button>
            </div>

            <div class="mt-4 space-y-2">
                @forelse ($reportEmails as $re)
                    <div class="flex items-center justify-between gap-3 p-3 rounded border border-red-100">
                        <div class="text-sm text-gray-900">{{ $re->email }}</div>
                        <button type="button"
                            class="inline-flex items-center px-3 py-2 border border-red-300 rounded-md text-xs font-semibold text-red-800 uppercase tracking-widest hover:bg-red-50"
                            @click="
                                deleteOpen = true;
                                deleteEmail = @js($re->email);
                                deleteUrl = @js(route('panel_admin.settings.report-emails.destroy', $re, false));
                            ">
                            Obriši
                        </button>
                    </div>
                @empty
                    <p class="text-sm text-gray-600">Nema adresa.</p>
                @endforelse
            </div>

            <div id="settings-report-email-form-panel" class="mt-6 p-4 rounded border border-red-100 bg-red-50 @unless($errors->has('email')) hidden @endunless" :class="{ 'hidden': ! emailAddOpen }">
                <form id="settings-report-email-form" method="POST" action="{{ route('panel_admin.settings.report-emails.store', [], false) }}" class="flex flex-wrap gap-3 items-end">
                    @csrf
                    <div class="min-w-[260px] grow">
                        <x-input-label for="email" value="Nova email adresa" />
                        <x-text-input id="email" name="email" type="email" class="mt-1 block w-full" :value="old('email')" />
                        <x-input-error class="mt-2" :messages="$errors->get('email')" />
                    </div>
                    <div class="flex gap-2">
                        <button type="submit"
                            class="inline-flex items-center px-4 py-2 bg-red-700 border border-transparent rounded-md font-semibold text-xs text-white uppercase tracking-widest hover:bg-red-800">
                            Dodaj
                        </button>
                        <button type="button"
                            class="inline-flex items-center px-4 py-2 border border-red-200 rounded-md font-semibold text-xs text-gray-700 uppercase tracking-widest hover:bg-red-50"
                            @click="emailAddOpen = false;">
                            Odkaži
                        </button>
                    </div>
                </form>
            </div>
        </section>

        <section class="bg-white shadow rounded-lg p-6 border border-red-100">
            <div class="flex items-start justify-between gap-4 flex-wrap">
                <div>
                    <h2 class="text-base font-semibold text-gray-900">Incident - email adrese</h2>
                    <p class="text-sm text-gray-600 mt-1">Lista adresa na koje se šalju obavještenja o Limo incidentima. Dozvoljeno je da lista bude prazna.</p>
                </div>
                <button type="button"
                        id="settings-incident-email-add"
                        class="inline-flex items-center px-4 py-2 bg-red-700 border border-transparent rounded-md font-semibold text-xs text-white uppercase tracking-widest hover:bg-red-800"
                        x-on:click="incidentEmailAddOpen = true">
                    Dodaj email adresu
                </button>
            </div>

            <div class="mt-4 space-y-2">
                @forelse ($limoIncidentEmails as $re)
                    <div class="flex items-center justify-between gap-3 p-3 rounded border border-red-100">
                        <div class="text-sm text-gray-900">{{ $re->email }}</div>
                        <button type="button"
                            class="inline-flex items-center px-3 py-2 border border-red-300 rounded-md text-xs font-semibold text-red-800 uppercase tracking-widest hover:bg-red-50"
                            @click="
                                deleteOpen = true;
                                deleteEmail = @js($re->email);
                                deleteUrl = @js(route('panel_admin.settings.limo-incident-emails.destroy', $re, false));
                            ">
                            Obriši
                        </button>
                    </div>
                @empty
                    <p class="text-sm text-gray-600">Nema adresa za Limo incidente.</p>
                @endforelse
            </div>

            <div id="settings-incident-email-form-panel" class="mt-6 p-4 rounded border border-red-100 bg-red-50 @unless($errors->has('limo_incident_email')) hidden @endunless" :class="{ 'hidden': ! incidentEmailAddOpen }">
                <form id="settings-incident-email-form" method="POST" action="{{ route('panel_admin.settings.limo-incident-emails.store', [], false) }}" class="flex flex-wrap gap-3 items-end">
                    @csrf
                    <div class="min-w-[260px] grow">
                        <x-input-label for="limo_incident_email" value="Nova email adresa" />
                        <x-text-input id="limo_incident_email" name="limo_incident_email" type="email" class="mt-1 block w-full" :value="old('limo_incident_email')" />
                        <x-input-error class="mt-2" :messages="$errors->get('limo_incident_email')" />
                    </div>
                    <div class="flex gap-2">
                        <button type="submit"
                            class="inline-flex items-center px-4 py-2 bg-red-700 border border-transparent rounded-md font-semibold text-xs text-white uppercase tracking-widest hover:bg-red-800">
                            Dodaj
                        </button>
                        <button type="button"
                            class="inline-flex items-center px-4 py-2 border border-red-200 rounded-md font-semibold text-xs text-gray-700 uppercase tracking-widest hover:bg-red-50"
                            x-on:click="incidentEmailAddOpen = false;">
                            Odkaži
                        </button>
                    </div>
                </form>
            </div>
        </section>

        <section class="bg-white shadow rounded-lg p-6 border border-red-100">
            @php
                $scheduledPrices = [];
                if (($priceChangeEditing ?? false) && $pendingPriceChange) {
                    foreach ($pendingPriceChange->lines as $scheduledLine) {
                        $scheduledPrices[(int) $scheduledLine->vehicle_type_id] = (string) $scheduledLine->price_after;
                    }
                }
                $priceInputs = old('prices', $scheduledPrices);
            @endphp
            <div>
                <h2 class="text-base font-semibold text-gray-900">Promjena cijena</h2>
                <p class="text-sm text-gray-600 mt-1">Nova cijena važi od 00:00 izabranog datuma (Europe/Podgorica). Do tada ostaje trenutna cijena. Može postojati samo jedna zakazana promjena.</p>
            </div>

            @if ($pendingPriceChange && ! ($priceChangeEditing ?? false))
                <p class="mt-4 text-sm text-gray-700">Zakazana promjena već postoji. Za izmjenu koristite Izmijeni. Potvrdi ne pravi drugu promjenu.</p>
            @else
                <form method="POST"
                      action="{{ ($priceChangeEditing ?? false) ? route('panel_admin.settings.price-change.update', [], false) : route('panel_admin.settings.price-change.store', [], false) }}"
                      class="mt-4 space-y-4"
                      id="price-change-form">
                    @csrf
                    @if ($priceChangeEditing ?? false)
                        @method('PUT')
                    @endif

                    <div class="max-w-xs">
                        <x-input-label for="effective_on" value="Datum važenja" />
                        <x-text-input
                            id="effective_on"
                            name="effective_on"
                            type="date"
                            class="mt-1 block w-full"
                            min="{{ \Carbon\Carbon::now('Europe/Podgorica')->addDay()->toDateString() }}"
                            value="{{ old('effective_on', ($priceChangeEditing ?? false) ? $pendingPriceChange->effectiveAt()->toDateString() : '') }}"
                            required
                        />
                        <x-input-error class="mt-2" :messages="$errors->get('effective_on')" />
                    </div>

                    <div class="overflow-x-auto">
                        <table class="min-w-full text-sm">
                            <thead>
                                <tr class="text-left text-gray-600">
                                    <th class="py-2 pr-4 font-medium">Kategorija</th>
                                    <th class="py-2 pr-4 font-medium">Trenutna cijena</th>
                                    <th class="py-2 font-medium">Nova cijena</th>
                                </tr>
                            </thead>
                            <tbody>
                                @foreach ($priceChangeVehicleTypes as $vt)
                                    <tr class="border-t border-red-100">
                                        <td class="py-2 pr-4 text-gray-900">{{ $vt->formatLabel('cg', 'EUR') }}</td>
                                        <td class="py-2 pr-4 text-gray-700">{{ number_format((float) $vt->price, 2, '.', '') }} EUR</td>
                                        <td class="py-2">
                                            <x-text-input
                                                name="prices[{{ $vt->id }}]"
                                                type="text"
                                                inputmode="decimal"
                                                class="block w-32"
                                                value="{{ $priceInputs[$vt->id] ?? '' }}"
                                                placeholder="{{ number_format((float) $vt->price, 2, '.', '') }}"
                                            />
                                            <x-input-error class="mt-1" :messages="$errors->get('prices.'.$vt->id)" />
                                        </td>
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                    </div>
                    <x-input-error :messages="$errors->get('prices')" />
                    <x-input-error class="mt-2" :messages="$errors->get('price_change')" />

                    <div class="flex flex-wrap gap-2">
                        <button type="submit"
                            class="inline-flex items-center px-4 py-2 bg-red-700 border border-transparent rounded-md font-semibold text-xs text-white uppercase tracking-widest hover:bg-red-800">
                            Potvrdi
                        </button>
                        @if ($priceChangeEditing ?? false)
                            <a href="{{ route('panel_admin.settings', [], false) }}"
                               class="inline-flex items-center px-4 py-2 border border-red-200 rounded-md font-semibold text-xs text-gray-700 uppercase tracking-widest hover:bg-red-50">
                                Otkaži
                            </a>
                        @else
                            <button type="button"
                                class="inline-flex items-center px-4 py-2 border border-red-200 rounded-md font-semibold text-xs text-gray-700 uppercase tracking-widest hover:bg-red-50"
                                onclick="document.getElementById('price-change-form').reset();">
                                Otkaži
                            </button>
                        @endif
                    </div>
                </form>
            @endif

            @if ($pendingPriceChange)
                <div class="mt-6 rounded border border-red-100 p-4">
                    <div class="flex items-start justify-between gap-4 flex-wrap">
                        <div>
                            <h3 class="text-sm font-semibold text-gray-900">Zakazana promjena</h3>
                            <p class="text-sm text-gray-700 mt-1">Važi od {{ $pendingPriceChange->effectiveAt()->format('d.m.Y.') }} u 00:00. Status: Zakazano.</p>
                        </div>
                        <div class="flex gap-2">
                            <a href="{{ route('panel_admin.settings', ['price_change' => 'edit'], false) }}"
                               class="inline-flex items-center px-3 py-2 border border-red-200 rounded-md text-xs font-semibold text-gray-700 uppercase tracking-widest hover:bg-red-50">
                                Izmijeni
                            </a>
                            <button type="button"
                                class="inline-flex items-center px-3 py-2 border border-red-300 rounded-md text-xs font-semibold text-red-800 uppercase tracking-widest hover:bg-red-50"
                                @click="priceDeleteOpen = true">
                                Ukloni
                            </button>
                        </div>
                    </div>
                    <ul class="mt-3 space-y-1 text-sm text-gray-800">
                        @foreach ($pendingPriceChange->lines as $line)
                            <li>
                                {{ $line->vehicleType?->formatLabel('cg', 'EUR') ?? ('#'.$line->vehicle_type_id) }}:
                                {{ number_format((float) $line->price_before, 2, '.', '') }} EUR
                                → {{ number_format((float) $line->price_after, 2, '.', '') }} EUR
                            </li>
                        @endforeach
                    </ul>
                </div>
            @endif

            @if ($priceChangeHistory->isNotEmpty())
                <div class="mt-6">
                    <h3 class="text-sm font-semibold text-gray-900">Istorija cijena</h3>
                    <div class="mt-3 space-y-3">
                        @foreach ($priceChangeHistory as $historical)
                            <div class="rounded border border-red-100 p-3 text-sm">
                                <p class="text-gray-700">Važilo od {{ $historical->effectiveAt()->format('d.m.Y.') }} u 00:00. Status: Na snazi.</p>
                                <ul class="mt-2 space-y-1 text-gray-800">
                                    @foreach ($historical->lines as $line)
                                        <li>
                                            {{ $line->vehicleType?->formatLabel('cg', 'EUR') ?? ('#'.$line->vehicle_type_id) }}:
                                            {{ number_format((float) $line->price_before, 2, '.', '') }} EUR
                                            → {{ number_format((float) $line->price_after, 2, '.', '') }} EUR
                                        </li>
                                    @endforeach
                                </ul>
                            </div>
                        @endforeach
                    </div>
                </div>
            @endif
        </section>

        <div x-show="priceDeleteOpen" x-cloak class="fixed inset-0 z-50 flex items-center justify-center p-4">
            <div class="absolute inset-0 bg-black/50" @click="priceDeleteOpen = false"></div>
            <div class="relative bg-white rounded-lg shadow-xl max-w-md w-full p-6 space-y-4" @click.stop>
                <h3 class="text-base font-semibold text-gray-900">Uklanjanje promjene cijena</h3>
                <p class="text-sm text-gray-700">Da li ste sigurni da želite ukloniti zakazanu promjenu cijena? Trenutne cijene ostaju na snazi.</p>
                <form action="{{ route('panel_admin.settings.price-change.destroy', [], false) }}" method="POST" class="flex justify-end gap-2">
                    @csrf
                    @method('DELETE')
                    <button type="button"
                        class="inline-flex items-center px-4 py-2 border border-red-200 rounded-md font-semibold text-xs text-gray-700 uppercase tracking-widest hover:bg-red-50"
                        @click="priceDeleteOpen = false">
                        Ne
                    </button>
                    <button type="submit"
                        class="inline-flex items-center px-4 py-2 bg-red-600 border border-red-700 rounded-md font-semibold text-xs text-white uppercase tracking-widest shadow-sm hover:bg-red-700">
                        Da
                    </button>
                </form>
            </div>
        </div>

        <div x-show="deleteOpen" x-cloak class="fixed inset-0 z-50 flex items-center justify-center p-4">
                <div class="absolute inset-0 bg-black/50" @click="deleteOpen = false"></div>
                <div class="relative bg-white rounded-lg shadow-xl max-w-md w-full p-6 space-y-4" @click.stop>
                    <h3 class="text-base font-semibold text-gray-900">Potvrda brisanja</h3>
                    <p class="text-sm text-gray-700">Da li si siguran da želiš da obrišeš adresu <span class="font-medium" x-text="deleteEmail"></span> sa liste?</p>
                    <form :action="deleteUrl" method="POST" class="flex justify-end gap-2">
                        @csrf
                        @method('DELETE')
                        <button type="button"
                            class="inline-flex items-center px-4 py-2 border border-red-200 rounded-md font-semibold text-xs text-gray-700 uppercase tracking-widest hover:bg-red-50"
                            @click="deleteOpen = false">
                            Ne
                        </button>
                        <button type="submit"
                            class="inline-flex items-center px-4 py-2 bg-red-600 border border-red-700 rounded-md font-semibold text-xs text-white uppercase tracking-widest shadow-sm hover:bg-red-700 focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-red-500">
                            Da
                        </button>
                    </form>
                </div>
        </div>
    </div>
</x-admin-panel-layout>

