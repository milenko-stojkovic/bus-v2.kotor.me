@props([
    'white' => false,
])

@php
    $src = $white ? asset('images/buslogowhite.svg') : asset('images/buslogofull.svg');
@endphp
<img
    src="{{ $src }}"
    alt="{{ config('app.name', 'Laravel') }}"
    loading="lazy"
    decoding="async"
    {{ $attributes }}
/>
