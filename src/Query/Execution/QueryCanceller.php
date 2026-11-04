<?php

namespace Abdalla\ReportGenerator\Query\Execution;

use Abdalla\ReportGenerator\Query\Compiler\Dialect;
use Illuminate\Contracts\Cache\Repository;
use Illuminate\Database\DatabaseManager;

/**
 * Lets the user who started a query stop it from another request: the
 * running request records its database session id under a client-chosen
 * query id, and cancel() asks the database to interrupt that session.
 */
final class QueryCanceller
{
    public function __construct(
        private readonly Repository $cache,
        private readonly DatabaseManager $db,
    ) {
    }

    public function register(string $queryId, string|int|null $userId, string $connection, int $sessionId, int $ttlSeconds): void
    {
        $this->cache->put($this->key($queryId), compact('userId', 'connection', 'sessionId'), $ttlSeconds);
    }

    public function forget(string $queryId): void
    {
        $this->cache->forget($this->key($queryId));
    }

    public function cancel(string $queryId, string|int|null $userId, Dialect $dialect): bool
    {
        $entry = $this->cache->get($this->key($queryId));
        if (! $entry || (string) $entry['userId'] !== (string) $userId) {
            return false;
        }

        $dialect->cancel($this->db->connection($entry['connection']), $entry['sessionId']);
        $this->forget($queryId);

        return true;
    }

    private function key(string $queryId): string
    {
        return 'report-generator:running:'.hash('sha256', $queryId);
    }
}
