@php
    $p = fn (string $key, ?string $fallback = null) => \App\Support\UiText::t('panel', $key, $fallback);
    $locale = app()->getLocale();
    $pickupMapUrl1 = 'https://maps.app.goo.gl/BkmeQ1ZAo8XG1FDb6';
    $pickupMapUrl2 = 'https://maps.app.goo.gl/qdy9YmTLRBsggPCD6';

    $pickupLinkText1 = $p(
        'limo_info_pickup_place_1',
        $locale === 'en'
            ? 'bus stop beside the theatre, near the city market'
            : 'autobusko stajalište pored pozorišta, kod gradske pijace',
    );
    $pickupLinkText2 = $p(
        'limo_info_pickup_place_2',
        $locale === 'en'
            ? 'exit from Riva parking area, across from the city market'
            : 'izlaz iz parking prostora Riva, preko puta gradske pijace',
    );

    $pickupLinkHtml1 = '<a href="' . e($pickupMapUrl1) . '" target="_blank" rel="noopener noreferrer" class="font-medium text-red-700 hover:text-red-600 underline decoration-red-200 underline-offset-2">' . e($pickupLinkText1) . '</a>';
    $pickupLinkHtml2 = '<a href="' . e($pickupMapUrl2) . '" target="_blank" rel="noopener noreferrer" class="font-medium text-red-700 hover:text-red-600 underline decoration-red-200 underline-offset-2">' . e($pickupLinkText2) . '</a>';
@endphp

<x-app-layout>
    <x-slot name="header">
        <h2 class="font-semibold text-xl text-gray-800 leading-tight">{{ $p('nav_limo', 'Limo') }}</h2>
    </x-slot>

    <div class="py-6">
        <div class="max-w-3xl mx-auto sm:px-6 lg:px-8 space-y-6">
            <div class="bg-white overflow-hidden shadow-sm sm:rounded-lg">
                <div class="p-6 space-y-4 text-sm text-gray-700">
                    <h3 class="text-base font-semibold text-gray-900">
                        {{ $p('limo_info_title', $locale === 'en' ? 'Limo service' : 'Limo usluga') }}
                    </h3>

                    <p>
                        {{ $p(
                            'limo_info_intro',
                            $locale === 'en'
                                ? 'This page provides operational information for agencies whose vehicles provide Limo service.'
                                : 'Ova stranica sadrži operativne informacije za agencije čija vozila pružaju limo uslugu.',
                        ) }}
                    </p>

                    <p>
                        {{ $p(
                            'limo_info_daily_fee',
                            $locale === 'en'
                                ? 'Each vehicle providing Limo service must have a paid Daily fee for the day the service is provided. Purchase the Daily fee under Reservations in the menu — it applies to one vehicle and one calendar day. Payment is by card or from advance.'
                                : 'Za svako vozilo koje pruža limo uslugu mora biti plaćena dnevna naknada za dan u kojem se usluga obavlja. Dnevna naknada kupuje se kroz meni Rezervacije i važi za jedno vozilo i jedan kalendarski dan. Plaćanje je moguće karticom ili iz avansa.',
                        ) }}
                    </p>

                    <p>
                        {{ $p(
                            'limo_info_zone_pickup_intro',
                            $locale === 'en'
                                ? 'If passenger pick-up or drop-off takes place in the narrower city center zone, from the bus station to shopping center Kamelija, only these approved locations may be used:'
                                : 'Ako se ukrcaj ili iskrcaj putnika vrši u užem centru grada, od autobuske stanice do tržnog centra Kamelija, dozvoljeno je koristiti samo odobrena mjesta:',
                        ) }}
                    </p>

                    <ul class="list-disc list-inside space-y-1 text-gray-700">
                        <li>{!! $pickupLinkHtml1 !!}</li>
                        <li>{!! $pickupLinkHtml2 !!}</li>
                    </ul>

                    <p>
                        {{ $p(
                            'limo_info_outside_zone',
                            $locale === 'en'
                                ? 'If Limo service is provided outside that zone, pick-up and drop-off may take place at other locations, provided traffic regulations and road safety rules are respected.'
                                : 'Ako se limo usluga pruža van navedene zone, ukrcaj i iskrcaj mogu se vršiti i na drugim lokacijama, uz poštovanje saobraćajnih propisa i pravila bezbjednosti saobraćaja.',
                        ) }}
                    </p>

                    <p class="font-medium text-gray-900">
                        {{ $p(
                            'limo_info_benovo_ban',
                            $locale === 'en'
                                ? 'Limo vehicles (Personal vehicle (4+1, 5+1, 6+1 and 7+1 seats)) may not pick up or drop off passengers at the Benovo location.'
                                : 'Limo vozilima (Putničko vozilo (4+1, 5+1, 6+1 i 7+1 mjesta)) nije dozvoljen ukrcaj niti iskrcaj putnika na lokaciji Benovo.',
                        ) }}
                    </p>

                    <p>
                        {{ $p(
                            'limo_info_control',
                            $locale === 'en'
                                ? 'Compliance is checked by municipal police and other authorized persons. The driver or responsible person should have proof of the paid Daily fee available by email or PDF.'
                                : 'Kontrolu vrši komunalna policija i druga ovlašćena lica. Preporučuje se da vozač ili odgovorno lice ima potvrdu o plaćenoj dnevnoj naknadi dostupnu na emailu ili u PDF-u.',
                        ) }}
                    </p>
                </div>
            </div>
        </div>
    </div>
</x-app-layout>
