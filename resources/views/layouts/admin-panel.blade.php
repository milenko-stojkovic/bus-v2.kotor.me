<!DOCTYPE html>
<html lang="sr-Latn-ME">
    <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1">
        <meta name="csrf-token" content="{{ csrf_token() }}">
        @stack('head')

        <title>{{ $pageTitle ?? 'Admin' }} — {{ config('app.name', 'Laravel') }}</title>

        <link rel="preconnect" href="https://fonts.bunny.net">
        <link href="https://fonts.bunny.net/css?family=figtree:400,500,600&display=swap" rel="stylesheet" />

        @vite(['resources/css/app.css', 'resources/js/app.js'])
        @include('partials.password-field-overlay-styles')
    </head>
    <body class="font-sans text-gray-900 antialiased bg-red-50">
        <div class="min-h-screen">
            <header class="bg-white border-b border-red-100 shadow-sm">
                <div class="max-w-6xl mx-auto px-4 sm:px-6 lg:px-8 py-3 flex flex-wrap items-center justify-between gap-3">
                    <span class="text-sm font-semibold text-gray-800">Admin panel</span>
                    <nav class="flex flex-wrap gap-x-2 gap-y-1 text-xs sm:text-sm">
                        <a href="{{ route('panel_admin.dashboard', [], false) }}" class="px-2 py-1 rounded {{ ($navActive ?? '') === 'dashboard' ? 'bg-red-100 text-red-900 font-medium' : 'text-gray-600 hover:text-gray-900' }}">Upozorenja / Informacije</a>
                        <a href="{{ route('panel_admin.system-status', [], false) }}" class="px-2 py-1 rounded {{ ($navActive ?? '') === 'system-status' ? 'bg-red-100 text-red-900 font-medium' : 'text-gray-600 hover:text-gray-900' }}">Sistem status</a>
                        <a href="{{ route('panel_admin.blocking', [], false) }}" class="px-2 py-1 rounded {{ ($navActive ?? '') === 'blocking' ? 'bg-red-100 text-red-900 font-medium' : 'text-gray-600 hover:text-gray-900' }}">Blokiranje</a>
                        <a href="{{ route('panel_admin.free-reservations', [], false) }}" class="px-2 py-1 rounded {{ ($navActive ?? '') === 'free-reservations' ? 'bg-red-100 text-red-900 font-medium' : 'text-gray-600 hover:text-gray-900' }}">Besplatne rezervacije</a>
                        <a href="{{ route('panel_admin.reservations', [], false) }}" class="px-2 py-1 rounded {{ ($navActive ?? '') === 'reservations' ? 'bg-red-100 text-red-900 font-medium' : 'text-gray-600 hover:text-gray-900' }}">Rezervacije</a>
                        <a href="{{ route('panel_admin.agencies.index', [], false) }}" class="px-2 py-1 rounded {{ ($navActive ?? '') === 'agencies' ? 'bg-red-100 text-red-900 font-medium' : 'text-gray-600 hover:text-gray-900' }}">Agencije</a>
                        <a href="{{ route('panel_admin.insight', [], false) }}" class="px-2 py-1 rounded {{ ($navActive ?? '') === 'insight' ? 'bg-red-100 text-red-900 font-medium' : 'text-gray-600 hover:text-gray-900' }}">Uvid</a>
                        <a href="{{ route('panel_admin.reports', [], false) }}" class="px-2 py-1 rounded {{ ($navActive ?? '') === 'reports' ? 'bg-red-100 text-red-900 font-medium' : 'text-gray-600 hover:text-gray-900' }}">Izvještaji</a>
                        <a href="{{ route('panel_admin.settings', [], false) }}" class="px-2 py-1 rounded {{ ($navActive ?? '') === 'settings' ? 'bg-red-100 text-red-900 font-medium' : 'text-gray-600 hover:text-gray-900' }}">Podešavanja</a>
                        <a href="{{ route('panel_admin.analytics', [], false) }}" class="px-2 py-1 rounded {{ ($navActive ?? '') === 'analytics' ? 'bg-red-100 text-red-900 font-medium' : 'text-gray-600 hover:text-gray-900' }}">Analitika</a>
                        <a href="{{ route('panel_admin.archive.failed', [], false) }}" class="px-2 py-1 rounded {{ ($navActive ?? '') === 'archive-failed' ? 'bg-red-100 text-red-900 font-medium' : 'text-gray-600 hover:text-gray-900' }}">Sistemska arhiva — neuspjeli</a>
                        <a href="{{ route('admin.limo.index', [], false) }}" class="px-2 py-1 rounded {{ ($navActive ?? '') === 'limo' ? 'bg-red-100 text-red-900 font-medium' : 'text-gray-600 hover:text-gray-900' }}">Limo događaji</a>
                    </nav>
                    <form method="POST" action="{{ route('panel_admin.logout', [], false) }}" class="shrink-0">
                        @csrf
                        <button type="submit" class="text-xs sm:text-sm text-gray-600 hover:text-gray-900 underline">Odjavi se</button>
                    </form>
                </div>
            </header>

            <div class="max-w-6xl mx-auto px-4 py-6 sm:px-6 lg:px-8">
                {{ $slot }}
            </div>
        </div>
    </body>
</html>
