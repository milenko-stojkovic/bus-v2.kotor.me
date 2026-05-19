<x-guest-layout>
<div class="space-y-6">
    <div class="space-y-1">
        <h1 class="text-lg font-semibold">Fake QA (banka + fiskal)</h1>
        <p class="text-sm text-gray-600">Jedan submit. Ako banka nije success, fiskal se ignoriše. Važi kada su <code class="bg-red-50 px-1">BANK_DRIVER=fake</code> i <code class="bg-red-50 px-1">FISCALIZATION_DRIVER=fake</code>.</p>
    </div>
    <p class="text-xs text-red-700">Ne poziva se pravi bank callback — test endpoint.</p>

    <form id="fake-qa-form" method="POST" action="{{ route('payment.fake-bank.complete', [], false) }}" class="space-y-6">
        @csrf
        <input type="hidden" name="merchant_transaction_id" value="{{ $merchant_transaction_id }}">

        <fieldset class="space-y-2 rounded-md border border-red-100 p-4">
            <legend class="text-sm font-semibold text-gray-900 px-1">A — Fake Bankart</legend>
            @php
                $bankScenarios = [
                    ['id' => 'success', 'label' => 'Success'],
                    ['id' => 'cancel', 'label' => 'Cancel (user_cancelled)'],
                    ['id' => 'expired', 'label' => 'Expired'],
                    ['id' => 'declined', 'label' => 'Declined'],
                    ['id' => 'insufficient_funds', 'label' => 'Insufficient funds'],
                    ['id' => '3ds_failed', 'label' => '3DS failed'],
                    ['id' => 'system_error', 'label' => 'System error'],
                ];
            @endphp
            @foreach ($bankScenarios as $s)
                <label class="flex items-center gap-2 text-sm cursor-pointer">
                    <input type="radio" name="bank_scenario" value="{{ $s['id'] }}" class="rounded-full border-red-200 text-red-600 focus:ring-red-500 js-bank-scenario"
                        {{ $loop->first ? 'checked' : '' }}>
                    <span>{{ $s['label'] }}</span>
                </label>
            @endforeach
        </fieldset>

        <fieldset id="fiscal-fieldset" class="space-y-2 rounded-md border border-red-100 p-4">
            <legend class="text-sm font-semibold text-gray-900 px-1">B — Fake fiskalizacija</legend>
            <p id="fiscal-hint" class="text-xs text-gray-500 hidden">Dostupno samo kada je banka Success.</p>
            @php
                $fiscalScenarios = [
                    ['id' => 'success', 'label' => 'Success'],
                    ['id' => 'deposit_missing', 'label' => 'Deposit missing (58)'],
                    ['id' => 'already_fiscalized', 'label' => 'Already fiscalized (78)'],
                    ['id' => 'validation_error', 'label' => 'Validation error (11)'],
                    ['id' => 'provider_down', 'label' => 'Provider down (500)'],
                    ['id' => 'tax_server_error', 'label' => 'Tax server error'],
                    ['id' => 'temporary_service_down', 'label' => 'Temporary service down (999)'],
                    ['id' => 'timeout', 'label' => 'Timeout'],
                    ['id' => 'malformed_response', 'label' => 'Malformed response'],
                ];
            @endphp
            @foreach ($fiscalScenarios as $fs)
                <label class="flex items-center gap-2 text-sm cursor-pointer">
                    <input type="radio" name="fiscal_scenario" value="{{ $fs['id'] }}" class="rounded-full border-red-200 text-red-600 focus:ring-red-500 js-fiscal-scenario"
                        {{ $fs['id'] === 'success' ? 'checked' : '' }}>
                    <span>{{ $fs['label'] }}</span>
                </label>
            @endforeach
        </fieldset>

        <button type="submit" class="inline-flex items-center justify-center px-4 py-2 bg-white border border-red-200 rounded-md font-semibold text-xs text-gray-900 uppercase tracking-widest shadow-sm hover:bg-red-50 focus:outline-none focus:ring-2 focus:ring-red-500 focus:ring-offset-2">
            Potvrdi (banka + fiskal)
        </button>
    </form>

    <p class="text-xs text-gray-500">Nakon submita: redirect na <a href="{{ route('payment.return', ['merchant_transaction_id' => $merchant_transaction_id]) }}" class="underline">/payment/return</a></p>
</div>

<script>
(function () {
    var form = document.getElementById('fake-qa-form');
    if (!form) return;
    var fiscalInputs = form.querySelectorAll('.js-fiscal-scenario');
    var fiscalFs = document.getElementById('fiscal-fieldset');
    var fiscalHint = document.getElementById('fiscal-hint');

    function selectedBank() {
        var r = form.querySelector('input[name="bank_scenario"]:checked');
        return r ? r.value : '';
    }

    function syncFiscalEnabled() {
        var ok = selectedBank() === 'success';
        fiscalInputs.forEach(function (el) {
            el.disabled = !ok;
        });
        if (fiscalFs) {
            fiscalFs.classList.toggle('opacity-40', !ok);
            fiscalFs.classList.toggle('pointer-events-none', !ok);
        }
        if (fiscalHint) {
            fiscalHint.classList.toggle('hidden', ok);
        }
    }

    form.querySelectorAll('.js-bank-scenario').forEach(function (el) {
        el.addEventListener('change', syncFiscalEnabled);
    });

    form.addEventListener('submit', function (e) {
        if (selectedBank() === 'success') {
            var picked = form.querySelector('input[name="fiscal_scenario"]:checked');
            if (!picked || picked.disabled) {
                e.preventDefault();
                alert('Izaberite fiskal scenario.');
            }
        }
    });

    syncFiscalEnabled();
})();
</script>
</x-guest-layout>
