<?php

namespace App\Http\Controllers\Panel;

use App\Http\Controllers\Controller;
use App\Http\Requests\Panel\PanelAdvanceTopupRequest;
use App\Models\AgencyAdvanceTopup;
use App\Models\AgencyAdvanceTransaction;
use App\Services\AgencyAdvance\AgencyAdvanceService;
use App\Services\AgencyAdvance\AdvanceTopupProcessor;
use App\Services\AgencyAdvance\RealAdvanceTopupPaymentProvider;
use App\Services\Payment\BankartBillingCountryAlertService;
use App\Support\BankartBillingCountry;
use App\Support\UiText;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Support\Facades\Log;
use Illuminate\View\View;

final class AdvanceController extends Controller
{
    public function index(AgencyAdvanceService $advance): View
    {
        if (! (bool) config('features.advance_payments')) {
            abort(404);
        }

        $userId = (int) auth()->id();

        $balance = $advance->balance($userId);

        $ledger = AgencyAdvanceTransaction::query()
            ->where('agency_user_id', $userId)
            ->orderByDesc('created_at')
            ->limit(50)
            ->get();

        return view('panel.advance', [
            'balance' => $balance,
            'ledger' => $ledger,
        ]);
    }

    public function storeTopup(PanelAdvanceTopupRequest $request): RedirectResponse
    {
        if (! (bool) config('features.advance_payments')) {
            abort(404);
        }

        $userId = (int) $request->user()->id;
        $locale = (string) ($request->user()->lang ?? app()->getLocale());
        $amount = (string) $request->validated('amount');

        $bankDriver = (string) config('services.bank.driver', 'fake');
        $country = (string) ($request->user()->country ?? '');
        if ($bankDriver !== 'fake' && ! BankartBillingCountry::isSelectablePaymentCountry($country)) {
            app(BankartBillingCountryAlertService::class)->notifyAndLog(
                $country,
                BankartBillingCountry::normalize($country),
                $userId,
                (string) ($request->user()->email ?? ''),
                null,
                null,
                null,
                null,
                'advance_topup',
            );

            return redirect()
                ->to(route('panel.advance.index', [], false))
                ->with('error', app(BankartBillingCountryAlertService::class)->userMessage($locale));
        }

        /** @var AgencyAdvanceTopup $topup */
        $topup = AgencyAdvanceTopup::query()->create([
            'agency_user_id' => $userId,
            'merchant_transaction_id' => (string) Str::uuid(),
            'amount' => $amount,
            'status' => AgencyAdvanceTopup::STATUS_PENDING,
            'bank_payload' => null,
            'paid_at' => null,
            'failed_at' => null,
        ]);

        Log::channel('payments')->info('advance_topup_started', [
            'agency_user_id' => $userId,
            'merchant_transaction_id' => $topup->merchant_transaction_id,
            'amount' => $amount,
            'topup_id' => $topup->id,
        ]);

        if ($bankDriver === 'fake') {
            app(AdvanceTopupProcessor::class)->markPaid($topup->merchant_transaction_id, ['driver' => 'fake']);

            return redirect()
                ->to(route('panel.advance.index', [], false))
                ->with('status', UiText::t('panel', 'advance_topup_success', 'Advance top-up was successfully recorded.', $locale));
        }

        $provider = app(RealAdvanceTopupPaymentProvider::class);
        $successUrl = route('panel.advance.return', ['merchant_transaction_id' => $topup->merchant_transaction_id, 'bank_result' => 'success'], false);
        $errorUrl = route('panel.advance.return', ['merchant_transaction_id' => $topup->merchant_transaction_id, 'bank_result' => 'error'], false);
        $cancelUrl = route('panel.advance.return', ['merchant_transaction_id' => $topup->merchant_transaction_id, 'bank_result' => 'cancel'], false);

        $session = $provider->createSession(
            $topup->merchant_transaction_id,
            (string) $topup->amount,
            $successUrl,
            $errorUrl,
            $cancelUrl,
            (string) ($request->user()->email ?? ''),
            (string) ($request->user()->country ?? ''),
        );

        if ($session->success && is_string($session->paymentUrl) && $session->paymentUrl !== '') {
            return redirect()->to($session->paymentUrl);
        }

        // createSession failed → mark failed; no ledger.
        $topup->status = AgencyAdvanceTopup::STATUS_FAILED;
        $topup->failed_at = now();
        $topup->save();

        Log::channel('payments')->warning('advance_topup_create_session_failed', [
            'agency_user_id' => $userId,
            'merchant_transaction_id' => $topup->merchant_transaction_id,
            'amount' => (string) $topup->amount,
            'topup_id' => $topup->id,
            'reason' => $session->failureReason ?? 'unavailable',
        ]);

        $errorMessage = $session->failureReason === 'invalid_billing_country'
            ? app(BankartBillingCountryAlertService::class)->userMessage($locale)
            : UiText::t('panel', 'advance_topup_start_failed', 'Advance top-up could not be started.', $locale);

        return redirect()
            ->to(route('panel.advance.index', [], false))
            ->with('error', $errorMessage);
    }

    /**
     * Bank redirect return (success/error/cancel). Status is still read from DB.
     */
    public function paymentReturn(Request $request): RedirectResponse
    {
        if (! (bool) config('features.advance_payments')) {
            abort(404);
        }
        $locale = (string) ($request->user()?->lang ?? app()->getLocale());

        $txId = $request->query('merchant_transaction_id');
        $txId = is_string($txId) ? trim($txId) : '';
        if ($txId === '') {
            return redirect()->to(route('panel.advance.index', [], false));
        }

        $topup = AgencyAdvanceTopup::query()->where('merchant_transaction_id', $txId)->first();
        if (! $topup) {
            return redirect()->to(route('panel.advance.index', [], false));
        }

        if ($topup->status === AgencyAdvanceTopup::STATUS_PAID) {
            return redirect()->to(route('panel.advance.index', [], false))
                ->with('status', UiText::t('panel', 'advance_topup_success', 'Advance top-up was successfully recorded.', $locale));
        }

        if ($topup->status === AgencyAdvanceTopup::STATUS_FAILED) {
            return redirect()->to(route('panel.advance.index', [], false))
                ->with('error', UiText::t('panel', 'advance_topup_failed', 'Advance top-up was not completed successfully.', $locale));
        }

        // pending: callback may arrive slightly later
        return redirect()->to(route('panel.advance.index', [], false))
            ->with('message', UiText::t('panel', 'advance_topup_pending', 'Advance top-up is being processed. If needed, refresh the page in a few moments.', $locale));
    }
}

