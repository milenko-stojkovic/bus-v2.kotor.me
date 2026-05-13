<?php

namespace App\Providers;

use App\Contracts\CallbackSignatureValidator;
use App\Contracts\PaymentService;
use App\Contracts\PaymentStatusInquiryService;
use App\Events\PaymentFailed;
use App\Listeners\LogPaymentFailed;
use App\Listeners\NotifyUserPaymentFailed;
use App\Services\Limo\LimoOcrRunner;
use App\Services\Limo\TesseractOcrRunner;
use App\Services\Payment\FakeCallbackSignatureValidator;
use App\Services\Payment\FakePaymentProvider;
use App\Services\Payment\FakePaymentStatusInquiryService;
use App\Services\Payment\RealCallbackSignatureValidator;
use App\Services\Payment\RealPaymentProvider;
use App\Services\Payment\RealPaymentStatusInquiryService;
use Illuminate\Auth\Middleware\RedirectIfAuthenticated;
use Illuminate\Queue\Events\WorkerStarting;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        $this->app->bind(PaymentService::class, function () {
            $bankDriver = config('services.bank.driver') ?? config('payment.provider', 'fake');

            return match ($bankDriver) {
                'bankart' => $this->app->make(RealPaymentProvider::class),
                default => $this->app->make(FakePaymentProvider::class),
            };
        });

        $this->app->bind(CallbackSignatureValidator::class, function () {
            $bankDriver = config('services.bank.driver') ?? config('payment.provider', 'fake');

            return match ($bankDriver) {
                'bankart' => $this->app->make(RealCallbackSignatureValidator::class),
                default => $this->app->make(FakeCallbackSignatureValidator::class),
            };
        });

        $this->app->bind(PaymentStatusInquiryService::class, function () {
            $bankDriver = config('services.bank.driver') ?? config('payment.provider', 'fake');

            return match ($bankDriver) {
                'bankart' => $this->app->make(RealPaymentStatusInquiryService::class),
                default => $this->app->make(FakePaymentStatusInquiryService::class),
            };
        });

        // Limo OCR runner (advisory suggestion only; may be disabled/unavailable).
        $this->app->bind(LimoOcrRunner::class, fn () => $this->app->make(TesseractOcrRunner::class));
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        RedirectIfAuthenticated::redirectUsing(function (\Illuminate\Http\Request $request): string {
            if ($request->routeIs('panel_admin.login')) {
                return Auth::guard('panel_admin')->check()
                    ? route('panel_admin.dashboard', absolute: false)
                    : route('login', absolute: false);
            }
            if ($request->routeIs('control.login')) {
                return Auth::guard('control')->check()
                    ? route('control.dashboard', absolute: false)
                    : route('login', absolute: false);
            }

            return route('dashboard', absolute: false);
        });

        Event::listen(PaymentFailed::class, LogPaymentFailed::class);
        Event::listen(PaymentFailed::class, NotifyUserPaymentFailed::class);

        Event::listen(WorkerStarting::class, function (): void {
            if (! app()->environment('production')) {
                return;
            }
            static $logged = false;
            if ($logged) {
                return;
            }
            $logged = true;
            Log::channel('payments')->info('queue_worker_booted', [
                'pid' => getmypid(),
            ]);
        });

        if ($this->app->environment('production')) {
            if (filter_var(config('payment.fake_e2e_sync'), FILTER_VALIDATE_BOOLEAN)) {
                Cache::remember('bootstrap_warn_fake_e2e_sync_production', 86_400, function (): bool {
                    Log::channel('payments')->warning('FAKE_PAYMENT_E2E_SYNC is enabled in production', [
                        'hint' => 'Set FAKE_PAYMENT_E2E_SYNC=false in production.',
                    ]);

                    return true;
                });
            }

            $bank = (string) config('services.bank.driver', 'fake');
            $fiscal = (string) config('services.fiscalization.driver', 'fake');
            if ($bank === 'fake' || $fiscal === 'fake') {
                Cache::remember('bootstrap_warn_fake_payment_drivers', 43_200, function () use ($bank, $fiscal): bool {
                    Log::channel('payments')->warning('production_fake_driver_active', [
                        'bank_driver' => $bank,
                        'fiscalization_driver' => $fiscal,
                        'hint' => 'Production should use BANK_DRIVER=bankart and FISCALIZATION_DRIVER=real.',
                    ]);

                    return true;
                });
            }
        }
    }
}
