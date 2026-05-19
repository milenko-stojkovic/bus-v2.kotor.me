<x-guest-layout>
    <div id="successScreen" class="rounded-lg border border-red-100 p-6 text-center space-y-3 cursor-pointer">
        <h1 class="text-lg font-semibold">{{ \App\Support\UiText::t('free_request', 'success_title', 'Zahtjev poslat') }}</h1>
        <p class="text-sm text-gray-700">{{ $message }}</p>
        <p class="text-xs text-gray-500">{{ \App\Support\UiText::t('free_request', 'success_click_anywhere', 'Kliknite bilo gdje da se vratite na početnu stranicu.') }}</p>
    </div>

    <script>
        (function () {
            const el = document.getElementById('successScreen');
            if (!el) return;
            el.addEventListener('click', function () {
                window.location.href = "{{ route('landing', [], false) }}";
            });
        })();
    </script>
</x-guest-layout>

