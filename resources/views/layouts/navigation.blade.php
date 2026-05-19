@php
    $p = fn (string $key, ?string $fallback = null) => \App\Support\UiText::t('panel', $key, $fallback);
    $advanceEnabled = (bool) config('features.advance_payments');
    $limoEnabled = $advanceEnabled && (bool) config('features.limo_service');
@endphp
<nav x-data="{ open: false }" class="border-b border-black/10 bg-[#9e1321] shadow-sm">
    <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8">
        <div class="flex justify-between h-16">
            <div class="flex min-w-0 flex-1">
                <div class="shrink-0 flex items-center">
                    <a href="{{ route('landing', [], false) }}">
                        <x-application-logo :white="true" class="block h-9 w-auto object-contain" />
                    </a>
                </div>

                <div class="hidden sm:flex sm:items-center sm:flex-wrap sm:gap-x-3 sm:gap-y-1 sm:ms-4 lg:gap-x-5 lg:ms-8">
                    <x-nav-link :href="route('panel.reservations')" :active="request()->routeIs('panel.reservations', 'panel.reservations.invoice')">
                        {{ $p('nav_reservations', 'Reservations') }}
                    </x-nav-link>
                    @if ($advanceEnabled)
                        <x-nav-link :href="route('panel.advance.index')" :active="request()->routeIs('panel.advance.*')">
                            {{ $p('nav_advance', 'Avans') }}
                        </x-nav-link>
                        @if ($limoEnabled)
                            <x-nav-link :href="route('panel.limo.index')" :active="request()->routeIs('panel.limo.*')">
                                {{ $p('nav_limo', 'Limo QR') }}
                            </x-nav-link>
                        @else
                            <span class="px-3 py-2 text-sm text-white/40 cursor-not-allowed" title="{{ $p('limo_disabled_hint', 'Limo servis trenutno nije dostupan.') }}">
                                {{ $p('nav_limo', 'Limo QR') }}
                            </span>
                        @endif
                    @else
                        <span class="px-3 py-2 text-sm text-white/40 cursor-not-allowed" title="{{ $p('advance_disabled_hint', 'Funkcionalnost trenutno nije dostupna') }}">
                            {{ $p('nav_advance', 'Avans') }}
                        </span>
                    @endif
                    <x-nav-link :href="route('panel.user')" :active="request()->routeIs('panel.user')">
                        {{ $p('nav_user', 'User') }}
                    </x-nav-link>
                    <x-nav-link :href="route('panel.vehicles')" :active="request()->routeIs('panel.vehicles', 'panel.vehicles.store', 'panel.vehicles.update', 'panel.vehicles.destroy')">
                        {{ $p('nav_vehicles', 'Vehicles') }}
                    </x-nav-link>
                    <x-nav-link :href="route('panel.fzbr.create')" :active="request()->routeIs('panel.fzbr.*')">
                        {{ $p('nav_fzbr', 'FZBR') }}
                    </x-nav-link>
                    <x-nav-link :href="route('panel.upcoming')" :active="request()->routeIs('panel.upcoming')">
                        {{ $p('nav_upcoming', 'Upcoming reservations') }}
                    </x-nav-link>
                    <x-nav-link :href="route('panel.realized')" :active="request()->routeIs('panel.realized')">
                        {{ $p('nav_realized', 'Realized reservations') }}
                    </x-nav-link>
                    <x-nav-link :href="route('panel.statistics')" :active="request()->routeIs('panel.statistics')">
                        {{ $p('nav_statistics', 'Statistic') }}
                    </x-nav-link>
                    @if(Auth::user()?->isAdmin())
                        <x-nav-link :href="route('staff.reservations.index')" :active="request()->routeIs('staff.*')">
                            Admin
                        </x-nav-link>
                    @endif
                </div>
            </div>

            <div class="hidden sm:flex sm:items-center sm:shrink-0 sm:gap-3 sm:ms-2 lg:ms-4">
                @include('partials.nav-locale-icons')
                <x-dropdown align="right" width="48">
                    <x-slot name="trigger">
                        <button type="button" class="inline-flex items-center px-3 py-2 border border-transparent text-sm leading-4 font-medium rounded-md text-white bg-transparent hover:bg-white/10 focus:outline-none focus-visible:ring-2 focus-visible:ring-white/50 transition ease-in-out duration-150">
                            <div>{{ Auth::user()->name }}</div>
                            <div class="ms-1">
                                <svg class="fill-current h-4 w-4" xmlns="http://www.w3.org/2000/svg" viewBox="0 0 20 20">
                                    <path fill-rule="evenodd" d="M5.293 7.293a1 1 0 011.414 0L10 10.586l3.293-3.293a1 1 0 111.414 1.414l-4 4a1 1 0 01-1.414 0l-4-4a1 1 0 010-1.414z" clip-rule="evenodd" />
                                </svg>
                            </div>
                        </button>
                    </x-slot>

                    <x-slot name="content">
                        <x-dropdown-link :href="route('panel.user')">
                            {{ $p('nav_user', 'User') }}
                        </x-dropdown-link>

                        <form method="POST" action="{{ route('logout') }}">
                            @csrf

                            <x-dropdown-link :href="route('logout')"
                                    onclick="event.preventDefault();
                                                this.closest('form').submit();">
                                {{ __('Log Out') }}
                            </x-dropdown-link>
                        </form>
                    </x-slot>
                </x-dropdown>
            </div>

            <div class="-me-2 flex items-center sm:hidden">
                <button type="button" @click="open = ! open" class="inline-flex items-center justify-center p-2 rounded-md text-white hover:bg-white/10 focus:outline-none focus:bg-white/10 focus-visible:ring-2 focus-visible:ring-white/50 transition duration-150 ease-in-out">
                    <svg class="h-6 w-6" stroke="currentColor" fill="none" viewBox="0 0 24 24">
                        <path :class="{'hidden': open, 'inline-flex': ! open }" class="inline-flex" stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 6h16M4 12h16M4 18h16" />
                        <path :class="{'hidden': ! open, 'inline-flex': open }" class="hidden" stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12" />
                    </svg>
                </button>
            </div>
        </div>
    </div>

    <div :class="{'block': open, 'hidden': ! open}" class="hidden sm:hidden">
        <div class="flex justify-end border-b border-white/15 px-4 py-3">
            @include('partials.nav-locale-icons')
        </div>
        <div class="pt-2 pb-3 space-y-1 px-2">
            <x-responsive-nav-link :href="route('panel.reservations')" :active="request()->routeIs('panel.reservations', 'panel.reservations.invoice')">
                {{ $p('nav_reservations', 'Reservations') }}
            </x-responsive-nav-link>
            @if ($advanceEnabled)
                <x-responsive-nav-link :href="route('panel.advance.index')" :active="request()->routeIs('panel.advance.*')">
                    {{ $p('nav_advance', 'Avans') }}
                </x-responsive-nav-link>
                @if ($limoEnabled)
                    <x-responsive-nav-link :href="route('panel.limo.index')" :active="request()->routeIs('panel.limo.*')">
                        {{ $p('nav_limo', 'Limo QR') }}
                    </x-responsive-nav-link>
                @else
                    <span class="block px-3 py-2 text-sm text-white/40 cursor-not-allowed" title="{{ $p('limo_disabled_hint', 'Limo servis trenutno nije dostupan.') }}">
                        {{ $p('nav_limo', 'Limo QR') }}
                    </span>
                @endif
            @else
                <span class="block px-3 py-2 text-sm text-white/40 cursor-not-allowed" title="{{ $p('advance_disabled_hint', 'Funkcionalnost trenutno nije dostupna') }}">
                    {{ $p('nav_advance', 'Avans') }}
                </span>
            @endif
            <x-responsive-nav-link :href="route('panel.user')" :active="request()->routeIs('panel.user')">
                {{ $p('nav_user', 'User') }}
            </x-responsive-nav-link>
            <x-responsive-nav-link :href="route('panel.vehicles')" :active="request()->routeIs('panel.vehicles', 'panel.vehicles.store', 'panel.vehicles.update', 'panel.vehicles.destroy')">
                {{ $p('nav_vehicles', 'Vehicles') }}
            </x-responsive-nav-link>
            <x-responsive-nav-link :href="route('panel.fzbr.create')" :active="request()->routeIs('panel.fzbr.*')">
                {{ $p('nav_fzbr', 'FZBR') }}
            </x-responsive-nav-link>
            <x-responsive-nav-link :href="route('panel.upcoming')" :active="request()->routeIs('panel.upcoming')">
                {{ $p('nav_upcoming', 'Upcoming reservations') }}
            </x-responsive-nav-link>
            <x-responsive-nav-link :href="route('panel.realized')" :active="request()->routeIs('panel.realized')">
                {{ $p('nav_realized', 'Realized reservations') }}
            </x-responsive-nav-link>
            <x-responsive-nav-link :href="route('panel.statistics')" :active="request()->routeIs('panel.statistics')">
                {{ $p('nav_statistics', 'Statistic') }}
            </x-responsive-nav-link>
            @if(Auth::user()?->isAdmin())
                <x-responsive-nav-link :href="route('staff.reservations.index')" :active="request()->routeIs('staff.*')">
                    Admin
                </x-responsive-nav-link>
            @endif
        </div>

        <div class="pt-4 pb-1 border-t border-white/15">
            <div class="px-4">
                <div class="font-medium text-base text-white">{{ Auth::user()->name }}</div>
                <div class="font-medium text-sm text-white/75">{{ Auth::user()->email }}</div>
            </div>

            <div class="mt-3 space-y-1 px-2">
                <x-responsive-nav-link :href="route('panel.user')">
                    {{ $p('nav_user', 'User') }}
                </x-responsive-nav-link>

                <form method="POST" action="{{ route('logout') }}">
                    @csrf

                    <x-responsive-nav-link :href="route('logout')"
                            onclick="event.preventDefault();
                                        this.closest('form').submit();">
                        {{ __('Log Out') }}
                    </x-responsive-nav-link>
                </form>
            </div>
        </div>
    </div>
</nav>
