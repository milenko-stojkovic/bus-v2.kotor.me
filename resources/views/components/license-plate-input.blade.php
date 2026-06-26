@props(['disabled' => false])

<input
    @disabled($disabled)
    {{ $attributes->merge([
        'type' => 'text',
        'autocapitalize' => 'characters',
        'autocomplete' => 'off',
        'spellcheck' => 'false',
        'inputmode' => 'latin',
        'pattern' => '[A-Z0-9]+',
        'class' => 'uppercase border-red-200 focus:border-red-500 focus:ring-red-500 rounded-md shadow-sm',
        'oninput' => "this.value=this.value.toUpperCase().replace(/[^A-Z0-9]+/g,'')",
    ]) }}
>
