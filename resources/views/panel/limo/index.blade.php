@php
    $p = fn (string $key, ?string $fallback = null) => \App\Support\UiText::t('panel', $key, $fallback);
    $locale = app()->getLocale();
    $pickupMapUrl = 'https://maps.app.goo.gl/BkmeQ1ZAo8XG1FDb6';
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
        <div class="max-w-3xl mx-auto sm:px-6 lg:px-8 space-y-6">
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

            @php
                $pickupLinkText = $p(
                    'limo_notice_pickup_place',
                    $locale === 'en'
                        ? 'the bus stop by the city market'
                        : 'autobuskom stajalištu kod gradske pijace',
                );

                $pickupLinkHtml = '<a href="' . e($pickupMapUrl) . '" target="_blank" rel="noopener noreferrer" class="font-medium text-red-700 hover:text-red-600 underline decoration-red-200 underline-offset-2">' . e($pickupLinkText) . '</a>';
                $p1Escaped = e($p(
                    'limo_notice_p1',
                    $locale === 'en'
                        ? 'Agencies that provide limo service must pick up passengers at the designated location — :pickup_link.'
                        : 'Agencije koje pružaju uslugu limo servisa imaju obavezu da ukrcavanje putnika vrše na za to predviđenom mjestu — :pickup_link.',
                ));
            @endphp

            <div class="bg-white overflow-hidden shadow-sm sm:rounded-lg">
                <div class="p-6 space-y-4 text-sm text-gray-700">
                    <h3 class="text-base font-semibold text-gray-900">
                        {{ $p('limo_notice_title', $locale === 'en' ? 'Important information' : 'Važne informacije') }}
                    </h3>

                    <p>{!! str_replace(':pickup_link', $pickupLinkHtml, $p1Escaped) !!}</p>

                    <p>
                        {{ $p(
                            'limo_notice_p2',
                            $locale === 'en'
                                ? 'At that location, the provision of the limo service is recorded by an authorized officer — the limo registrar.'
                                : 'Na tom mjestu vrši se evidentiranje pružanja limo usluge od strane službenog lica — limo evidentičara.',
                        ) }}
                    </p>

                    <p>
                        {{ $p(
                            'limo_notice_p3',
                            $locale === 'en'
                                ? 'Registration is done by scanning a one-time, single-day QR code generated on this page.'
                                : 'Evidentiranje se vrši skeniranjem jednokratnog, jednodnevnog QR koda koji se generiše na ovoj stranici.',
                        ) }}
                    </p>

                    <div class="space-y-2">
                        <p class="font-medium text-gray-900">
                            {{ $p(
                                'limo_notice_effects_intro',
                                $locale === 'en'
                                    ? 'Scanning the QR code results in:'
                                    : 'Skeniranje QR koda ima za posljedicu:',
                            ) }}
                        </p>
                        <ul class="list-disc pl-5 space-y-1">
                            <li>{{ $p('limo_notice_effect_1', $locale === 'en' ? 'deducting the amount from the agency’s advance balance,' : 'umanjenje ukupnog avansa agencije,') }}</li>
                            <li>{{ $p('limo_notice_effect_2', $locale === 'en' ? 'issuing a fiscal invoice,' : 'izradu fiskalnog računa,') }}</li>
                            <li>{{ $p('limo_notice_effect_3', $locale === 'en' ? 'sending the fiscal invoice to the agency email,' : 'slanje fiskalnog računa na email agencije,') }}</li>
                            <li>{{ $p('limo_notice_effect_4', $locale === 'en' ? 'and marking the QR code as used (invalid).' : 'i označavanje QR koda kao iskorištenog (ništavnog).') }}</li>
                        </ul>
                    </div>

                    <div class="space-y-2">
                        <p class="font-medium text-gray-900">
                            {{ $p(
                                'limo_notice_requirements_intro',
                                $locale === 'en'
                                    ? 'To provide limo service, the agency must:'
                                    : 'Da bi agencija mogla pružati limo uslugu, potrebno je da:',
                            ) }}
                        </p>
                        <ul class="list-disc pl-5 space-y-1">
                            <li>{{ $p('limo_notice_req_1', $locale === 'en' ? 'be registered on the Bus Kotor service,' : 'bude registrovana na Bus Kotor servisu,') }}</li>
                            <li>{{ $p('limo_notice_req_2', $locale === 'en' ? 'have sufficient advance balance at the time of registration,' : 'posjeduje dovoljan avans u trenutku evidencije,') }}</li>
                            <li>{{ $p('limo_notice_req_3', $locale === 'en' ? 'generate a QR code that will be used during the day,' : 'generiše QR kod koji će biti iskorišten tokom dana,') }}</li>
                            <li>{{ $p('limo_notice_req_4', $locale === 'en' ? 'present the generated QR code to the registrar.' : 'pokaže generisani QR kod evidentičaru.') }}</li>
                        </ul>
                    </div>

                    <div class="space-y-2">
                        <p class="font-medium text-gray-900">
                            {{ $p(
                                'limo_notice_qr_can_be_intro',
                                $locale === 'en'
                                    ? 'The QR code may be:'
                                    : 'QR kod može biti:',
                            ) }}
                        </p>
                        <ul class="list-disc pl-5 space-y-1">
                            <li>{{ $p('limo_notice_qr_can_be_1', $locale === 'en' ? 'printed on paper,' : 'odštampan na papiru,') }}</li>
                            <li>{{ $p('limo_notice_qr_can_be_2', $locale === 'en' ? 'or shown as an image on a device screen.' : 'ili prikazan kao slika na ekranu uređaja.') }}</li>
                        </ul>
                    </div>
                </div>
            </div>

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
