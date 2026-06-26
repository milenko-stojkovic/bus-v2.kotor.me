<x-guest-layout :landing-background="true" :show-home-link="true">
    @php
        $minDate = now()->toDateString();
        $maxDate = now()->addDays(90)->toDateString();
        $locale = app()->getLocale();
        $ui = fn (string $key) => \App\Support\UiText::t('reservation', $key);
        $pn = fn (string $key, ?string $fallback = null) => \App\Support\UiText::t('panel', $key, $fallback);
        $termsTitle = $locale === 'cg' ? 'Uslovi korišćenja' : 'Terms and Conditions';
        $termsLinkLabel = $locale === 'cg' ? 'uslovima korišćenja' : 'terms and conditions';
        $reservationKind = $reservation_kind ?? \App\Support\ReservationKind::TIME_SLOTS;
        $isDailyTicketBooking = (bool) ($is_daily_ticket_booking ?? ($reservationKind === \App\Support\ReservationKind::DAILY_TICKET));
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
        $explTimeSlotsLimoNote = $pn(
            'guest_booking_time_slots_limo_note',
            $locale === 'cg'
                ? 'Putnička vozila (4+1–7+1) nisu dostupna za Termini jer se ne mogu koristiti na Benovu.'
                : 'Passenger vehicles (4+1–7+1) are not available for Time slots because they may not use Benovo.',
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

    @php
        $checkoutBanner = session('checkout_banner');
        $guestFeedbackScroll = session('guest_lower_category_block')
            || (session('error') && ! session('guest_lower_category_block'))
            || session('message')
            || $errors->any()
            || (is_array($checkoutBanner) && ($checkoutBanner['level'] ?? '') === 'error');
    @endphp

    <div class="space-y-6">
        <div class="space-y-3">
            <div class="space-y-1">
                <h1 class="text-lg font-semibold">{{ $ui('title') }}</h1>
            </div>
            @include('partials.reservation-pricing-notice')
        </div>

        <div
            id="guest-reservation-feedback"
            @if ($guestFeedbackScroll) data-guest-reservation-feedback @endif
            class="space-y-4 @if ($guestFeedbackScroll) scroll-mt-20 @endif"
        >
        @include('partials.checkout-result-banner')

        @include('partials.guest-lower-category-block')

        @if (session('message'))
            <div class="rounded-md bg-red-50 p-3 text-sm text-red-900">{{ session('message') }}</div>
        @endif
        @if (session('error') && ! session('guest_lower_category_block'))
            <div class="rounded-md bg-red-50 p-3 text-sm text-red-800">{{ session('error') }}</div>
        @endif
        </div>

        <form method="GET" action="{{ route('guest.reserve', [], false) }}" class="space-y-4" id="stepForm"
            data-reservation-auto-scroll="reservation_form_scroll_guest"
            @if ($errors->any()) data-skip-scroll-restore="true" @endif>
            @include('partials.reservation-date-calendar')

            <fieldset class="space-y-3" id="guestReservationKindFieldset">
                <legend class="text-sm font-medium text-gray-900">{{ $pn('booking_kind_legend', $locale === 'cg' ? 'Vrsta rezervacije' : 'Reservation type') }}</legend>
                <div id="guestBookingKindExplanation" class="rounded-md border border-red-100 bg-red-50 p-4 text-sm text-gray-700 space-y-3">
                    <p class="m-0">{!! $explTimeSlots !!}</p>
                    <p class="m-0 text-gray-600">{{ $explTimeSlotsLimoNote }}</p>
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

            <div id="guestTimeSlotsSection" class="space-y-4 {{ $isDailyTicketBooking ? 'hidden' : '' }}">
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
                <p class="mt-1 text-xs text-gray-500">
                    {{ $ui('departure_disabled_hint') }}
                </p>
                <x-input-error class="mt-2" :messages="$errors->get('pick_up_time_slot_id')" />
            </div>
            </div>

            <div>
                <x-input-label for="vehicle_type_id_step" :value="$ui('vehicle_category')" />
                <select
                    id="vehicle_type_id_step"
                    name="vehicle_type_id"
                    class="mt-1 block w-full rounded-md border-red-200 shadow-sm focus:border-red-500 focus:ring-red-500"
                    @disabled(empty($selected_date) || (! $isDailyTicketBooking && (empty($arrival_id) || empty($departure_id))))
                >
                    <option value="">{{ $ui('select_vehicle_category') }}</option>
                    @foreach (($vehicle_types ?? []) as $vt)
                        <option value="{{ $vt->id }}" @selected((int)request('vehicle_type_id') === (int)$vt->id)>
                            {{ $vt->formatLabel(app()->getLocale(), 'EUR') }}
                        </option>
                    @endforeach
                </select>
                <x-input-error class="mt-2" :messages="$errors->get('vehicle_type_id')" />
            </div>
        </form>

        <form method="POST" action="{{ route('checkout.store', [], false) }}" class="space-y-4">
            @csrf

            <input type="hidden" name="reservation_date" value="{{ $selected_date ?? '' }}">
            <input type="hidden" name="reservation_kind" id="guestPostReservationKind" value="{{ $reservationKind }}">
            <input type="hidden" name="drop_off_time_slot_id" id="guestPostDropOff" value="{{ $isDailyTicketBooking ? '' : ($arrival_id ?? '') }}">
            <input type="hidden" name="pick_up_time_slot_id" id="guestPostPickUp" value="{{ $isDailyTicketBooking ? '' : ($departure_id ?? '') }}">
            <input type="hidden" name="vehicle_type_id" value="{{ request('vehicle_type_id') }}">

            <div class="rounded-md bg-red-50 p-3 text-sm text-gray-800">
                @if ($isDailyTicketBooking && !empty($selected_date) && request('vehicle_type_id'))
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

            <div class="space-y-2">
                <label class="flex items-start gap-2 text-sm">
                    <input type="checkbox" name="accept_terms" value="1" class="mt-1 rounded border-red-200" {{ old('accept_terms') ? 'checked' : '' }} required>
                    <span>
                        {{ $locale === 'cg' ? 'Saglasan/a sam sa' : 'I agree to the' }}
                        <button type="button" id="openTermsModal" class="underline text-red-700 hover:text-red-900">
                            {{ $termsLinkLabel }}
                        </button>
                    </span>
                </label>
                <x-input-error class="mt-2" :messages="$errors->get('accept_terms')" />

                <label id="guestAcceptPrivacyRow" class="flex items-start gap-2 text-sm {{ $isDailyTicketBooking ? 'hidden' : '' }}">
                    <input type="checkbox" name="accept_privacy" value="1" id="guestAcceptPrivacy" class="mt-1 rounded border-red-200" {{ old('accept_privacy') ? 'checked' : '' }} @if (! $isDailyTicketBooking) required @endif>
                    <span>@include('partials.reservation-accept-parking-obligation')</span>
                </label>
                <x-input-error class="mt-2" :messages="$errors->get('accept_privacy')" />
            </div>

            <button
                type="submit"
                id="reserveBtn"
                class="inline-flex items-center px-4 py-2 bg-red-700 border border-transparent rounded-md font-semibold text-xs text-white uppercase tracking-widest hover:bg-red-800 focus:bg-red-800 active:bg-red-900 focus:outline-none focus:ring-2 focus:ring-red-500 focus:ring-offset-2 transition ease-in-out duration-150 disabled:opacity-50 disabled:cursor-not-allowed"
                disabled
            >
                {{ $ui('reserve') }}
            </button>
        </form>
    </div>

    <div id="termsModal" class="fixed inset-0 z-50 hidden" aria-hidden="true" role="dialog" aria-modal="true">
        <div id="termsOverlay" class="absolute inset-0 bg-black/50"></div>
        <div class="relative mx-auto mt-10 w-[92%] max-w-2xl rounded-lg bg-white shadow-lg">
            <div class="flex items-center justify-between border-b px-4 py-3">
                <h2 class="text-base font-semibold">{{ $termsTitle }}</h2>
                <button type="button" id="closeTermsModal" class="rounded px-2 py-1 text-sm text-gray-600 hover:bg-red-50">
                    {{ $locale === 'cg' ? 'Zatvori' : 'Close' }}
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

    <script>
        (function () {
            const form = document.getElementById('stepForm');
            if (!form) return;
            const submitStep = (sourceEl) => {
                if (window.ReservationFormScroll) {
                    window.ReservationFormScroll.submit(form, sourceEl);
                } else {
                    form.submit();
                }
            };
            const dateInput = document.getElementById('reservation_date');
            if (dateInput) {
                form.querySelectorAll('[data-reservation-date]').forEach((btn) => {
                    btn.addEventListener('click', () => {
                        const v = btn.getAttribute('data-reservation-date');
                        if (v) dateInput.value = v;
                        submitStep(btn);
                    });
                });
            }
            const arrival = document.getElementById('drop_off_time_slot_id');
            const departure = document.getElementById('pick_up_time_slot_id');
            const vehicleType = document.getElementById('vehicle_type_id_step');
            if (arrival) arrival.addEventListener('change', (e) => submitStep(e.target));
            if (departure) departure.addEventListener('change', (e) => submitStep(e.target));
            if (vehicleType) vehicleType.addEventListener('change', (e) => submitStep(e.target));
            form.querySelectorAll('input[name="reservation_kind"]').forEach((radio) => {
                radio.addEventListener('change', (e) => submitStep(e.target));
            });

            const reserveBtn = document.getElementById('reserveBtn');
            const postForm = reserveBtn ? reserveBtn.closest('form') : null;
            const postKindInput = document.getElementById('guestPostReservationKind');
            const postDropOff = document.getElementById('guestPostDropOff');
            const postPickUp = document.getElementById('guestPostPickUp');
            const acceptPrivacyRow = document.getElementById('guestAcceptPrivacyRow');
            const acceptPrivacy = document.getElementById('guestAcceptPrivacy');
            const timeSlotsSection = document.getElementById('guestTimeSlotsSection');

            function isDailyKindSelected() {
                if (postKindInput) {
                    return postKindInput.value === '{{ \App\Support\ReservationKind::DAILY_TICKET }}';
                }
                const checked = form.querySelector('input[name="reservation_kind"]:checked');
                return checked && checked.value === '{{ \App\Support\ReservationKind::DAILY_TICKET }}';
            }

            function syncPostKindFromStepForm() {
                if (!postKindInput) return;
                const checked = form.querySelector('input[name="reservation_kind"]:checked');
                if (checked) {
                    postKindInput.value = checked.value;
                }
                const daily = isDailyKindSelected();
                if (postDropOff) postDropOff.value = daily ? '' : (document.getElementById('drop_off_time_slot_id')?.value || '');
                if (postPickUp) postPickUp.value = daily ? '' : (document.getElementById('pick_up_time_slot_id')?.value || '');
                if (timeSlotsSection) {
                    timeSlotsSection.classList.toggle('hidden', daily);
                }
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
            const lp = document.getElementById('license_plate');
            if (lp) {
                lp.addEventListener('input', () => {
                    const v = (lp.value || '').toUpperCase().replace(/[^A-Z0-9]/g, '');
                    if (lp.value !== v) lp.value = v;
                });
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
                    if (el.getAttribute('pattern')) {
                        try {
                            const re = new RegExp('^' + el.getAttribute('pattern') + '$');
                            if (!re.test(el.value)) return false;
                        } catch (e) {}
                    }
                }
                const vt = postForm.querySelector('input[name="vehicle_type_id"]');
                const d = postForm.querySelector('input[name="reservation_date"]');
                if (!vt || !vt.value || !d || !d.value) return false;
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

            const termsModal = document.getElementById('termsModal');
            const openTerms = document.getElementById('openTermsModal');
            const closeTerms = document.getElementById('closeTermsModal');
            const termsOverlay = document.getElementById('termsOverlay');

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
</x-guest-layout>

