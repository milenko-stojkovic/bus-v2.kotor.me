<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
    <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1">
        <meta name="csrf-token" content="{{ csrf_token() }}">

        <title>{{ config('app.name', 'Laravel') }}</title>

        <link rel="preconnect" href="https://fonts.bunny.net">
        <link href="https://fonts.bunny.net/css?family=figtree:400,500,600&display=swap" rel="stylesheet" />

        @vite(['resources/css/app.css', 'resources/js/app.js'])
        @include('partials.password-field-overlay-styles')
    </head>
    <body class="font-sans text-gray-900 antialiased">
        <div class="relative isolate flex min-h-screen flex-col bg-red-50 pt-6 sm:pt-0">
            @if ($landingBackground ?? false)
                <div
                    aria-hidden="true"
                    class="pointer-events-none fixed inset-0 z-0 bg-red-50"
                    style="background-image: url('{{ asset('images/background.svg') }}'); background-repeat: no-repeat; background-position: center center; background-size: 50% auto;"
                ></div>
            @endif
            <div class="relative z-10 mb-6 flex w-full flex-1 flex-col items-center pt-6 sm:mb-8 sm:justify-center sm:pt-0">
                <div class="w-full sm:max-w-lg flex justify-end px-6">
                    <div class="flex items-center gap-3">
                        <a
                            href="{{ url('/locale/cg') }}"
                            class="inline-flex rounded transition focus:outline-none focus-visible:ring-2 focus-visible:ring-red-600 focus-visible:ring-offset-2 {{ app()->getLocale() === 'cg' ? 'ring-2 ring-red-700 ring-offset-2 ring-offset-red-50' : 'opacity-60 hover:opacity-100' }}"
                            title="Crnogorski"
                            aria-label="Crnogorski"
                        >
                            <img src="{{ asset('images/cg.png') }}" alt="" class="block h-6 w-auto" decoding="async" />
                            <span class="sr-only">CG</span>
                        </a>
                        <a
                            href="{{ url('/locale/en') }}"
                            class="inline-flex rounded transition focus:outline-none focus-visible:ring-2 focus-visible:ring-red-600 focus-visible:ring-offset-2 {{ app()->getLocale() === 'en' ? 'ring-2 ring-red-700 ring-offset-2 ring-offset-red-50' : 'opacity-60 hover:opacity-100' }}"
                            title="English"
                            aria-label="English"
                        >
                            <img src="{{ asset('images/en.png') }}" alt="" class="block h-6 w-auto" decoding="async" />
                            <span class="sr-only">EN</span>
                        </a>
                    </div>
                </div>
                <div class="w-full sm:max-w-lg mx-auto px-6">
                    <a href="/">
                        <x-application-logo class="mx-auto block max-w-full" style="height: 5rem; width: auto;" />
                    </a>
                </div>

                <div class="w-full sm:max-w-lg mt-6 px-6 py-4 bg-white shadow-md overflow-hidden sm:rounded-lg">
                    {{ $slot }}
                </div>
            </div>

            @include('partials.site-footer')
        </div>
    </body>
</html>
