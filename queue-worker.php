<?php

/**
 * Plesk scheduled task entrypoint: Run a PHP script, cron * * * * *
 * Script path (relative to domain home): bus-v2.kotor.me/queue-worker.php
 *
 * Processes Laravel queue jobs (payment callbacks, fiscal, emails).
 * Runs up to ~55s per cron tick, then exits when the queue is empty.
 * Long jobs (e.g. fiscal, up to 120s) may finish across consecutive runs.
 */

use Symfony\Component\Console\Input\ArgvInput;

define('LARAVEL_START', microtime(true));

require __DIR__.'/vendor/autoload.php';

/** @var \Illuminate\Foundation\Application $app */
$app = require_once __DIR__.'/bootstrap/app.php';

$status = $app->handleCommand(new ArgvInput([
    'artisan',
    'queue:work',
    '--stop-when-empty',
    '--max-time=55',
    '--sleep=1',
    '--tries=3',
    '--timeout=130',
    '--memory=512',
]));

exit($status);
