<x-guest-layout :landing-background="true">
    @php
        $ui = fn (string $key) => \App\Support\UiText::t('landing', $key);
    @endphp

    <div class="space-y-4">
        <div class="space-y-1">
            <h1 class="text-lg font-semibold text-gray-900">
                {{ $ui('title') }}
            </h1>
            <p class="text-sm text-gray-600">
                {{ $ui('subtitle') }}
            </p>
        </div>

        <div class="space-y-3">
            <div class="rounded-lg border border-gray-200 p-4">
                <div class="font-semibold text-gray-900">{{ $ui('guest_title') }}</div>
                <div class="mt-1 text-sm text-gray-600">
                    {{ $ui('guest_description') }}
                </div>
                <a
                    href="{{ route('guest.reserve', [], false) }}"
                    class="mt-3 inline-flex w-full items-center justify-center rounded-md bg-red-700 px-4 py-2 text-xs font-semibold uppercase tracking-widest text-white hover:bg-red-800 focus:outline-none focus:ring-2 focus:ring-red-600 focus:ring-offset-2 transition"
                >
                    {{ $ui('guest_cta') }}
                </a>
            </div>

            <div class="rounded-lg border border-gray-200 p-4">
                <div class="font-semibold text-gray-900">{{ $ui('agency_title') }}</div>
                <div class="mt-1 text-sm text-gray-600">
                    {{ $ui('agency_description') }}
                </div>
                <a
                    href="{{ route('login', [], false) }}"
                    class="mt-3 inline-flex w-full items-center justify-center rounded-md border border-gray-300 bg-white px-4 py-2 text-xs font-semibold uppercase tracking-widest text-gray-800 hover:bg-gray-50 focus:outline-none focus:ring-2 focus:ring-red-600 focus:ring-offset-2 transition"
                >
                    {{ $ui('agency_cta') }}
                </a>
            </div>
        </div>
    </div>
</x-guest-layout>

