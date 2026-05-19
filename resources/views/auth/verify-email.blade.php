<x-guest-layout>
    @php
        $ui = fn (string $key, ?string $fallback = null) => \App\Support\UiText::t('auth', $key, $fallback);
    @endphp

    <div class="mb-4 text-sm text-gray-600">
        {{ $ui('verify_prompt') }}
    </div>

    @if (session('status') == 'verification-link-sent')
        <div class="mb-4 font-medium text-sm text-red-700">
            {{ $ui('verify_link_sent') }}
        </div>
    @endif

    <div class="mt-4 flex items-center justify-between">
        <form method="POST" action="{{ route('verification.send') }}">
            @csrf

            <div>
                <x-primary-button>
                    {{ $ui('verify_resend_button') }}
                </x-primary-button>
            </div>
        </form>

        <form method="POST" action="{{ route('logout') }}">
            @csrf

            <button type="submit" class="underline text-sm text-gray-600 hover:text-gray-900 rounded-md focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-red-500">
                {{ $ui('logout_button') }}
            </button>
        </form>
    </div>
</x-guest-layout>
