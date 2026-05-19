{{-- Očekuje: $merchantTransactionId, $backUrl --}}
<div class="space-y-4">
    <p class="text-red-900 font-medium">{{ \App\Support\UiText::t('checkout_result', 'payment_pending_title', 'Your payment is being processed.') }}</p>
    <p class="text-sm text-gray-600 whitespace-pre-line">{{ \App\Support\UiText::t('checkout_result', 'payment_pending_message', "If you already completed payment at the bank, wait a moment and refresh this page.\nStatus is always read from our server.") }}</p>
    <div class="flex flex-wrap gap-2">
        <a href="{{ request()->fullUrl() }}" class="inline-flex items-center px-4 py-2 bg-red-700 border border-transparent rounded-md font-semibold text-xs text-white uppercase tracking-widest hover:bg-red-800 focus:bg-red-800 focus:outline-none focus:ring-2 focus:ring-red-600 focus:ring-offset-2 transition">
            {{ \App\Support\UiText::t('checkout_result', 'refresh_page', 'Refresh') }}
        </a>
        <a href="{{ $backUrl }}" class="inline-flex items-center px-4 py-2 border border-red-200 rounded-md font-semibold text-xs text-gray-700 uppercase tracking-widest hover:bg-red-50 focus:outline-none transition">
            {{ \App\Support\UiText::t('checkout_result', 'back_to_booking', 'Back to booking') }}
        </a>
    </div>
    {{-- Polling: kada callback stigne, osveži da redirect na odredište sa flash porukom --}}
    <script>
        (function() {
            var txId = @json($merchantTransactionId);
            var interval = setInterval(function() {
                fetch('{{ route("payment.result") }}?merchant_transaction_id=' + encodeURIComponent(txId), {
                    headers: { 'Accept': 'application/json', 'X-Requested-With': 'XMLHttpRequest' }
                }).then(function(r) { return r.json(); }).then(function(data) {
                    if (data.status === 'success' || data.status === 'failed' || data.status === 'late_success') {
                        clearInterval(interval);
                        window.location.reload();
                    }
                }).catch(function() {});
            }, 3000);
            setTimeout(function() { clearInterval(interval); }, 300000);
        })();
    </script>
</div>
