@props(['disabled' => false])

<input @disabled($disabled) {{ $attributes->merge(['class' => 'border-red-200 focus:border-red-500 focus:ring-red-500 rounded-md shadow-sm']) }}>
