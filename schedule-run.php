<?php

/**
 * Plesk scheduled task entrypoint: Run a PHP script, cron * * * * *
 * Script path (relative to domain home): bus-v2.kotor.me/schedule-run.php
 */

use App\Services\Operational\BackgroundWatchdogService;
use Symfony\Component\Console\Input\ArgvInput;

define('LARAVEL_START', microtime(true));

require __DIR__.'/vendor/autoload.php';

/** @var \Illuminate\Foundation\Application $app */
$app = require_once __DIR__.'/bootstrap/app.php';

$app->make(\Illuminate\Contracts\Console\Kernel::class)->bootstrap();

/** @var BackgroundWatchdogService $watchdog */
$watchdog = $app->make(BackgroundWatchdogService::class);
$watchdog->recordSchedulerRunStarted();

$status = $app->handleCommand(new ArgvInput(['artisan', 'schedule:run']));

$watchdog->recordSchedulerRunFinished($status);
$watchdog->evaluateStaleHeartbeats();

exit($status);
