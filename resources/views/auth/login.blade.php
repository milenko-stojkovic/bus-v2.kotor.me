<x-guest-layout :landing-background="true">
    @php
        $ui = fn (string $key, ?string $fallback = null) => \App\Support\UiText::t('auth', $key, $fallback);
    @endphp

    <!-- Session Status -->
    <x-auth-session-status class="mb-4" :status="session('status')" />

    <div class="mb-4">
        <h1 class="text-lg font-semibold text-gray-900">{{ $ui('login_title') }}</h1>
    </div>

    <form method="POST" action="{{ route('login') }}">
        @csrf

        <!-- Email Address -->
        <div>
            <x-input-label for="email" :value="$ui('email', 'Email')" />
            <x-text-input id="email" class="block mt-1 w-full" type="email" name="email" :value="old('email')" required autofocus autocomplete="username" />
            <x-input-error :messages="$errors->get('email')" class="mt-2" />
        </div>

        <!-- Password -->
        <div class="mt-4">
            <x-input-label for="password" :value="$ui('password', 'Password')" />

            <div class="mt-1 grid w-full grid-cols-1" data-pw-wrapper>
                <x-text-input id="password" class="col-start-1 row-start-1 min-w-0 block w-full pr-11"
                                type="password"
                                name="password"
                                required autocomplete="current-password" />
                @include('auth.partials.password-eye-toggle-button', [
                    'showText' => $ui('show_password'),
                    'hideText' => $ui('hide_password'),
                ])
            </div>

            <x-input-error :messages="$errors->get('password')" class="mt-2" />
        </div>

        <!-- Remember Me -->
        <div class="block mt-4">
            <label for="remember_me" class="inline-flex items-center">
                <input id="remember_me" type="checkbox" class="rounded border-red-200 text-red-600 shadow-sm focus:ring-red-500" name="remember">
                <span class="ms-2 text-sm text-gray-600">{{ $ui('remember_me') }}</span>
            </label>
        </div>

        <div class="flex items-center justify-end mt-4">
            @if (Route::has('password.request'))
                <a class="underline text-sm text-gray-600 hover:text-gray-900 rounded-md focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-red-500" href="{{ route('password.request') }}">
                    {{ $ui('forgot_password') }}
                </a>
            @endif

            <x-primary-button class="ms-3">
                {{ $ui('login_button') }}
            </x-primary-button>
        </div>

        <div class="mt-6 text-center">
            <span class="text-sm text-gray-700">
                {{ $ui('login_no_account') }}
            </span>
            <a
                href="{{ route('register') }}"
                class="ms-1 underline font-semibold text-sm text-red-700 hover:text-red-900"
            >
                {{ $ui('login_create_account') }}.
            </a>
        </div>
    </form>

    @include('auth.partials.password-toggle-script')
</x-guest-layout>
