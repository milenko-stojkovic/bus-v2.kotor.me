@php
    use App\Models\Reservation;
    use Illuminate\Support\Js;
    /** @var Reservation $reservation */
    $fd = old('reservation_date', $formDate);
    $sameAsRes = $fd === $reservation->reservation_date->toDateString();
    $defDrop = old('drop_off_time_slot_id', $sameAsRes ? $reservation->drop_off_time_slot_id : '');
    $defPick = old('pick_up_time_slot_id', $sameAsRes ? $reservation->pick_up_time_slot_id : '');
    $cancelUrl = route('panel_admin.reservations', [], false).($returnQuery !== '' ? '?'.$returnQuery : '');
    $editBase = route('panel_admin.reservations.edit', $reservation, false);
    $initialPayload = [
        'reservation_date' => (string) $fd,
        'drop_off_time_slot_id' => (string) $defDrop,
        'pick_up_time_slot_id' => (string) $defPick,
        'user_name' => old('user_name', $reservation->user_name),
        'country' => old('country', $reservation->country),
        'license_plate' => old('license_plate', $reservation->license_plate),
        'vehicle_type_id' => (string) old('vehicle_type_id', $reservation->vehicle_type_id),
        'email' => old('email', $reservation->email),
    ];
@endphp

<x-admin-panel-layout :page-title="$pageTitle ?? 'Uredi rezervaciju'" nav-active="reservations">
    <div class="space-y-6" x-data="{
        tick: 0,
        initial: {{ Js::from($initialPayload) }},
        canSubmit() {
            this.tick;
            const f = this.$refs.mainForm;
            if (! f) {
                return false;
            }
            const g = (n) => (f.elements.namedItem(n)?.value ?? '').trim();
            if (! g('reservation_date') || ! g('drop_off_time_slot_id') || ! g('pick_up_time_slot_id')) {
                return false;
            }
            const snap = {
                reservation_date: g('reservation_date'),
                drop_off_time_slot_id: g('drop_off_time_slot_id'),
                pick_up_time_slot_id: g('pick_up_time_slot_id'),
                user_name: g('user_name'),
                country: g('country'),
                license_plate: g('license_plate'),
                vehicle_type_id: g('vehicle_type_id'),
                email: g('email'),
            };
            return JSON.stringify(snap) !== JSON.stringify(this.initial);
        },
    }">
        @if (session('status'))
            <div class="rounded-md bg-red-50 p-4 text-sm text-red-900">{{ session('status') }}</div>
        @endif
        @if (session('error'))
            <div class="rounded-md bg-red-50 p-4 text-sm text-red-800">{{ session('error') }}</div>
        @endif

        <div class="flex flex-wrap items-center justify-between gap-3">
            <div>
                <h1 class="text-lg font-semibold text-gray-900">Uredi rezervaciju #{{ $reservation->id }}</h1>
                <p class="text-sm text-gray-600 mt-1">
                    Status: <span class="font-medium">{{ $reservation->status }}</span>
                    @if ($reservation->merchant_transaction_id)
                        · MTID: {{ $reservation->merchant_transaction_id }}
                    @endif
                </p>
            </div>
            <a href="{{ $cancelUrl }}" class="text-sm text-red-700 hover:underline">Otkaži</a>
        </div>

        @if (! empty($pickUpOnly))
            <p class="text-sm text-amber-900 bg-amber-50 border border-amber-200 rounded-md p-3">
                Dolazak je već prošao — možete mijenjati samo <strong>termin odlaska</strong> i ostala polja (ne datum ni dolazak).
            </p>
        @endif

        <p class="text-sm text-red-800 bg-red-50 border border-red-200 rounded-md p-3">
            Kalendar i lista termina su <strong>prefilter</strong>. Konačna provjera dostupnosti radi se pri „Sačuvaj“, nakon zaključavanja redova u bazi.
        </p>

        <form x-ref="mainForm" method="post" action="{{ route('panel_admin.reservations.update', $reservation, false) }}"
            class="bg-white shadow rounded-lg p-6 space-y-5 border border-red-100"
            @input="tick++"
            @change="tick++">
            @csrf
            @method('PUT')
            <input type="hidden" name="return_query" value="{{ $returnQuery }}" />

            <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                <div class="md:col-span-2">
                    <x-input-label for="reservation_date" value="Datum" />
                    @if (! empty($pickUpOnly))
                        <p class="mt-1 text-sm text-gray-900">{{ $reservation->reservation_date->format('d.m.Y.') }}</p>
                        <input type="hidden" name="reservation_date" value="{{ $reservation->reservation_date->toDateString() }}" />
                    @else
                        <input type="date" name="reservation_date" id="reservation_date" required
                            min="{{ $dateMin }}" max="{{ $dateMax }}"
                            value="{{ $fd }}"
                            class="mt-1 block w-full rounded-md border-red-200 shadow-sm focus:border-red-500 focus:ring-red-500"
                            @change="
                                const u = new URL(@json($editBase), window.location.origin);
                                u.searchParams.set('form_date', $event.target.value);
                                @if ($returnQuery !== '')
                                    u.searchParams.set('rq', @json($returnQuery));
                                @endif
                                window.location.href = u.toString();
                            " />
                    @endif
                    <x-input-error class="mt-2" :messages="$errors->get('reservation_date')" />
                </div>

                <div>
                    <x-input-label for="drop_off_time_slot_id" value="Vrijeme dolaska (drop-off)" />
                    <select name="drop_off_time_slot_id" id="drop_off_time_slot_id" required
                        @if (! empty($pickUpOnly)) disabled @endif
                        class="mt-1 block w-full rounded-md border-red-200 shadow-sm @if(!empty($pickUpOnly)) bg-gray-100 @endif">
                        <option value="">— izaberite —</option>
                        @foreach ($slotOptions as $row)
                            @php
                                $opt = $row['slot'];
                                $sel = $row['selectable'];
                                $daily = $row['daily'];
                                $allow = $sel || (string) $defDrop === (string) $opt->id;
                                $hint = '';
                                if ($daily === null) {
                                    $hint = ' (nema podataka za dan)';
                                } elseif (! $sel) {
                                    $hint = ' (nedostupno)';
                                }
                            @endphp
                            <option value="{{ $opt->id }}" @disabled(! $allow) @selected((string) $defDrop === (string) $opt->id)>
                                {{ $opt->time_slot }}{{ $hint }}
                            </option>
                        @endforeach
                    </select>
                    @if (! empty($pickUpOnly))
                        <input type="hidden" name="drop_off_time_slot_id" value="{{ $reservation->drop_off_time_slot_id }}" />
                    @endif
                    <x-input-error class="mt-2" :messages="$errors->get('drop_off_time_slot_id')" />
                </div>
                <div>
                    <x-input-label for="pick_up_time_slot_id" value="Vrijeme odlaska (pick-up)" />
                    <select name="pick_up_time_slot_id" id="pick_up_time_slot_id" required
                        class="mt-1 block w-full rounded-md border-red-200 shadow-sm">
                        <option value="">— izaberite —</option>
                        @foreach ($slotOptions as $row)
                            @php
                                $opt = $row['slot'];
                                $sel = $row['selectable'];
                                $daily = $row['daily'];
                                $allow = $sel || (string) $defPick === (string) $opt->id;
                                $hint = '';
                                if ($daily === null) {
                                    $hint = ' (nema podataka za dan)';
                                } elseif (! $sel) {
                                    $hint = ' (nedostupno)';
                                }
                            @endphp
                            <option value="{{ $opt->id }}" @disabled(! $allow) @selected((string) $defPick === (string) $opt->id)>
                                {{ $opt->time_slot }}{{ $hint }}
                            </option>
                        @endforeach
                    </select>
                    <x-input-error class="mt-2" :messages="$errors->get('pick_up_time_slot_id')" />
                </div>

                <div class="md:col-span-2">
                    <x-input-label for="user_name" value="Ime" />
                    <x-text-input class="mt-1 block w-full" type="text" name="user_name" id="user_name" required
                        :value="old('user_name', $reservation->user_name)" />
                    <x-input-error class="mt-2" :messages="$errors->get('user_name')" />
                </div>

                <div>
                    <x-input-label for="country" value="Država" />
                    <select name="country" id="country" class="mt-1 block w-full rounded-md border-red-200 shadow-sm" required>
                        @foreach ($countries as $code => $labels)
                            @php $lab = is_array($labels) ? ($labels['cg'] ?? $code) : $labels; @endphp
                            <option value="{{ $code }}" @selected(old('country', $reservation->country) === $code)>{{ $lab }} ({{ $code }})</option>
                        @endforeach
                    </select>
                    <x-input-error class="mt-2" :messages="$errors->get('country')" />
                </div>

                <div>
                    <x-input-label for="license_plate" value="Registarska tablica" />
                    <x-text-input class="mt-1 block w-full" type="text" name="license_plate" id="license_plate" required
                        style="text-transform: uppercase"
                        :value="old('license_plate', $reservation->license_plate)" />
                    <x-input-error class="mt-2" :messages="$errors->get('license_plate')" />
                </div>

                <div class="md:col-span-2">
                    <x-input-label for="vehicle_type_id" value="Kategorija vozila" />
                    <select name="vehicle_type_id" id="vehicle_type_id" class="mt-1 block w-full rounded-md border-red-200 shadow-sm" required>
                        @foreach ($vehicleTypesAllowed as $vt)
                            <option value="{{ $vt->id }}" @selected((string) old('vehicle_type_id', $reservation->vehicle_type_id) === (string) $vt->id)>
                                {{ $vt->formatLabel('cg', 'EUR') }}
                            </option>
                        @endforeach
                    </select>
                    <x-input-error class="mt-2" :messages="$errors->get('vehicle_type_id')" />
                </div>

                <div class="md:col-span-2">
                    <x-input-label for="email" value="Email" />
                    <x-text-input class="mt-1 block w-full" type="email" name="email" id="email" required
                        :value="old('email', $reservation->email)" />
                    <x-input-error class="mt-2" :messages="$errors->get('email')" />
                </div>
            </div>

            <div class="flex flex-wrap justify-end gap-3 pt-2">
                <a href="{{ $cancelUrl }}"
                    class="inline-flex items-center px-4 py-2 border border-red-200 rounded-md font-semibold text-xs text-gray-700 uppercase tracking-widest hover:bg-red-50">
                    Otkaži
                </a>
                <button type="submit"
                    class="inline-flex items-center px-4 py-2 bg-red-700 border border-transparent rounded-md font-semibold text-xs text-white uppercase tracking-widest hover:bg-red-800 disabled:opacity-50"
                    @if (empty($pickUpOnly)) x-bind:disabled="!canSubmit()" @endif>
                    Sačuvaj
                </button>
            </div>
        </form>
    </div>
</x-admin-panel-layout>
