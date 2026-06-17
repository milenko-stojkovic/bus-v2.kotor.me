<?php

/**
 * Plesk scheduled task entrypoint: Run a PHP script, cron * * * * *
 * Script path (relative to domain home): bus-v2.kotor.me/queue-worker.php
 *
 * Processes Laravel queue jobs (payment callbacks, fiscal, emails).
 *
 * Intentionally stays alive for up to --max-time=55 seconds (no --stop-when-empty),
 * polling every --sleep=1 second, so jobs that arrive shortly after cron starts are
 * picked up within ~1s instead of waiting up to 60s for the next cron tick.
 *
 * A cache (or file) lock prevents overlapping workers when cron fires every minute.
 * Lock TTL (70s) is slightly longer than max-time so the next tick exits quietly if
 * the previous worker is still finishing.
 *
 * Preferred production setup: supervisor/systemd `queue:work`. This script is the
 * Plesk fallback when Laravel Toolkit Queue cannot be enabled.
 */

use Illuminate\Contracts\Cache\Lock;
use Illuminate\Support\Facades\Cache;
use Symfony\Component\Console\Input\ArgvInput;

define('LARAVEL_START', microtime(true));

const PLESK_QUEUE_WORKER_LOCK = 'plesk_queue_worker_bus_v2';
const PLESK_QUEUE_WORKER_LOCK_SECONDS = 70;
const PLESK_QUEUE_WORKER_LOCK_FILE = __DIR__.'/storage/framework/queue-worker.lock';

require __DIR__.'/vendor/autoload.php';

/** @var \Illuminate\Foundation\Application $app */
$app = require_once __DIR__.'/bootstrap/app.php';

$app->make(\Illuminate\Contracts\Console\Kernel::class)->bootstrap();

$releaseLock = acquirePleskQueueWorkerLock();

if ($releaseLock === null) {
    // Another worker from the previous cron minute is still running.
    exit(0);
}

$status = 0;

try {
    $status = $app->handleCommand(new ArgvInput([
        'artisan',
        'queue:work',
        '--max-time=55',
        '--sleep=1',
        '--tries=3',
        '--timeout=130',
        '--memory=512',
    ]));
} finally {
    $releaseLock();
}

exit($status);

/**
 * @return null|callable Release lock (call in finally).
 */
function acquirePleskQueueWorkerLock(): ?callable
{
    $cacheLock = tryAcquireCacheLock();

    if ($cacheLock instanceof Lock) {
        return static function () use ($cacheLock): void {
            $cacheLock->release();
        };
    }

    if ($cacheLock === true) {
        // Cache lock held by another worker.
        return null;
    }

    // Cache lock unavailable (exception) — fall back to exclusive flock.
    return tryAcquireFileLock();
}

/**
 * @return Lock|true|null Lock on success, true if held by another worker, null if cache unusable.
 */
function tryAcquireCacheLock(): Lock|true|null
{
    try {
        $lock = Cache::lock(PLESK_QUEUE_WORKER_LOCK, PLESK_QUEUE_WORKER_LOCK_SECONDS);

        if ($lock->get()) {
            return $lock;
        }

        return true;
    } catch (\Throwable) {
        return null;
    }
}

/**
 * @return null|callable
 */
function tryAcquireFileLock(): ?callable
{
    $directory = dirname(PLESK_QUEUE_WORKER_LOCK_FILE);

    if (! is_dir($directory) && ! mkdir($directory, 0755, true) && ! is_dir($directory)) {
        return null;
    }

    $handle = fopen(PLESK_QUEUE_WORKER_LOCK_FILE, 'c+');

    if ($handle === false) {
        return null;
    }

    if (! flock($handle, LOCK_EX | LOCK_NB)) {
        fclose($handle);

        return null;
    }

    return static function () use ($handle): void {
        flock($handle, LOCK_UN);
        fclose($handle);
    };
}
