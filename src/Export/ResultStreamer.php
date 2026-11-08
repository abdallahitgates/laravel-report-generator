<?php

namespace Abdalla\ReportGenerator\Export;

use Abdalla\ReportGenerator\Query\Compiler\CompiledQuery;
use PDO;

/**
 * Reads a compiled report in batches without loading it into memory:
 * MySQL unbuffered (rows come off the socket as they are read),
 * PostgreSQL through a server-side cursor, SQLite as it already streams.
 */
final class ResultStreamer
{
    /** @return \Generator<int, list<array<string, mixed>>> batches of rows */
    public function stream(CompiledQuery $compiled, int $timeoutMs, int $chunk): \Generator
    {
        return match ($compiled->connection->getDriverName()) {
            'mysql', 'mariadb' => $this->mysql($compiled, $chunk),
            'pgsql' => $this->postgres($compiled, $timeoutMs, $chunk),
            default => $this->cursor($compiled, $chunk),
        };
    }

    private function mysql(CompiledQuery $compiled, int $chunk): \Generator
    {
        $connection = $compiled->connection;
        $pdo = $connection->getReadPdo();
        $buffered = $pdo->getAttribute(PDO::MYSQL_ATTR_USE_BUFFERED_QUERY);
        $pdo->setAttribute(PDO::MYSQL_ATTR_USE_BUFFERED_QUERY, false);

        try {
            yield from $this->cursor($compiled, $chunk);
        } finally {
            $pdo->setAttribute(PDO::MYSQL_ATTR_USE_BUFFERED_QUERY, $buffered);
        }
    }

    private function postgres(CompiledQuery $compiled, int $timeoutMs, int $chunk): \Generator
    {
        $connection = $compiled->connection;
        $cursor = 'rg_export_'.bin2hex(random_bytes(6));
        $connection->beginTransaction();
        $done = false;

        try {
            if ($timeoutMs > 0) {
                $connection->statement('SET LOCAL statement_timeout = '.(int) $timeoutMs);
            }
            $connection->statement("DECLARE {$cursor} NO SCROLL CURSOR FOR ".$compiled->sql(), $compiled->bindings());
            while (true) {
                $rows = array_map(fn ($r) => (array) $r, $connection->select("FETCH FORWARD {$chunk} FROM {$cursor}"));
                if ($rows === []) {
                    break;
                }
                yield $rows;
            }
            $connection->statement("CLOSE {$cursor}");
            $connection->commit();
            $done = true;
        } finally {
            // Stopped early (client went away) or failed: drop the cursor with the transaction.
            if (! $done) {
                $connection->rollBack();
            }
        }
    }

    private function cursor(CompiledQuery $compiled, int $chunk): \Generator
    {
        $batch = [];
        foreach ($compiled->connection->cursor($compiled->sql(), $compiled->bindings()) as $row) {
            $batch[] = (array) $row;
            if (count($batch) >= $chunk) {
                yield $batch;
                $batch = [];
            }
        }
        if ($batch !== []) {
            yield $batch;
        }
    }
}
