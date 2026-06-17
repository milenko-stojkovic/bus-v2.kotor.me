@php
    $pn = fn (string $key, ?string $fallback = null) => \App\Support\UiText::t('panel', $key, $fallback);
    $ui = fn (string $key) => \App\Support\UiText::t('reservation', $key);
    $u = auth()->user();
    $locale = app()->getLocale();
    $minDate = now()->toDateString();
    $maxDate = now()->addDays(90)->toDateString();
    $termsTitle = $pn('terms_modal_title', $locale === 'cg' ? 'Uslovi korišćenja' : 'Terms and Conditions');
    $termsLinkLabel = $pn('terms_link_label', $locale === 'cg' ? 'uslovima korišćenja' : 'terms and conditions');
    $termsPrefix = $pn('terms_accept_prefix', $locale === 'cg' ? 'Saglasan/a sam sa' : 'I agree to the');
    $countriesCfg = (array) config('countries', []);
    $cc = $u->country ?? '';
    $countryDisplay = $cc;
    if ($cc !== '' && isset($countriesCfg[$cc]) && is_array($countriesCfg[$cc])) {
        $countryDisplay = $countriesCfg[$cc][$locale] ?? ($countriesCfg[$cc]['en'] ?? $cc);
    }
    $noVehiclesHint = $pn('no_vehicles_hint', $locale === 'cg'
        ? 'Prije rezervacije dodajte bar jedno vozilo u sekciji Vozila.'
        : 'Add at least one vehicle in the Vehicles tab before booking.');
    $openVehiclesLabel = $pn('open_vehicles_tab', $locale === 'cg' ? 'Otvori vozila' : 'Open Vehicles');

    $advanceEnabled = (bool) config('features.advance_payments');
    $advanceBalance = (string) ($advance_balance ?? '0.00');
    $advanceCanPay = (bool) ($advance_can_pay ?? false);
    $advancePrice = (string) ($paid_amount ?? '0.00');
    $advanceInsufficientHint = $pn('advance_insufficient', $locale === 'cg'
        ? 'Raspoloživi avans nije dovoljan za ovu rezervaciju.'
        : 'Your advance balance is not sufficient for this reservation.');
    $checkoutMtid = \Illuminate\Support\Str::uuid()->toString();
    $reservationKind = $reservation_kind ?? \App\Support\ReservationKind::TIME_SLOTS;
    $isDailyTicketBooking = (bool) ($is_daily_ticket_booking ?? ($reservationKind === \App\Support\ReservationKind::DAILY_TICKET));
@endphp
<x-app-layout>
    <x-slot name="header">
        <h2 class="font-semibold text-xl text-gray-800 leading-tight">{{ $pn('nav_reservations', 'Reservations') }}</h2>
    </x-slot>

    <div class="py-6">
        <div class="max-w-3xl mx-auto sm:px-6 lg:px-8 space-y-6">
            @include('partials.reservation-pricing-notice')

            @if ($advanceEnabled && !is_null($advanceBalance))
                <div class="rounded-md border border-red-100 bg-red-50 p-3">
                    <span class="text-sm text-gray-600">{{ $pn('advance_total_label', $locale === 'cg' ? 'Ukupan iznos avansa:' : 'Total advance balance:') }}</span>
                    <span class="ml-2 font-semibold text-gray-900">{{ number_format((float) $advanceBalance, 2, '.', '') }} EUR</span>
                </div>
            @endif

            @if ($errors->any())
                <div class="rounded-md bg-red-50 p-4 text-sm text-red-800 space-y-1">
                    @foreach ($errors->all() as $err)
                        <div>{{ $err }}</div>
                    @endforeach
                </div>
            @endif

            @include('partials.checkout-result-banner')

            @if (session('message'))
                <div class="rounded-md bg-red-50 p-3 text-sm text-red-900">{{ session('message') }}</div>
            @endif
            @if (session('error'))
                <div class="rounded-md bg-red-50 p-3 text-sm text-red-800">{{ session('error') }}</div>
            @endif

            {{-- New booking --}}
            <div class="bg-white overflow-hidden shadow-sm sm:rounded-lg">
                <div class="p-6 space-y-4">
                    <h3 class="text-lg font-medium text-gray-900">{{ $pn('booking_section_title', 'New reservation') }}</h3>

                    @if ($vehicles->isEmpty())
                        <div class="rounded-md bg-red-50 p-4 text-sm text-red-900 space-y-2">
                            <p>{{ $noVehiclesHint }}</p>
                            <a href="{{ route('panel.vehicles') }}" class="inline-flex text-red-700 font-medium underline">{{ $openVehiclesLabel }}</a>
                        </div>
                    @endif

                    <form method="GET" action="{{ route('panel.reservations', [], false) }}" class="space-y-4" id="panelStepForm"
                        data-reservation-auto-scroll="reservation_form_scroll_panel"
                        @if ($errors->any()) data-skip-scroll-restore="true" @endif>
                            @include('partials.reservation-date-calendar')

                            @php
                                $benovoUrl = 'https://maps.app.goo.gl/5Mp6LFS1gNLYFrSQA';
                                $autobokaUrl = 'https://maps.app.goo.gl/BqfQWnYqy8mjTo1D8';
                                $pucUrl = 'https://maps.app.goo.gl/1XKocEMgyYi7YoD99';
                                $linkClass = 'font-medium text-red-700 hover:text-red-600 underline decoration-red-200 underline-offset-2';
                                $benovoLink = '<a href="' . e($benovoUrl) . '" target="_blank" rel="noopener noreferrer" class="' . $linkClass . '">' . e($pn('booking_link_benovo', 'Benovo')) . '</a>';
                                $autobokaLink = '<a href="' . e($autobokaUrl) . '" target="_blank" rel="noopener noreferrer" class="' . $linkClass . '">' . e($pn('booking_link_autoboka', 'Autoboka')) . '</a>';
                                $pucLink = '<a href="' . e($pucUrl) . '" target="_blank" rel="noopener noreferrer" class="' . $linkClass . '">' . e($pn('booking_link_puc', 'Puč')) . '</a>';
                                $explTimeSlots = str_replace(
                                    ':benovo_link',
                                    $benovoLink,
                                    $pn(
                                        'booking_kind_expl_time_slots',
                                        $locale === 'cg'
                                            ? 'Termini — ako želite unaprijed rezervisano vrijeme dolaska i odlaska na lokaciji :benovo_link (obavezna lokacija). Ako Vam nisu na raspolaganju željeni termini odaberite dnevnu naknadu.'
                                            : 'Time slots — if you want reserved arrival and departure times at :benovo_link (mandatory location). If your preferred time slots are unavailable, choose the daily fee.',
                                    ),
                                );
                                $explDailyFallback = $locale === 'cg'
                                    ? 'Dnevna naknada — ako vam nije bitan tačan termin ili planirate obilazak više lokacija tokom dana, npr. Perast, Risan, žičara Kotor - Lovćen... U slučaju da želite da posjetite Stari grad, za iskrcaj i ukrcaj putnika koriste se lokacije parkinga :autoboka_link i :puc_link.'
                                    : 'Daily fee — if an exact time is not important or you plan to visit several locations during the day, e.g. Perast, Risan, the Kotor–Lovćen cable car… When visiting the Old Town, passenger pick-up and drop-off use the :autoboka_link and :puc_link parking areas.';
                                $explDailyTemplate = $pn('booking_kind_expl_daily_ticket', $explDailyFallback);
                                if (str_contains($explDailyTemplate, ':perast_link') || str_contains($explDailyTemplate, ':risan_link')) {
                                    $explDailyTemplate = $explDailyFallback;
                                }
                                $explDaily = str_replace(
                                    [':autoboka_link', ':puc_link'],
                                    [$autobokaLink, $pucLink],
                                    $explDailyTemplate,
                                );
                            @endphp
                            <fieldset class="space-y-3" id="panelReservationKindFieldset">
                                <legend class="text-sm font-medium text-gray-900">{{ $pn('booking_kind_legend', $locale === 'cg' ? 'Vrsta rezervacije' : 'Reservation type') }}</legend>
                                <div id="panelBookingKindExplanation" class="rounded-md border border-red-100 bg-red-50 p-4 text-sm text-gray-700 space-y-3">
                                    <p class="m-0">{!! $explTimeSlots !!}</p>
                                    <p class="m-0">{!! $explDaily !!}</p>
                                </div>
                                <div class="flex flex-wrap gap-4 text-sm">
                                    <label class="inline-flex items-center gap-2">
                                        <input type="radio" name="reservation_kind" value="{{ \App\Support\ReservationKind::TIME_SLOTS }}" class="rounded border-red-200"
                                            {{ $reservationKind === \App\Support\ReservationKind::TIME_SLOTS ? 'checked' : '' }}>
                                        <span>{{ $pn('booking_kind_time_slots', $locale === 'cg' ? 'Termini' : 'Time slots') }}</span>
                                    </label>
                                    <label class="inline-flex items-center gap-2">
                                        <input type="radio" name="reservation_kind" value="{{ \App\Support\ReservationKind::DAILY_TICKET }}" class="rounded border-red-200"
                                            {{ $reservationKind === \App\Support\ReservationKind::DAILY_TICKET ? 'checked' : '' }}>
                                        <span>{{ $pn('booking_kind_daily_ticket', $locale === 'cg' ? 'Dnevna naknada' : 'Daily fee') }}</span>
                                    </label>
                                </div>
                            </fieldset>

                            <div id="panelTimeSlotsSection" class="space-y-4 {{ $isDailyTicketBooking ? 'hidden' : '' }}">
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
                            </div>
                            </div>

                            <div>
                                <x-input-label for="vehicle_id_panel" :value="$pn('booking_vehicle_label', 'Vehicle')" />
                                <select
                                    id="vehicle_id_panel"
                                    name="vehicle_id"
                                    class="mt-1 block w-full rounded-md border-red-200 shadow-sm focus:border-red-500 focus:ring-red-500"
                                    @disabled($vehicles->isEmpty() || empty($selected_date) || (! $isDailyTicketBooking && (empty($arrival_id) || empty($departure_id))))
                                >
                                    <option value="">{{ $pn('select_vehicle_option', 'Select vehicle') }}</option>
                                    @foreach ($vehicles as $v)
                                        <option value="{{ $v->id }}" @selected((int)($vehicle_id ?? 0) === (int)$v->id)>
                                            {{ $v->license_plate }} — {{ $v->vehicleType?->formatLabel($locale, 'EUR') ?? ('#'.$v->vehicle_type_id) }}
                                        </option>
                                    @endforeach
                                </select>
                            </div>
                        </form>

                        <form method="POST" action="{{ route('checkout.store', [], false) }}" class="space-y-4" data-disable-double-submit>
                            @csrf
                            <input type="hidden" name="auth_panel_booking" value="1">
                            <input type="hidden" name="merchant_transaction_id" value="{{ $checkoutMtid }}">
                            <input type="hidden" name="reservation_date" value="{{ $selected_date ?? '' }}">
                            <input type="hidden" name="reservation_kind" id="panelPostReservationKind" value="{{ $reservationKind }}">
                            <input type="hidden" name="drop_off_time_slot_id" id="panelPostDropOff" value="{{ $isDailyTicketBooking ? '' : ($arrival_id ?? '') }}">
                            <input type="hidden" name="pick_up_time_slot_id" id="panelPostPickUp" value="{{ $isDailyTicketBooking ? '' : ($departure_id ?? '') }}">
                            <input type="hidden" name="vehicle_id" value="{{ $vehicle_id ?? '' }}">

                            <div class="rounded-md bg-red-50 p-3 text-sm text-gray-800">
                                @if ($isDailyTicketBooking && !empty($selected_date) && !empty($vehicle_id))
                                    <strong>{{ $ui('total_to_pay') }}:</strong>
                                    {{ $paid_amount ?? '—' }} EUR
                                @elseif (!empty($arrival_id) && !empty($departure_id))
                                    @if ($is_free_reservation ?? false)
                                        <strong>{{ $ui('free_reservation') }}</strong>
                                    @else
                                        <strong>{{ $ui('total_to_pay') }}:</strong>
                                        {{ $paid_amount ?? '—' }} EUR
                                    @endif
                                @else
                                    —
                                @endif
                            </div>

                            <div class="grid grid-cols-1 sm:grid-cols-2 gap-4 text-sm">
                                <div>
                                    <span class="block text-xs font-medium text-gray-500">{{ $ui('name') }}</span>
                                    <span class="mt-1 block text-gray-900">{{ $u->name }}</span>
                                </div>
                                <div>
                                    <span class="block text-xs font-medium text-gray-500">{{ $ui('country') }}</span>
                                    <span class="mt-1 block text-gray-900">{{ $countryDisplay }}</span>
                                </div>
                                <div class="sm:col-span-2">
                                    <span class="block text-xs font-medium text-gray-500">{{ $ui('email') }}</span>
                                    <span class="mt-1 block text-gray-900">{{ $u->email }}</span>
                                </div>
                            </div>

                            <div class="space-y-2">
                                <label class="flex items-start gap-2 text-sm">
                                    <input type="checkbox" name="accept_terms" value="1" class="mt-1 rounded border-red-200" {{ old('accept_terms') ? 'checked' : '' }} required>
                                    <span>
                                        {{ $termsPrefix }}
                                        <button type="button" id="openTermsModalPanel" class="underline text-red-700 hover:text-red-900">
                                            {{ $termsLinkLabel }}
                                        </button>
                                    </span>
                                </label>
                                <label id="panelAcceptPrivacyRow" class="flex items-start gap-2 text-sm {{ $isDailyTicketBooking ? 'hidden' : '' }}">
                                    <input type="checkbox" name="accept_privacy" value="1" id="panelAcceptPrivacy" class="mt-1 rounded border-red-200" {{ old('accept_privacy') ? 'checked' : '' }} @if (! $isDailyTicketBooking) required @endif>
                                    <span>@include('partials.reservation-accept-parking-obligation')</span>
                                </label>
                                <x-input-error class="mt-2" :messages="$errors->get('accept_privacy')" />
                            </div>

                            @php
                                $paymentUiReady = ! ($is_free_reservation ?? false)
                                    && ! empty($vehicle_id)
                                    && (
                                        ($isDailyTicketBooking && ! empty($selected_date))
                                        || (! $isDailyTicketBooking && ! empty($arrival_id) && ! empty($departure_id))
                                    );
                            @endphp
                            @if ($paymentUiReady)
                                <div class="rounded-md bg-red-50 border border-red-100 p-3 text-sm space-y-2">
                                    @if ($advanceEnabled)
                                        <div class="text-gray-800">
                                            <strong>{{ $pn('advance_available', $locale === 'cg' ? 'Raspoloživi avans' : 'Available advance') }}:</strong>
                                            {{ number_format((float) $advanceBalance, 2, '.', '') }} EUR
                                        </div>
                                    @endif
                                    <div class="space-y-1">
                                        <div class="font-medium text-gray-800">{{ $pn('payment_method_title', $locale === 'cg' ? 'Način plaćanja' : 'Payment method') }}</div>
                                        <label class="flex items-center gap-2">
                                            <input type="radio" name="payment_method" value="card" class="rounded border-red-200" {{ old('payment_method', 'card') === 'card' ? 'checked' : '' }}>
                                            <span>{{ $pn('pay_by_card', $locale === 'cg' ? 'Plati karticom' : 'Pay by card') }}</span>
                                        </label>
                                        @if ($advanceEnabled)
                                            <label class="flex items-center gap-2">
                                                <input type="radio" name="payment_method" value="advance" class="rounded border-red-200" {{ old('payment_method') === 'advance' ? 'checked' : '' }} {{ $advanceCanPay ? '' : 'disabled' }}>
                                                <span class="{{ $advanceCanPay ? '' : 'text-gray-400' }}">{{ $pn('pay_from_advance', $locale === 'cg' ? 'Plati iz avansa' : 'Pay from advance') }}</span>
                                            </label>
                                            @if (! $advanceCanPay)
                                                <div class="text-xs text-gray-600">{{ $advanceInsufficientHint }}</div>
                                            @endif
                                        @endif
                                    </div>
                                </div>
                            @endif

                            <button
                                type="submit"
                                id="panelReserveBtn"
                                class="inline-flex items-center px-4 py-2 bg-red-700 border border-transparent rounded-md font-semibold text-xs text-white uppercase tracking-widest hover:bg-red-800 disabled:opacity-50 disabled:cursor-not-allowed"
                                disabled
                            >
                                {{ $ui('reserve') }}
                            </button>
                        </form>
                </div>
            </div>

            <div id="termsModalPanel" class="fixed inset-0 z-50 hidden" aria-hidden="true" role="dialog" aria-modal="true">
                <div id="termsOverlayPanel" class="absolute inset-0 bg-black/50"></div>
                <div class="relative mx-auto mt-10 w-[92%] max-w-2xl rounded-lg bg-white shadow-lg">
                    <div class="flex items-center justify-between border-b px-4 py-3">
                        <h2 class="text-base font-semibold">{{ $termsTitle }}</h2>
                        <button type="button" id="closeTermsModalPanel" class="rounded px-2 py-1 text-sm text-gray-600 hover:bg-red-50">
                            {{ $pn('action_close', $locale === 'cg' ? 'Zatvori' : 'Close') }}
                        </button>
                    </div>
                    <div class="max-h-[70vh] overflow-y-auto px-4 py-3 text-sm text-gray-700 space-y-3">
                        @if ($locale === 'cg')
                            @include('partials.terms_cg')
                        @else
                            @include('partials.terms_en')
                        @endif
                    </div>
                </div>
            </div>
        </div>
    </div>

    <script>
        (function () {
            const stepForm = document.getElementById('panelStepForm');
            if (stepForm) {
                const submitStep = (sourceEl) => {
                    if (window.ReservationFormScroll) {
                        window.ReservationFormScroll.submit(stepForm, sourceEl);
                    } else {
                        stepForm.submit();
                    }
                };
                const dateInput = document.getElementById('reservation_date');
                if (dateInput) {
                    stepForm.querySelectorAll('[data-reservation-date]').forEach((btn) => {
                        btn.addEventListener('click', () => {
                            const v = btn.getAttribute('data-reservation-date');
                            if (v) dateInput.value = v;
                            submitStep(btn);
                        });
                    });
                }
                const arrival = document.getElementById('drop_off_time_slot_id');
                const departure = document.getElementById('pick_up_time_slot_id');
                const vehicle = document.getElementById('vehicle_id_panel');
                if (arrival) arrival.addEventListener('change', (e) => submitStep(e.target));
                if (departure) departure.addEventListener('change', (e) => submitStep(e.target));
                if (vehicle) vehicle.addEventListener('change', (e) => submitStep(e.target));
                stepForm.querySelectorAll('input[name="reservation_kind"]').forEach((radio) => {
                    radio.addEventListener('change', (e) => submitStep(e.target));
                });
            }

            const reserveBtn = document.getElementById('panelReserveBtn');
            const postForm = reserveBtn ? reserveBtn.closest('form') : null;
            const postKindInput = document.getElementById('panelPostReservationKind');
            const postDropOff = document.getElementById('panelPostDropOff');
            const postPickUp = document.getElementById('panelPostPickUp');
            const acceptPrivacyRow = document.getElementById('panelAcceptPrivacyRow');
            const acceptPrivacy = document.getElementById('panelAcceptPrivacy');

            function isDailyKindSelected() {
                if (postKindInput) {
                    return postKindInput.value === '{{ \App\Support\ReservationKind::DAILY_TICKET }}';
                }
                const checked = document.querySelector('#panelStepForm input[name="reservation_kind"]:checked');
                return checked && checked.value === '{{ \App\Support\ReservationKind::DAILY_TICKET }}';
            }

            function syncPostKindFromStepForm() {
                if (!postKindInput || !stepForm) return;
                const checked = stepForm.querySelector('input[name="reservation_kind"]:checked');
                if (checked) {
                    postKindInput.value = checked.value;
                }
                const daily = isDailyKindSelected();
                if (postDropOff) postDropOff.value = daily ? '' : (document.getElementById('drop_off_time_slot_id')?.value || '');
                if (postPickUp) postPickUp.value = daily ? '' : (document.getElementById('pick_up_time_slot_id')?.value || '');
                if (acceptPrivacyRow && acceptPrivacy) {
                    acceptPrivacyRow.classList.toggle('hidden', daily);
                    if (daily) {
                        acceptPrivacy.checked = false;
                        acceptPrivacy.removeAttribute('required');
                    } else {
                        acceptPrivacy.setAttribute('required', 'required');
                    }
                }
            }

            function computePostValid() {
                if (!postForm) return false;
                syncPostKindFromStepForm();
                const required = Array.from(postForm.querySelectorAll('[required]'));
                for (const el of required) {
                    if (el.offsetParent === null && el.type === 'checkbox') {
                        continue;
                    }
                    if (el.type === 'checkbox') {
                        if (!el.checked) return false;
                        continue;
                    }
                    if (!el.value || String(el.value).trim() === '') return false;
                }
                const vid = postForm.querySelector('input[name="vehicle_id"]');
                const d = postForm.querySelector('input[name="reservation_date"]');
                if (!vid || !vid.value || !d || !d.value) return false;
                if (isDailyKindSelected()) return true;
                const a = postForm.querySelector('input[name="drop_off_time_slot_id"]');
                const p = postForm.querySelector('input[name="pick_up_time_slot_id"]');
                return !!(a && a.value && p && p.value);
            }

            function refresh() {
                if (!reserveBtn) return;
                reserveBtn.disabled = !computePostValid();
            }

            if (postForm) {
                postForm.addEventListener('input', refresh);
                postForm.addEventListener('change', refresh);
                refresh();
            }

            const termsModal = document.getElementById('termsModalPanel');
            const openTerms = document.getElementById('openTermsModalPanel');
            const closeTerms = document.getElementById('closeTermsModalPanel');
            const termsOverlay = document.getElementById('termsOverlayPanel');

            function showTermsModal() {
                if (!termsModal) return;
                termsModal.classList.remove('hidden');
                termsModal.setAttribute('aria-hidden', 'false');
            }
            function hideTermsModal() {
                if (!termsModal) return;
                termsModal.classList.add('hidden');
                termsModal.setAttribute('aria-hidden', 'true');
            }
            if (openTerms) openTerms.addEventListener('click', showTermsModal);
            if (closeTerms) closeTerms.addEventListener('click', hideTermsModal);
            if (termsOverlay) termsOverlay.addEventListener('click', hideTermsModal);
            document.addEventListener('keydown', function (e) {
                if (e.key === 'Escape') hideTermsModal();
            });
        })();
    </script>
</x-app-layout>
