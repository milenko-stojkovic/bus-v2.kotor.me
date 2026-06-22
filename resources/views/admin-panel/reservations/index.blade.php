<x-admin-panel-layout :page-title="$pageTitle ?? 'Rezervacije'" nav-active="reservations">
    <div class="space-y-8" x-data="{
        useInterval: {{ old('use_interval', $filters['use_interval'] ?? false) ? 'true' : 'false' }},
        tick: 0,
        hasAny() {
            this.tick;
            return !!(
                (this.$refs.mtid && this.$refs.mtid.value.trim()) ||
                (this.$refs.dateSingle && this.$refs.dateSingle.value && !this.useInterval) ||
                (this.useInterval && this.$refs.dateFrom && this.$refs.dateFrom.value && this.$refs.dateTo && this.$refs.dateTo.value) ||
                (this.$refs.name && this.$refs.name.value.trim()) ||
                (this.$refs.email && this.$refs.email.value.trim()) ||
                (this.$refs.vt && this.$refs.vt.value) ||
                (this.$refs.plate && this.$refs.plate.value.trim()) ||
                (this.$refs.country && this.$refs.country.value) ||
                (this.$refs.status && this.$refs.status.value) ||
                (this.$refs.agency && this.$refs.agency.value) ||
                (this.$refs.reservationKind && this.$refs.reservationKind.value)
            );
        }
    }">
        <div>
            <h1 class="text-lg font-semibold text-gray-900">Rezervacije</h1>
            <p class="text-sm text-gray-600 mt-1">Pretraga samo nad tabelom rezervacija (AND između popunjenih kriterijuma).</p>
        </div>

        @if (session('status'))
            <div class="rounded-md bg-red-50 p-4 text-sm text-red-900">{{ session('status') }}</div>
        @endif
        @if (session('error'))
            <div class="rounded-md bg-red-50 p-4 text-sm text-red-800">{{ session('error') }}</div>
        @endif

        <form method="get" action="{{ route('panel_admin.reservations', [], false) }}" class="bg-white shadow rounded-lg p-6 space-y-4 border border-red-100"
            @change="tick++"
            @input="tick++">
            <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                <div>
                    <x-input-label for="merchant_transaction_id" value="Merchant transaction ID" />
                    <x-text-input class="mt-1 block w-full" type="text" name="merchant_transaction_id" id="merchant_transaction_id" x-ref="mtid"
                        :value="old('merchant_transaction_id', $filters['merchant_transaction_id'] ?? '')" />
                    <x-input-error class="mt-2" :messages="$errors->get('merchant_transaction_id')" />
                </div>
                <div>
                    <x-input-label for="agency_user_id" value="Agencija (korisnik)" />
                    <select name="agency_user_id" id="agency_user_id" x-ref="agency"
                        class="mt-1 block w-full rounded-md border-red-200 shadow-sm focus:border-red-500 focus:ring-red-500"
                        @change="
                            const o = $event.target.selectedOptions[0];
                            if (o && o.dataset.name) {
                                $refs.name.value = o.dataset.name || '';
                                $refs.email.value = o.dataset.email || '';
                                $refs.country.value = o.dataset.country || '';
                            }
                        ">
                        <option value="">—</option>
                        @foreach ($agencies as $u)
                            <option value="{{ $u->id }}" data-name="{{ e($u->name) }}" data-email="{{ e($u->email) }}" data-country="{{ e($u->country ?? '') }}"
                                @selected((string)old('agency_user_id', $filters['agency_user_id'] ?? '') === (string)$u->id)>
                                {{ $u->name }} ({{ $u->email }})
                            </option>
                        @endforeach
                    </select>
                </div>
            </div>

            <div class="flex flex-wrap items-center gap-4">
                <label class="inline-flex items-center gap-2 text-sm text-gray-700">
                    <input type="checkbox" name="use_interval" value="1" x-model="useInterval" class="rounded border-red-200"
                        @checked(old('use_interval', $filters['use_interval'] ?? false)) />
                    Interval datuma
                </label>
            </div>

            <div class="grid grid-cols-1 md:grid-cols-3 gap-4" x-show="!useInterval">
                <div>
                    <x-input-label for="date_single" value="Datum" />
                    <input type="date" name="date_single" id="date_single" x-ref="dateSingle"
                        min="{{ $dateMin }}" max="{{ $dateMax }}"
                        value="{{ old('date_single', $filters['date_single'] ?? '') }}"
                        class="mt-1 block w-full rounded-md border-red-200 shadow-sm focus:border-red-500 focus:ring-red-500" />
                    <x-input-error class="mt-2" :messages="$errors->get('date_single')" />
                </div>
            </div>

            <div class="grid grid-cols-1 md:grid-cols-2 gap-4" x-show="useInterval" x-cloak>
                <div>
                    <x-input-label for="date_from" value="Od datuma" />
                    <input type="date" name="date_from" id="date_from" x-ref="dateFrom"
                        min="{{ $dateMin }}" max="{{ $dateMax }}"
                        value="{{ old('date_from', $filters['date_from'] ?? '') }}"
                        class="mt-1 block w-full rounded-md border-red-200 shadow-sm" />
                    <x-input-error class="mt-2" :messages="$errors->get('date_from')" />
                </div>
                <div>
                    <x-input-label for="date_to" value="Do datuma" />
                    <input type="date" name="date_to" id="date_to" x-ref="dateTo"
                        min="{{ $dateMin }}" max="{{ $dateMax }}"
                        value="{{ old('date_to', $filters['date_to'] ?? '') }}"
                        class="mt-1 block w-full rounded-md border-red-200 shadow-sm" />
                    <x-input-error class="mt-2" :messages="$errors->get('date_to')" />
                </div>
            </div>

            <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                <div>
                    <x-input-label for="name" value="Ime" />
                    <x-text-input class="mt-1 block w-full" type="text" name="name" id="name" x-ref="name" :value="old('name', $filters['name'] ?? '')" />
                </div>
                <div>
                    <x-input-label for="email" value="Email" />
                    <x-text-input class="mt-1 block w-full" type="text" name="email" id="email" x-ref="email" :value="old('email', $filters['email'] ?? '')" />
                </div>
                <div>
                    <x-input-label for="vehicle_type_id" value="Tip vozila" />
                    <select name="vehicle_type_id" id="vehicle_type_id" x-ref="vt" class="mt-1 block w-full rounded-md border-red-200 shadow-sm">
                        <option value="">—</option>
                        @foreach ($vehicleTypes as $vt)
                            <option value="{{ $vt->id }}" @selected((string)old('vehicle_type_id', $filters['vehicle_type_id'] ?? '') === (string)$vt->id)>
                                {{ $vt->formatLabel('cg', 'EUR') }}
                            </option>
                        @endforeach
                    </select>
                </div>
                <div>
                    <x-input-label for="license_plate" value="Registarska tablica" />
                    <x-text-input class="mt-1 block w-full uppercase" type="text" name="license_plate" id="license_plate" x-ref="plate"
                        autocapitalize="characters" autocomplete="off" spellcheck="false"
                        oninput="this.value=this.value.toUpperCase()"
                        :value="old('license_plate', $filters['license_plate'] ?? '')" />
                </div>
                <div>
                    <x-input-label for="country" value="Država" />
                    <select name="country" id="country" x-ref="country" class="mt-1 block w-full rounded-md border-red-200 shadow-sm">
                        <option value="">—</option>
                        @foreach ($countries as $code => $labels)
                            @php $lab = is_array($labels) ? ($labels['cg'] ?? $code) : $labels; @endphp
                            <option value="{{ $code }}" @selected(old('country', $filters['country'] ?? '') === $code)>{{ $lab }} ({{ $code }})</option>
                        @endforeach
                    </select>
                </div>
                <div>
                    <x-input-label for="status" value="Status" />
                    <select name="status" id="status" x-ref="status" class="mt-1 block w-full rounded-md border-red-200 shadow-sm">
                        <option value="">—</option>
                        <option value="paid" @selected(old('status', $filters['status'] ?? '') === 'paid')>Plaćeno</option>
                        <option value="free" @selected(old('status', $filters['status'] ?? '') === 'free')>Besplatno</option>
                    </select>
                </div>
                <div>
                    <x-input-label for="reservation_kind" value="Vrsta rezervacije" />
                    <select name="reservation_kind" id="reservation_kind" x-ref="reservationKind"
                        class="mt-1 block w-full rounded-md border-red-200 shadow-sm focus:border-red-500 focus:ring-red-500">
                        <option value="" @selected(old('reservation_kind', $filters['reservation_kind'] ?? '') === '')>Sve</option>
                        <option value="time_slots" @selected(old('reservation_kind', $filters['reservation_kind'] ?? '') === 'time_slots')>Termini</option>
                        <option value="daily_ticket" @selected(old('reservation_kind', $filters['reservation_kind'] ?? '') === 'daily_ticket')>Dnevna naknada</option>
                    </select>
                    <x-input-error class="mt-2" :messages="$errors->get('reservation_kind')" />
                </div>
            </div>

            <div class="flex justify-end">
                <button type="submit" x-bind:disabled="!hasAny()"
                    class="inline-flex items-center px-4 py-2 bg-red-700 border border-transparent rounded-md font-semibold text-xs text-white uppercase tracking-widest hover:bg-red-800 disabled:opacity-50">
                    Pretraži
                </button>
            </div>
        </form>

        @if ($hasCriteria)
            <div class="flex justify-end">
                <a href="{{ route('panel_admin.reservations', [], false) }}"
                   class="inline-flex items-center px-4 py-2 border border-red-300 rounded-md font-semibold text-xs text-red-800 uppercase tracking-widest hover:bg-red-50">
                    Nova pretraga
                </a>
            </div>
        @endif

        @if ($hasCriteria && $results)
            <div class="space-y-3">
                <h2 class="text-base font-semibold text-gray-900">Rezultati ({{ $results->total() }})</h2>
                <ul class="space-y-3">
                    @foreach ($results as $r)
                        @php
                            $canEdit = \App\Services\AdminPanel\Reservation\AdminReservationEditPolicy::canEdit($r);
                            $rq = request()->getQueryString();
                        @endphp
                        <li class="bg-white shadow rounded-lg p-4 border border-red-100">
                            <div class="flex flex-wrap justify-between gap-3">
                                <div class="text-sm space-y-1 min-w-0">
                                    <div><span class="text-gray-500">MTID:</span> {{ $r->merchant_transaction_id ?? '—' }}</div>
                                    <div><span class="text-gray-500">Datum:</span> {{ $r->reservation_date->format('d.m.Y.') }}</div>
                                    @if ($r->isDailyTicket())
                                        <div><span class="text-gray-500">Vrsta:</span> @include('partials.reservation-slot-display', ['reservation' => $r, 'slot' => null, 'locale' => 'cg'])</div>
                                        <div><span class="text-gray-500">Datum važenja:</span> {{ $r->reservation_date->format('d.m.Y.') }}</div>
                                        <div><span class="text-gray-500">Lokacije:</span> Autoboka i Puč</div>
                                    @else
                                        <div><span class="text-gray-500">Dolazak:</span> @include('partials.reservation-slot-display', ['reservation' => $r, 'slot' => $r->dropOffTimeSlot, 'locale' => 'cg'])</div>
                                        <div><span class="text-gray-500">Odlazak:</span> @include('partials.reservation-slot-display', ['reservation' => $r, 'slot' => $r->pickUpTimeSlot, 'locale' => 'cg'])</div>
                                    @endif
                                    @if ($r->isGuest())
                                        <div><span class="text-gray-500">Tip korisnika:</span> Guest</div>
                                        <div><span class="text-gray-500">Ime:</span> {{ $r->user_name }}</div>
                                        <div><span class="text-gray-500">Email:</span> {{ $r->email }}</div>
                                    @else
                                        @php
                                            $agencyAccountEmail = trim((string) ($r->user->email ?? ''));
                                            $reservationEmail = trim((string) ($r->email ?? ''));
                                        @endphp
                                        <div><span class="text-gray-500">Tip korisnika:</span> Agencija</div>
                                        <div><span class="text-gray-500">Agencija:</span> {{ $r->user->name ?: '—' }}</div>
                                        <div><span class="text-gray-500">Email naloga:</span> {{ $agencyAccountEmail !== '' ? $agencyAccountEmail : '—' }}</div>
                                        @if ($reservationEmail !== '' && strcasecmp($reservationEmail, $agencyAccountEmail) !== 0)
                                            <div><span class="text-gray-500">Email rezervacije:</span> {{ $reservationEmail }}</div>
                                        @endif
                                    @endif
                                    <div><span class="text-gray-500">Država:</span> {{ $r->country }}</div>
                                    <div><span class="text-gray-500">Tablica:</span> {{ $r->license_plate }}</div>
                                    <div><span class="text-gray-500">Vozilo:</span> {{ $r->vehicleType?->formatLabel('cg', 'EUR') ?? '—' }}</div>
                                    <div><span class="text-gray-500">Status:</span> {{ $r->status }}</div>
                                </div>
                                <div class="flex flex-row flex-wrap gap-2 shrink-0 items-start">
                                    <a href="{{ route('panel_admin.reservations.pdf', $r, false) }}" target="_blank" rel="noopener"
                                       class="inline-flex justify-center items-center px-3 py-2 border border-red-200 rounded-md text-xs font-semibold text-gray-700 uppercase tracking-widest hover:bg-red-50">PDF</a>
                                    @if ($canEdit)
                                        <a href="{{ route('panel_admin.reservations.edit', array_filter(['reservation' => $r, 'rq' => $rq ?: null]), false) }}"
                                           class="inline-flex justify-center items-center px-3 py-2 border border-red-300 rounded-md text-xs font-semibold text-red-800 uppercase tracking-widest hover:bg-red-50">Izmeni</a>
                                    @else
                                        <span class="inline-flex justify-center items-center px-3 py-2 border border-gray-200 rounded-md text-xs font-semibold text-gray-400 uppercase tracking-widest cursor-not-allowed" title="Realizovana rezervacija">Izmeni</span>
                                    @endif
                                </div>
                            </div>
                        </li>
                    @endforeach
                </ul>
                <div class="mt-4">{{ $results->links() }}</div>
            </div>
        @endif
    </div>
</x-admin-panel-layout>
