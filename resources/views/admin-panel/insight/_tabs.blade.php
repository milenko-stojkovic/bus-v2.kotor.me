@php
    $active = $insightTab ?? 'reservations';
@endphp
<nav class="flex flex-wrap gap-2 text-sm border-b border-red-100 pb-3">
    <a href="{{ route('panel_admin.insight', [], false) }}"
       class="px-3 py-1.5 rounded-md font-medium {{ $active === 'reservations' ? 'bg-red-100 text-red-900' : 'text-gray-600 hover:bg-red-50 hover:text-gray-900' }}">
        Plaćanje rezervacije
    </a>
    @if (config('features.advance_payments'))
        <a href="{{ route('panel_admin.insight.advance', [], false) }}"
           class="px-3 py-1.5 rounded-md font-medium {{ $active === 'advance' ? 'bg-red-100 text-red-900' : 'text-gray-600 hover:bg-red-50 hover:text-gray-900' }}">
            Avansna uplata
        </a>
    @endif
</nav>
