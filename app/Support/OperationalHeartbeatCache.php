<?php

namespace App\Support;

/**
 * Cache keys for operational heartbeats (future read-only “Sistem status” dashboard).
 * Values use default Laravel cache store; TTL long enough for daily/six-hour schedules.
 */
final class OperationalHeartbeatCache
{
    /** 30 days — must survive gaps between scheduled runs. */
    public const TTL_SECONDS = 60 * 60 * 24 * 30;

    public const SYSTEM_HEALTH_LAST_RUN_AT = 'system_health:last_run_at';

    public const SYSTEM_HEALTH_LAST_OK_AT = 'system_health:last_ok_at';

    public const MEGA_LAST_DIAGNOSE_AT = 'mega:last_diagnose_at';

    public const MEGA_LAST_DIAGNOSE_OK = 'mega:last_diagnose_ok';

    public const MEGA_LAST_DIAGNOSE_ERROR = 'mega:last_diagnose_error';

    public const ARCHIVE_PRIVATE_LAST_RUN_AT = 'archive_private:last_run_at';

    public const ARCHIVE_PRIVATE_LAST_OK_AT = 'archive_private:last_ok_at';

    public const ARCHIVE_PRIVATE_LAST_SUMMARY = 'archive_private:last_summary';

    public const SCHEDULER_LAST_RUN_AT = 'watchdog:scheduler:last_run_at';

    public const SCHEDULER_LAST_OK_AT = 'watchdog:scheduler:last_ok_at';

    public const SCHEDULER_LAST_ERROR = 'watchdog:scheduler:last_error';

    public const QUEUE_WORKER_LAST_RUN_AT = 'watchdog:queue_worker:last_run_at';

    public const QUEUE_WORKER_LAST_OK_AT = 'watchdog:queue_worker:last_ok_at';

    public const QUEUE_WORKER_LAST_ERROR = 'watchdog:queue_worker:last_error';

    public static function ttl(): \DateTimeInterface
    {
        return now()->addSeconds(self::TTL_SECONDS);
    }
}
