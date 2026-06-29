<section>
    <header>
        <h2 class="text-lg font-medium text-gray-900">
            {{ __('Profile Information') }}
        </h2>

        <p class="mt-1 text-sm text-gray-600">
            {{ __("Update your account's profile information and email address.") }}
        </p>
    </header>

    <form id="send-verification" method="post" action="{{ route('verification.send') }}">
        @csrf
    </form>

    <form method="post" action="{{ route('profile.update') }}" class="mt-6 space-y-6">
        @csrf
        @method('patch')

        <div>
            <x-input-label for="name" :value="__('Name')" />
            <x-text-input id="name" name="name" type="text" class="mt-1 block w-full" :value="old('name', $user->name)" required autofocus autocomplete="name" />
            <x-input-error class="mt-2" :messages="$errors->get('name')" />
        </div>

        <div>
            <x-input-label for="email" :value="__('Email')" />
            <x-text-input id="email" name="email" type="email" class="mt-1 block w-full" :value="old('email', $user->email)" required autocomplete="username" />
            <x-input-error class="mt-2" :messages="$errors->get('email')" />
            <p class="mt-2 text-xs text-gray-600">
                {{ app()->getLocale() === 'cg'
                    ? 'Ako promijenite email adresu, za naredna prijavljivanja koristićete novu email adresu.'
                    : 'If you change your email address, you will use the new email address for future sign-ins.' }}
            </p>

            @if ($user instanceof \Illuminate\Contracts\Auth\MustVerifyEmail && ! $user->hasVerifiedEmail())
                <div>
                    <p class="text-sm mt-2 text-gray-800">
                        {{ __('Your email address is unverified.') }}

                        <button form="send-verification" class="underline text-sm text-gray-600 hover:text-gray-900 rounded-md focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-red-500">
                            {{ __('Click here to re-send the verification email.') }}
                        </button>
                    </p>

                    @if (session('status') === 'verification-link-sent')
                        <p class="mt-2 font-medium text-sm text-red-700">
                            {{ __('A new verification link has been sent to your email address.') }}
                        </p>
                    @endif
                </div>
            @endif
        </div>

        <div>
            <x-input-label for="lang" :value="__('Language')" />
            <select id="lang" name="lang" class="mt-1 block w-full rounded-md border-red-200 shadow-sm focus:border-red-500 focus:ring-red-500">
                <option value="cg" @selected(old('lang', $user->lang) === 'cg')>cg</option>
                <option value="en" @selected(old('lang', $user->lang) === 'en')>en</option>
            </select>
            <x-input-error class="mt-2" :messages="$errors->get('lang')" />
        </div>

        <div>
            <x-input-label for="country" :value="__('Card billing country')" />
            @php($countries = \App\Support\BankartBillingCountry::selectableCountries())
            <p class="mt-1 text-sm text-gray-600">
                {{ \App\Support\UiText::t('auth', 'country_help', 'Select the country corresponding to the billing address of the payment card.') }}
            </p>
            <select id="country" name="country" class="mt-1 block w-full rounded-md border-red-200 shadow-sm focus:border-red-500 focus:ring-red-500" required>
                <option value="">{{ app()->getLocale() === 'cg' ? 'Odaberite državu' : 'Select country' }}</option>
                @foreach ($countries as $code => $labels)
                    @php
                        $label = is_array($labels) ? ($labels[app()->getLocale()] ?? ($labels['en'] ?? $code)) : (string) $labels;
                        $current = old('country', $user->country);
                        $selected = \App\Support\BankartBillingCountry::isSelectablePaymentCountry($current) ? $current : old('country', '');
                    @endphp
                    <option value="{{ $code }}" @selected($selected === $code)>{{ $label }}</option>
                @endforeach
            </select>
            <x-input-error class="mt-2" :messages="$errors->get('country')" />
        </div>

        <div class="flex items-center gap-4">
            <x-primary-button>{{ __('Save') }}</x-primary-button>

            @if (session('status') === 'profile_updated')
                <p
                    x-data="{ show: true }"
                    x-show="show"
                    x-transition
                    x-init="setTimeout(() => show = false, 2000)"
                    class="text-sm text-gray-600"
                >{{ __('Saved.') }}</p>
            @endif
        </div>
    </form>
</section>
