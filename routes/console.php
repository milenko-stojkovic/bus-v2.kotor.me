<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use App\Models\AgencyAdvanceTransaction;
use App\Models\User;
use App\Services\AgencyAdvance\AgencyAdvanceYearlyStatementService;
use Carbon\Carbon;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

Artisan::command('advance:send-yearly-statements', function (AgencyAdvanceYearlyStatementService $svc): int {
    if (! config('features.advance_payments')) {
        Log::channel('payments')->info('advance_yearly_statement_skipped', [
            'year' => (int) Carbon::now()->subYear()->format('Y'),
            'reason' => 'feature_flag_off',
        ]);

        $this->info('advance:send-yearly-statements skipped: feature flag off');

        return 0;
    }

    $year = (int) Carbon::now()->subYear()->format('Y');
    $start = Carbon::create($year, 1, 1, 0, 0, 0)->startOfDay();
    $end = Carbon::create($year, 12, 31, 23, 59, 59)->endOfDay();

    $agencyIds = AgencyAdvanceTransaction::query()
        ->where('created_at', '>=', $start->toDateTimeString())
        ->where('created_at', '<=', $end->toDateTimeString())
        ->select('agency_user_id')
        ->distinct()
        ->pluck('agency_user_id')
        ->map(fn ($v) => (int) $v)
        ->values();

    $sent = 0;
    $skipped = 0;
    $failed = 0;

    foreach ($agencyIds as $agencyUserId) {
        /** @var User|null $agency */
        $agency = User::query()->whereKey($agencyUserId)->first();
        if (! $agency) {
            continue;
        }

        $res = $svc->sendForAgencyYear($agency, $year);
        if ($res === 'sent') {
            $sent++;
        } elseif ($res === 'skipped') {
            $skipped++;
        } else {
            $failed++;
        }
    }

    Log::channel('payments')->info('advance_yearly_statements_command_done', [
        'year' => $year,
        'sent' => $sent,
        'skipped' => $skipped,
        'failed' => $failed,
        'agencies_total' => $agencyIds->count(),
    ]);

    $this->info("Year {$year}: sent={$sent}, skipped={$skipped}, failed={$failed}, agencies={$agencyIds->count()}");

    return 0;
})->purpose('Send yearly advance statements (previous year) to agencies with ledger activity');

Schedule::command('advance:send-yearly-statements')
    ->yearlyOn(1, 1, '10:00');

// SAFE local scheduled jobs (no real bank/fiscal calls)
Schedule::command('reservations:expire-pending')->everyFiveMinutes();
Schedule::command('prices:apply-due')
    ->everyMinute()
    ->timezone('Europe/Podgorica')
    ->withoutOverlapping();
Schedule::command('parking:sync-days')->dailyAt('00:05');
Schedule::command('limo:cleanup-temporary-data')
    ->dailyAt('00:10')
    ->timezone('Europe/Podgorica');
Schedule::command('files:cleanup-preview-cache')
    ->dailyAt('00:15')
    ->timezone('Europe/Podgorica');
Schedule::command('files:archive-private --source=all --limit=50 --require-mega-health')
    ->everySixHours()
    ->timezone('Europe/Podgorica')
    ->withoutOverlapping(360);
Schedule::command('temp-data:cleanup')->daily();

// Scheduled admin PDF report emails (SAFE: reads data + sends email)
Schedule::command('reports:send-scheduled daily')
    ->dailyAt('07:00')
    ->timezone('Europe/Podgorica');
Schedule::command('alerts:system-health')
    ->dailyAt('07:30')
    ->timezone('Europe/Podgorica');
Schedule::command('reports:send-scheduled monthly')
    ->monthlyOn(1, '07:05')
    ->timezone('Europe/Podgorica');
Schedule::command('reports:send-scheduled yearly')
    ->yearlyOn(1, 1, '07:10')
    ->timezone('Europe/Podgorica');
