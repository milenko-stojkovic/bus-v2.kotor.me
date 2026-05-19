@php
    $p = fn (string $key, ?string $fallback = null) => \App\Support\UiText::t('panel', $key, $fallback);
    /** @var \Illuminate\Support\Collection<int, \App\Models\LimoQrToken> $tokens */
    $tokens = $tokens ?? collect();
    $slotsUsedToday = $slotsUsedToday ?? 0;
    $slotsMax = $slotsMax ?? 20;
@endphp

<x-app-layout>
    <x-slot name="header">
        <h2 class="font-semibold text-xl text-gray-800 leading-tight">{{ $p('nav_limo', 'Limo QR') }}</h2>
    </x-slot>

    <div class="py-6">
        <div class="max-w-7xl mx-auto sm:px-6 lg:px-8 space-y-6">
            @if (session('status'))
                <div class="rounded-md bg-red-50 p-3 text-sm text-red-900">{{ session('status') }}</div>
            @endif

            @if (session('limo_new_qr_token'))
                <div class="rounded-md border border-red-200 bg-red-50 p-4 text-sm text-red-900 space-y-2" role="status">
                    <p class="font-medium">{{ $p('limo_new_qr_once', 'Novi QR – prikažite ga samo jednom; sačuvajte payload za štampu.') }}</p>
                    <code class="block break-all text-xs bg-white/80 p-2 rounded border border-red-100 select-all">{{ session('limo_new_qr_token') }}</code>
                </div>
            @endif

            @if ($errors->any())
                <div class="rounded-md bg-red-50 p-4 text-sm text-red-800 space-y-1">
                    @foreach ($errors->all() as $err)
                        <div>{{ $err }}</div>
                    @endforeach
                </div>
            @endif

            <div class="bg-white overflow-hidden shadow-sm sm:rounded-lg">
                <div class="p-6 space-y-4">
                    <p class="text-sm text-gray-600">
                        {{ $p('limo_slots_hint', 'Dnevni limit i QR važe samo za današnji datum (Europe/Podgorica).') }}
                        <span class="font-medium">{{ $slotsUsedToday }}/{{ $slotsMax }}</span>
                    </p>
                    <form method="POST" action="{{ route('panel.limo.qr.generate', [], false) }}">
                        @csrf
                        <x-primary-button type="submit">{{ $p('limo_generate_qr', 'Generiši QR') }}</x-primary-button>
                    </form>
                </div>
            </div>

            <div class="bg-white overflow-hidden shadow-sm sm:rounded-lg">
                <div class="p-6 space-y-4">
                    <h3 class="text-lg font-medium text-gray-900">{{ $p('limo_active_tokens', 'Aktivni QR kodovi za danas') }}</h3>
                    @if ($tokens->isEmpty())
                        <p class="text-sm text-gray-500">{{ $p('limo_no_tokens', 'Nema aktivnih QR kodova za danas.') }}</p>
                    @else
                        <ul class="divide-y divide-gray-100">
                            @foreach ($tokens as $t)
                                <li class="py-3 flex flex-wrap items-center justify-between gap-2">
                                    <span class="text-sm text-gray-700">
                                        {{ $t->created_at?->timezone(config('app.timezone'))->format('Y-m-d H:i') ?? '—' }}
                                    </span>
                                    <a href="{{ route('panel.limo.qr.show', ['limoQrToken' => $t->id], false) }}" class="text-sm font-medium text-red-600 hover:text-red-500">
                                        {{ $p('limo_open_qr', 'Otvori QR') }}
                                    </a>
                                </li>
                            @endforeach
                        </ul>
                    @endif
                </div>
            </div>
        </div>
    </div>
</x-app-layout>
