<x-guest-layout :landing-background="true">
    @php
        $ui = fn (string $key, ?string $fallback = null) => \App\Support\UiText::t('auth', $key, $fallback);
    @endphp

    <div class="mb-4">
        <h1 class="text-lg font-semibold text-gray-900">{{ $ui('register_title') }}</h1>
    </div>

    <form method="POST" action="{{ route('register') }}">
        @csrf

        <!-- Name -->
        <div>
            <x-input-label for="name" :value="$ui('name', 'Name')" />
            <x-text-input id="name" class="block mt-1 w-full" type="text" name="name" :value="old('name')" required autofocus autocomplete="name" />
            <x-input-error :messages="$errors->get('name')" class="mt-2" />
        </div>

        <!-- Card billing country -->
        <div class="mt-4">
            <x-input-label for="country" :value="$ui('country', 'Card billing country')" />
            <p class="mt-1 text-sm text-gray-600">{{ $ui('country_help', 'Select the country corresponding to the billing address of the payment card.') }}</p>
            <select id="country" name="country" class="block mt-1 w-full rounded-md border-red-200 shadow-sm focus:border-red-500 focus:ring-red-500" required>
                <option value="">{{ $ui('select_country') }}</option>
                @foreach (($countries ?? []) as $code => $labels)
                    <?php $label = is_array($labels) ? ($labels[app()->getLocale()] ?? ($labels['en'] ?? $code)) : (string) $labels; ?>
                    <option value="{{ $code }}" @selected(old('country') === $code)>{{ $label }}</option>
                @endforeach
            </select>
            <x-input-error :messages="$errors->get('country')" class="mt-2" />
        </div>

        <!-- Email Address -->
        <div class="mt-4">
            <x-input-label for="email" :value="$ui('email', 'Email')" />
            <x-text-input id="email" class="block mt-1 w-full" type="email" name="email" :value="old('email')" required autocomplete="username" />
            <x-input-error :messages="$errors->get('email')" class="mt-2" />
        </div>

        <!-- Password -->
        <div class="mt-4">
            <x-input-label for="password" :value="$ui('password', 'Password')" />

            <div class="mt-1 grid w-full grid-cols-1" data-pw-wrapper>
                <x-text-input id="password" class="col-start-1 row-start-1 min-w-0 block w-full pr-11"
                                type="password"
                                name="password"
                                required autocomplete="new-password" />
                @include('auth.partials.password-eye-toggle-button', [
                    'showText' => $ui('show_password'),
                    'hideText' => $ui('hide_password'),
                ])
            </div>

            <x-input-error :messages="$errors->get('password')" class="mt-2" />
        </div>

        <!-- Confirm Password -->
        <div class="mt-4">
            <x-input-label for="password_confirmation" :value="$ui('password_confirmation', 'Confirm password')" />

            <div class="mt-1 grid w-full grid-cols-1" data-pw-wrapper>
                <x-text-input id="password_confirmation" class="col-start-1 row-start-1 min-w-0 block w-full pr-11"
                                type="password"
                                name="password_confirmation" required autocomplete="new-password" />
                @include('auth.partials.password-eye-toggle-button', [
                    'showText' => $ui('show_password'),
                    'hideText' => $ui('hide_password'),
                ])
            </div>

            <x-input-error :messages="$errors->get('password_confirmation')" class="mt-2" />
        </div>

        <div class="flex items-center justify-end mt-4">
            <a class="underline text-sm text-gray-600 hover:text-gray-900 rounded-md focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-red-500" href="{{ route('login') }}">
                {{ $ui('register_already_registered') }}
            </a>

            <x-primary-button class="ms-4">
                {{ $ui('register_button') }}
            </x-primary-button>
        </div>

        <div class="mt-4 text-center">
            <a href="{{ route('login') }}" class="underline font-semibold text-sm text-red-700 hover:text-red-900">
                {{ $ui('register_login_link') }}
            </a>
        </div>
    </form>

    @include('auth.partials.password-toggle-script')
</x-guest-layout>
