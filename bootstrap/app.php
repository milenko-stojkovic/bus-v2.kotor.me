<?php

use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->web(append: [
            \App\Http\Middleware\SetLocale::class,
        ]);
        $middleware->redirectGuestsTo(function (\Illuminate\Http\Request $request): string {
            if ($request->is('admin') || $request->is('admin/*')) {
                return route('panel_admin.login', absolute: false);
            }
            if ($request->is('control') || $request->is('control/*')) {
                return route('control.login', absolute: false);
            }
            if ($request->is('limo') || $request->is('limo/*')) {
                return route('panel_admin.login', absolute: false);
            }

            return route('login', absolute: false);
        });
        $middleware->alias([
            'admin' => \App\Http\Middleware\AdminMiddleware::class,
            'admin.panel' => \App\Http\Middleware\EnsureAdminPanelAccess::class,
            'limo.access' => \App\Http\Middleware\EnsureLimoAccess::class,
            'advance.feature' => \App\Http\Middleware\EnsureAdvancePaymentsEnabled::class,
            'limo.feature' => \App\Http\Middleware\EnsureLimoServiceEnabled::class,
            'limo.qr_workflow' => \App\Http\Middleware\EnsureLimoQrWorkflowEnabled::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        //
    })
    ->withSchedule(function (Schedule $schedule): void {
        // LOCAL SAFE schedule lives in routes/console.php.
        // Production-only / unsafe jobs (real bank/fiscal calls) are intentionally not scheduled in local/dev.
        if (app()->environment('production')) {
            // Prioritet: fiskalizacija → parking → email
            $schedule->command('reservations:process-pending')->everyFiveMinutes();
            $schedule->command('payment:check-pending-inquiry')->everyMinute();
            $schedule->command('post-fiscalization:retry')->everyTenMinutes();
        }
    })
    ->create();
