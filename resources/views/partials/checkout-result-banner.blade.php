@php
    $b = session('checkout_banner');
@endphp
@if (is_array($b) && isset($b['level'], $b['title_key'], $b['message_key']))
    @php
        $group = $b['group'] ?? \App\Support\CheckoutResultFlash::GROUP;
        $level = $b['level'];
        $box = match ($level) {
            'success' => 'bg-red-50 text-red-900 border border-red-100',
            'info' => 'bg-red-50 text-red-900 border border-red-100',
            default => 'bg-red-50 text-red-800 border border-red-100',
        };
        $title = \App\Support\UiText::t($group, $b['title_key']);
        $message = \App\Support\UiText::t($group, $b['message_key']);
    @endphp
    <div class="rounded-md p-4 text-sm {{ $box }} space-y-1">
        <p class="font-medium">{{ $title }}</p>
        <p class="mt-1 whitespace-pre-line">{{ $message }}</p>
    </div>
@endif
