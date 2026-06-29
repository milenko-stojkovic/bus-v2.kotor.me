@php
    $u = fn (string $key, ?string $fallback = null) => \App\Support\UiText::t('user', $key, $fallback);
    $countries = \App\Support\BankartBillingCountry::selectableCountries();
    $storedCountry = old('country', $user->country);
    $storedCountrySelectable = \App\Support\BankartBillingCountry::isSelectablePaymentCountry($storedCountry);
    $selectedCountry = old('country', $storedCountrySelectable ? $storedCountry : '');
    $initial = [
        'name' => old('name', $user->name),
        'email' => old('email', $user->email),
        'lang' => old('lang', $user->lang ?? 'en'),
        'country' => $selectedCountry,
    ];
@endphp

<form id="send-verification" method="post" action="{{ route('verification.send') }}">
    @csrf
</form>

<div
    class="space-y-8"
    x-data="{
        initial: @js($initial),
        name: @js($initial['name']),
        email: @js($initial['email']),
        lang: @js($initial['lang']),
        country: @js($initial['country']),
        current_password: '',
        password: '',
        password_confirmation: '',
        isDirty() {
            if (this.name !== this.initial.name || this.email !== this.initial.email || this.lang !== this.initial.lang || this.country !== this.initial.country) {
                return true;
            }
            return (this.current_password || this.password || this.password_confirmation).length > 0;
        },
        cancel() {
            this.name = this.initial.name;
            this.email = this.initial.email;
            this.lang = this.initial.lang;
            this.country = this.initial.country;
            this.current_password = '';
            this.password = '';
            this.password_confirmation = '';
        }
    }"
>
    <form method="post" action="{{ route('profile.update') }}" class="space-y-6">
        @csrf
        @method('patch')

        <div>
            <x-input-label for="user_name" :value="$u('name', 'Name')" />
            <x-text-input
                id="user_name"
                name="name"
                type="text"
                class="mt-1 block w-full"
                x-model="name"
                required
                minlength="2"
                autocomplete="name"
            />
            <x-input-error class="mt-2" :messages="$errors->get('name')" />
        </div>

        <div>
            <x-input-label for="user_country" :value="$u('country', 'Country')" />
            @if (! $storedCountrySelectable && (string) $storedCountry !== '')
                <p class="mt-1 text-sm text-amber-800">
                    {{ app()->getLocale() === 'cg'
                        ? 'Trenutna država na profilu nije validna za plaćanje. Molimo izaberite državu iz liste.'
                        : 'The country on your profile is not valid for payment. Please select a country from the list.' }}
                </p>
            @endif
            <select
                id="user_country"
                name="country"
                class="mt-1 block w-full rounded-md border-red-200 shadow-sm focus:border-red-500 focus:ring-red-500"
                x-model="country"
                required
            >
                <option value="">{{ \App\Support\UiText::t('auth', 'select_country', app()->getLocale() === 'cg' ? 'Izaberite državu' : 'Select country') }}</option>
                @foreach ($countries as $code => $labels)
                    @php
                        $label = is_array($labels) ? ($labels[app()->getLocale()] ?? ($labels['en'] ?? $code)) : (string) $labels;
                    @endphp
                    <option value="{{ $code }}" @selected($selectedCountry === $code)>{{ $label }}</option>
                @endforeach
            </select>
            <x-input-error class="mt-2" :messages="$errors->get('country')" />
        </div>

        <div>
            <x-input-label for="user_lang" :value="$u('language', 'Language')" />
            <select
                id="user_lang"
                name="lang"
                class="mt-1 block w-full rounded-md border-red-200 shadow-sm focus:border-red-500 focus:ring-red-500"
                x-model="lang"
                required
            >
                <option value="cg">{{ $u('lang_cg', 'cg') }}</option>
                <option value="en">{{ $u('lang_en', 'en') }}</option>
            </select>
            <x-input-error class="mt-2" :messages="$errors->get('lang')" />
        </div>

        <div>
            <x-input-label for="user_email" :value="$u('email', 'Email')" />
            <x-text-input
                id="user_email"
                name="email"
                type="email"
                class="mt-1 block w-full"
                x-model="email"
                required
                autocomplete="username"
            />
            <x-input-error class="mt-2" :messages="$errors->get('email')" />
            <p class="mt-2 text-xs text-gray-600">
                {{ $u('email_warning', 'Changing your email changes your login. You will need to verify the new address.') }}
            </p>

            @if ($user instanceof \Illuminate\Contracts\Auth\MustVerifyEmail && ! $user->hasVerifiedEmail())
                <div class="mt-3">
                    <p class="text-sm text-gray-800">
                        {{ $u('email_unverified', 'Your email address is unverified.') }}

                        <button form="send-verification" type="submit" class="underline text-sm text-gray-600 hover:text-gray-900 rounded-md focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-red-500">
                            {{ $u('email_resend_verification', 'Click here to re-send the verification email.') }}
                        </button>
                    </p>

                    @if (session('status') === 'verification-link-sent')
                        <p class="mt-2 font-medium text-sm text-red-700">
                            {{ $u('email_verification_sent', 'A new verification link has been sent to your email address.') }}
                        </p>
                    @endif
                </div>
            @endif
        </div>

        <div class="border-t border-red-100 pt-6 space-y-6">
            <h3 class="text-base font-medium text-gray-900">{{ $u('password_section_title', 'Password') }}</h3>

            <div>
                <x-input-label for="user_current_password" :value="$u('password_current', 'Current password')" />
                <div class="mt-1 grid w-full grid-cols-1" data-pw-wrapper>
                    <x-text-input
                        id="user_current_password"
                        name="current_password"
                        type="password"
                        class="col-start-1 row-start-1 min-w-0 block w-full pr-11"
                        x-model="current_password"
                        autocomplete="current-password"
                    />
                    @include('auth.partials.password-eye-toggle-button', [
                        'showText' => \App\Support\UiText::t('auth', 'show_password'),
                        'hideText' => \App\Support\UiText::t('auth', 'hide_password'),
                    ])
                </div>
                <x-input-error class="mt-2" :messages="$errors->get('current_password')" />
            </div>

            <div>
                <x-input-label for="user_new_password" :value="$u('password_new', 'New password')" />
                <div class="mt-1 grid w-full grid-cols-1" data-pw-wrapper>
                    <x-text-input
                        id="user_new_password"
                        name="password"
                        type="password"
                        class="col-start-1 row-start-1 min-w-0 block w-full pr-11"
                        x-model="password"
                        autocomplete="new-password"
                    />
                    @include('auth.partials.password-eye-toggle-button', [
                        'showText' => \App\Support\UiText::t('auth', 'show_password'),
                        'hideText' => \App\Support\UiText::t('auth', 'hide_password'),
                    ])
                </div>
                <x-input-error class="mt-2" :messages="$errors->get('password')" />
            </div>

            <div>
                <x-input-label for="user_password_confirmation" :value="$u('password_confirm', 'Confirm new password')" />
                <div class="mt-1 grid w-full grid-cols-1" data-pw-wrapper>
                    <x-text-input
                        id="user_password_confirmation"
                        name="password_confirmation"
                        type="password"
                        class="col-start-1 row-start-1 min-w-0 block w-full pr-11"
                        x-model="password_confirmation"
                        autocomplete="new-password"
                    />
                    @include('auth.partials.password-eye-toggle-button', [
                        'showText' => \App\Support\UiText::t('auth', 'show_password'),
                        'hideText' => \App\Support\UiText::t('auth', 'hide_password'),
                    ])
                </div>
                <x-input-error class="mt-2" :messages="$errors->get('password_confirmation')" />
            </div>
        </div>

        <div class="flex flex-wrap items-center gap-3">
            <x-primary-button type="submit" x-bind:disabled="! isDirty()">
                {{ $u('save', 'Save') }}
            </x-primary-button>
            <button
                type="button"
                class="inline-flex items-center px-4 py-2 bg-white border border-red-200 rounded-md font-semibold text-xs text-gray-700 uppercase tracking-widest shadow-sm hover:bg-red-50 focus:outline-none focus:ring-2 focus:ring-red-500 focus:ring-offset-2 disabled:opacity-50"
                @click="cancel()"
                x-bind:disabled="! isDirty()"
            >
                {{ $u('cancel', 'Cancel') }}
            </button>

            @if (session('status') === 'profile_updated')
                <p
                    x-data="{ show: true }"
                    x-show="show"
                    x-transition
                    x-init="setTimeout(() => show = false, 2500)"
                    class="text-sm text-red-800"
                >
                    {{ $u('profile_saved', 'Saved.') }}
                </p>
            @endif
        </div>
    </form>
</div>

@include('auth.partials.password-toggle-script')
