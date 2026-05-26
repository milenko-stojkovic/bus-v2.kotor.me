@props([
    'id',
    'name',
    'label' => null,
    'value' => '',
    'min' => null,
    'max' => null,
    'required' => false,
    'form' => null,
])

@php
    $displayValue = '';
    if (is_string($value) && $value !== '') {
        try {
            $displayValue = \Illuminate\Support\Carbon::parse($value)->format('d/m/Y');
        } catch (\Throwable) {
            $displayValue = '';
        }
    }
    $inputClass = 'mt-1 block w-full rounded-md border-red-200 shadow-sm focus:border-red-500 focus:ring-red-500 sm:text-sm';
@endphp

<div data-iso-date-input {{ $attributes->only('class')->merge(['class' => '']) }}>
    @if ($label)
        <x-input-label :for="$id.'_display'" :value="$label" />
    @endif
    <input
        type="text"
        id="{{ $id }}_display"
        data-iso-date-display
        value="{{ $displayValue }}"
        placeholder="dd/mm/yyyy"
        inputmode="numeric"
        autocomplete="off"
        @if ($required) required @endif
        class="{{ $inputClass }}"
    />
    <input
        type="hidden"
        id="{{ $id }}"
        name="{{ $name }}"
        value="{{ $value }}"
        data-iso-date-hidden
        @if ($min) data-min="{{ $min }}" @endif
        @if ($max) data-max="{{ $max }}" @endif
        @if ($form) form="{{ $form }}" @endif
    />
</div>
