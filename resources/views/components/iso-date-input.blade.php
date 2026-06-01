@props([
    'id',
    'name',
    'label' => null,
    'value' => '',
    'min' => null,
    'max' => null,
    'required' => false,
    'form' => null,
    'calendarAriaLabel' => null,
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
    $pickerValue = is_string($value) && $value !== '' ? $value : '';
    $inputClass = 'block w-full min-w-0 flex-1 rounded-md border-red-200 shadow-sm focus:border-red-500 focus:ring-red-500 sm:text-sm';
    $calendarLabel = $calendarAriaLabel ?? (app()->getLocale() === 'en' ? 'Open calendar' : 'Otvori kalendar');
@endphp

<div data-iso-date-input {{ $attributes->only('class')->merge(['class' => '']) }}>
    @if ($label)
        <x-input-label :for="$id.'_display'" :value="$label" />
    @endif
    <div class="mt-1 flex items-stretch gap-1" data-iso-date-control>
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
        <button
            type="button"
            data-iso-date-calendar-btn
            class="inline-flex shrink-0 items-center justify-center rounded-md border border-red-200 bg-white px-2.5 text-gray-600 shadow-sm hover:bg-red-50 hover:text-red-800 focus:border-red-500 focus:outline-none focus:ring-2 focus:ring-red-500 focus:ring-offset-1"
            aria-label="{{ $calendarLabel }}"
            title="{{ $calendarLabel }}"
        >
            <svg class="h-5 w-5" viewBox="0 0 20 20" fill="currentColor" aria-hidden="true">
                <path fill-rule="evenodd" d="M5.75 2a.75.75 0 0 1 .75.75V4h7V2.75a.75.75 0 0 1 1.5 0V4h.25A2.75 2.75 0 0 1 18 6.75v8.5A2.75 2.75 0 0 1 15.25 18H4.75A2.75 2.75 0 0 1 2 15.25v-8.5A2.75 2.75 0 0 1 4.75 4H5V2.75A.75.75 0 0 1 5.75 2Zm-1 5.5c0-.69.56-1.25 1.25-1.25h8.5c.69 0 1.25.56 1.25 1.25v6.5c0 .69-.56 1.25-1.25 1.25h-8.5c-.69 0-1.25-.56-1.25-1.25v-6.5Z" clip-rule="evenodd" />
            </svg>
        </button>
        <input
            type="date"
            id="{{ $id }}_picker"
            data-iso-date-picker
            value="{{ $pickerValue }}"
            tabindex="-1"
            aria-hidden="true"
            class="sr-only"
            @if ($min) min="{{ $min }}" @endif
            @if ($max) max="{{ $max }}" @endif
        />
    </div>
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
