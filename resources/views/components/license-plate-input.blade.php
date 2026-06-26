@props(['disabled' => false])

<x-text-input
    @disabled($disabled)
    {{ $attributes->merge([
        'type' => 'text',
        'autocapitalize' => 'characters',
        'autocomplete' => 'off',
        'spellcheck' => 'false',
        'inputmode' => 'latin',
        'pattern' => '[A-Z0-9]+',
        'class' => 'uppercase',
        'oninput' => "this.value=this.value.toUpperCase().replace(/[^A-Z0-9]+/g,'')",
    ]) }}
/>
