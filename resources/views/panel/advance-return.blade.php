<x-app-layout>
    <x-slot name="header">
        <h2 class="font-semibold text-xl text-gray-800 leading-tight">{{ \App\Support\UiText::t('panel', 'nav_advance', 'Avans') }}</h2>
    </x-slot>

    <div class="py-6">
        <div class="max-w-3xl mx-auto sm:px-6 lg:px-8">
            <div class="bg-white shadow-sm sm:rounded-lg p-6 space-y-4">
                <p class="text-red-900 font-medium">{{ \App\Support\UiText::t('panel', 'advance_topup_pending_title', 'Advance top-up is being processed') }}</p>
                <p class="text-sm text-gray-600 whitespace-pre-line">{{ \App\Support\UiText::t('panel', 'advance_topup_pending', 'Advance top-up is being processed. If needed, refresh the page in a few moments.') }}</p>
                <div class="flex flex-wrap gap-2">
                    <a href="{{ request()->fullUrl() }}" class="inline-flex items-center px-4 py-2 bg-red-700 border border-transparent rounded-md font-semibold text-xs text-white uppercase tracking-widest hover:bg-red-800 focus:bg-red-800 focus:outline-none focus:ring-2 focus:ring-red-600 focus:ring-offset-2 transition">
                        {{ \App\Support\UiText::t('checkout_result', 'refresh_page', 'Refresh') }}
                    </a>
                    <a href="{{ route('panel.advance.index', [], false) }}" class="inline-flex items-center px-4 py-2 border border-red-200 rounded-md font-semibold text-xs text-gray-700 uppercase tracking-widest hover:bg-red-50 focus:outline-none transition">
                        {{ \App\Support\UiText::t('panel', 'advance_topup_back', 'Back to advance') }}
                    </a>
                </div>
                <script>
                    (function() {
                        var txId = @json($merchantTransactionId);
                        var interval = setInterval(function() {
                            fetch('{{ route('panel.advance.result', [], false) }}?merchant_transaction_id=' + encodeURIComponent(txId), {
                                headers: { 'Accept': 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
                                credentials: 'same-origin'
                            }).then(function(r) { return r.json(); }).then(function(data) {
                                if (data.status === 'success' || data.status === 'failed') {
                                    clearInterval(interval);
                                    window.location.reload();
                                }
                            }).catch(function() {});
                        }, 3000);
                        setTimeout(function() { clearInterval(interval); }, 300000);
                    })();
                </script>
            </div>
        </div>
    </div>
</x-app-layout>
