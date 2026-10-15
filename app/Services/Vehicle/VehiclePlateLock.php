<?php

namespace App\Services\Vehicle;

use App\Services\Reservation\DuplicateReservationAttemptService;
use Closure;
use Illuminate\Contracts\Cache\Lock;
use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * Serializes category-dependent vehicle writes for one normalized plate.
 *
 * MySQL named locks are connection-scoped and are not released by COMMIT or
 * ROLLBACK, so release always runs in finally. The database transaction starts
 * only after every named lock is held. Nested acquisition is rejected.
 */
final class VehiclePlateLock
{
    private const TIMEOUT_SECONDS = 5;

    /** @var list<string> */
    private array $heldNames = [];

    /** @var array<string, Lock> */
    private array $cacheLocks = [];

    /**
     * @param  list<string>|string  $plates
     */
    public function run(array|string $plates, Closure $callback, ?int $timeoutSeconds = null): mixed
    {
        if ($this->heldNames !== []) {
            throw new \RuntimeException('Nested vehicle plate lock is not allowed.');
        }

        $timeout = $timeoutSeconds ?? self::TIMEOUT_SECONDS;
        $keys = $this->keys($plates);

        try {
            foreach ($keys as $key) {
                $this->acquire($this->lockName($key), $timeout);
            }

            return DB::transaction(static fn () => $callback());
        } finally {
            $this->releaseAll();
        }
    }

    /**
     * @param  list<string>|string  $plates
     * @return list<string>
     */
    private function keys(array|string $plates): array
    {
        $plates = is_array($plates) ? $plates : [$plates];
        $keys = [];
        foreach ($plates as $plate) {
            $key = DuplicateReservationAttemptService::normalizeLicensePlate((string) $plate);
            if ($key === '') {
                throw new \InvalidArgumentException('Vehicle plate lock requires a plate.');
            }
            $keys[$key] = $key;
        }

        $keys = array_values($keys);
        sort($keys);

        return $keys;
    }

    public static function lockName(string $normalizedPlate): string
    {
        if (strlen($normalizedPlate) > 50) {
            return 'vplate:'.substr(hash('sha256', $normalizedPlate), 0, 40);
        }

        return 'vplate:'.$normalizedPlate;
    }

    private function acquire(string $lockName, int $timeoutSeconds): void
    {
        if (in_array($lockName, $this->heldNames, true)) {
            throw new \RuntimeException('Nested vehicle plate lock is not allowed.');
        }

        $driver = DB::connection()->getDriverName();
        if ($driver === 'mysql' || $driver === 'mariadb') {
            $row = DB::selectOne('SELECT GET_LOCK(?, ?) AS acquired', [$lockName, $timeoutSeconds]);
            $acquired = is_object($row) ? ($row->acquired ?? null) : null;
            if ((int) $acquired !== 1) {
                throw new VehiclePlateLockNotAcquired($lockName);
            }
            $this->heldNames[] = $lockName;

            return;
        }

        $lock = Cache::lock($lockName, max($timeoutSeconds, 1));
        try {
            $got = $timeoutSeconds === 0 ? $lock->get() : $lock->block($timeoutSeconds);
        } catch (LockTimeoutException) {
            $got = false;
        }
        if ($got !== true) {
            throw new VehiclePlateLockNotAcquired($lockName);
        }
        $this->cacheLocks[$lockName] = $lock;
        $this->heldNames[] = $lockName;
    }

    private function releaseAll(): void
    {
        foreach (array_reverse($this->heldNames) as $lockName) {
            try {
                $this->release($lockName);
            } catch (Throwable) {
                // Keep releasing the remaining names.
            }
        }
        $this->heldNames = [];
        $this->cacheLocks = [];
    }

    private function release(string $lockName): void
    {
        $driver = DB::connection()->getDriverName();
        if ($driver === 'mysql' || $driver === 'mariadb') {
            DB::selectOne('SELECT RELEASE_LOCK(?) AS released', [$lockName]);

            return;
        }

        $this->cacheLocks[$lockName]?->release();
    }
}
