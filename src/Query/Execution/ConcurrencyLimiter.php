<?php

namespace Abdalla\ReportGenerator\Query\Execution;

use Abdalla\ReportGenerator\Query\QueryException;
use Closure;
use Illuminate\Contracts\Cache\Repository;

/**
 * Caps how many report queries run at once, per user and overall, with
 * cache locks as slots. Ten people opening heavy reports at the same time
 * get a clear "try again" instead of saturating the database.
 */
final class ConcurrencyLimiter
{
    public function __construct(private readonly Repository $cache)
    {
    }

    public function run(string|int|null $userId, int $ttlSeconds, Closure $callback): mixed
    {
        $perUser = (int) config('report-generator.limits.max_concurrent_per_user', 2);
        $global = (int) config('report-generator.limits.max_concurrent_global', 10);

        $userLock = $perUser > 0 ? $this->acquire('user:'.($userId ?? 'guest'), $perUser, $ttlSeconds) : null;
        if ($perUser > 0 && $userLock === null) {
            throw new QueryException('TOO_MANY_QUERIES', "You already have {$perUser} reports running. Wait for one to finish, then try again.", status: 429);
        }

        try {
            $globalLock = $global > 0 ? $this->acquire('global', $global, $ttlSeconds) : null;
            if ($global > 0 && $globalLock === null) {
                throw new QueryException('SERVER_BUSY', 'The report server is busy. Try again in a few seconds.', status: 429);
            }

            try {
                return $callback();
            } finally {
                $globalLock?->release();
            }
        } finally {
            $userLock?->release();
        }
    }

    private function acquire(string $scope, int $slots, int $ttl): ?\Illuminate\Contracts\Cache\Lock
    {
        for ($slot = 0; $slot < $slots; $slot++) {
            $lock = $this->cache->getStore()->lock("report-generator:slot:{$scope}:{$slot}", $ttl);
            if ($lock->get()) {
                return $lock;
            }
        }

        return null;
    }
}
