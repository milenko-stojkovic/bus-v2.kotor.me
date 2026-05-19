<x-guest-layout :landing-background="true">
    @php
        $ui = fn (string $key, ?string $fallback = null) => \App\Support\UiText::t('auth', $key, $fallback);
    @endphp

    <div class="mb-4 text-sm text-gray-600">
        {{ __('This is a secure area of the application. Please confirm your password before continuing.') }}
    </div>

    <form method="POST" action="{{ route('password.confirm') }}">
        @csrf

        <!-- Password -->
        <div>
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

        <div class="flex justify-end mt-4">
            <x-primary-button>
                {{ __('Confirm') }}
            </x-primary-button>
        </div>
    </form>

    @include('auth.partials.password-toggle-script')
</x-guest-layout>
