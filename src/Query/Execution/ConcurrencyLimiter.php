<?php

namespace Abdalla\ReportGenerator\Query\Execution;

use Abdalla\ReportGenerator\Query\QueryException;
use Closure;
use Illuminate\Contracts\Cache\Repository;

/**
 * Caps how many report queries run at once, per user and overall, with
 * cache locks as slots. A request waits up to limits.queue_wait_ms for a
 * slot (a dashboard asks for all its widgets at once), then gets a clear
 * "try again" instead of saturating the database.
 */
final class ConcurrencyLimiter
{
    public function __construct(private readonly Repository $cache)
    {
    }

    public function run(string|int|null $userId, int $ttlSeconds, Closure $callback): mixed
    {
        $release = $this->hold($userId, $ttlSeconds);

        try {
            return $callback();
        } finally {
            $release();
        }
    }

    /**
     * Takes a user slot and a global slot until the returned closure is
     * called (a streamed export keeps them while the file is written).
     *
     * @return Closure(): void  releases both slots
     */
    public function hold(string|int|null $userId, int $ttlSeconds): Closure
    {
        $perUser = (int) config('report-generator.limits.max_concurrent_per_user', 2);
        $global = (int) config('report-generator.limits.max_concurrent_global', 10);
        $deadline = microtime(true) + max(0, (int) config('report-generator.limits.queue_wait_ms', 15000)) / 1000;

        $userLock = $perUser > 0 ? $this->acquire('user:'.($userId ?? 'guest'), $perUser, $ttlSeconds, $deadline) : null;
        if ($perUser > 0 && $userLock === null) {
            throw new QueryException('TOO_MANY_QUERIES', "You already have {$perUser} reports running. Wait for one to finish, then try again.", status: 429);
        }

        $globalLock = $global > 0 ? $this->acquire('global', $global, $ttlSeconds, $deadline) : null;
        if ($global > 0 && $globalLock === null) {
            $userLock?->release();
            throw new QueryException('SERVER_BUSY', 'The report server is busy. Try again in a few seconds.', status: 429);
        }

        $released = false;

        return function () use ($userLock, $globalLock, &$released) {
            if ($released) {
                return;
            }
            $released = true;
            $globalLock?->release();
            $userLock?->release();
        };
    }

    /** A free slot, waiting for one until the deadline. */
    private function acquire(string $scope, int $slots, int $ttl, float $deadline): ?\Illuminate\Contracts\Cache\Lock
    {
        do {
            for ($slot = 0; $slot < $slots; $slot++) {
                $lock = $this->cache->getStore()->lock("report-generator:slot:{$scope}:{$slot}", $ttl);
                if ($lock->get()) {
                    return $lock;
                }
            }
            if (microtime(true) >= $deadline) {
                return null;
            }
            usleep(100_000);
        } while (true);
    }
}
