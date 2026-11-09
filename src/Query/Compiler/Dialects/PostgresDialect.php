<?php

namespace Abdalla\ReportGenerator\Query\Compiler\Dialects;

use Abdalla\ReportGenerator\Query\Compiler\Dialect;
use Illuminate\Database\Connection;

final class PostgresDialect implements Dialect
{
    public function name(): string
    {
        return 'pgsql';
    }

    public function timeGrain(string $e, string $grain): string
    {
        return match ($grain) {
            'day', 'week', 'month', 'quarter', 'year' => "CAST(DATE_TRUNC('{$grain}', {$e}) AS DATE)",
            'hour' => "DATE_TRUNC('hour', {$e})",
            'month_of_year' => "CAST(EXTRACT(MONTH FROM {$e}) AS INTEGER)",
            'day_of_week' => "CAST(EXTRACT(ISODOW FROM {$e}) AS INTEGER)",
            'hour_of_day' => "CAST(EXTRACT(HOUR FROM {$e}) AS INTEGER)",
        };
    }

    public function booleanLiteral(bool $value): string
    {
        return $value ? 'TRUE' : 'FALSE';
    }

    public function booleanBinding(bool $value): mixed
    {
        return $value ? 'true' : 'false';
    }

    public function daysBetween(string $from, string $to): string
    {
        return "(CAST({$to} AS DATE) - CAST({$from} AS DATE))";
    }

    public function concat(array $parts): string
    {
        return 'CONCAT('.implode(', ', $parts).')';
    }

    public function greatestLeast(string $function, array $args): string
    {
        return $function.'('.implode(', ', $args).')';
    }

    public function likeOperator(): string
    {
        return 'ILIKE';
    }

    public function supportsRollup(): bool
    {
        return true;
    }

    public function rollupGroupBy(array $expressions): string
    {
        return 'ROLLUP('.implode(', ', $expressions).')';
    }

    public function timeoutHint(int $milliseconds): string
    {
        return '';
    }

    public function withTimeout(Connection $connection, int $milliseconds, \Closure $callback): mixed
    {
        if ($milliseconds <= 0) {
            return $callback();
        }

        // SET LOCAL lasts until the end of the transaction, so the timeout never leaks
        // into other queries that reuse this connection.
        return $connection->transaction(function () use ($connection, $milliseconds, $callback) {
            $connection->statement('SET LOCAL statement_timeout = '.(int) $milliseconds);

            return $callback();
        });
    }

    public function sessionId(Connection $connection): ?int
    {
        return (int) $connection->selectOne('SELECT pg_backend_pid() AS id')->id;
    }

    public function cancel(Connection $connection, int $sessionId): void
    {
        $connection->select('SELECT pg_cancel_backend(?)', [$sessionId]);
    }

    public function estimateRows(Connection $connection, string $sql, array $bindings): ?int
    {
        $row = $connection->selectOne('EXPLAIN (FORMAT JSON) '.$sql, $bindings);
        $plan = json_decode((string) (array_values((array) $row)[0] ?? '[]'), true);

        $max = null;
        $walk = function (array $node) use (&$walk, &$max) {
            if (str_contains((string) ($node['Node Type'] ?? ''), 'Scan')) {
                $max = max($max ?? 0, (int) ($node['Plan Rows'] ?? 0));
            }
            foreach ($node['Plans'] ?? [] as $child) {
                $walk($child);
            }
        };
        if (isset($plan[0]['Plan'])) {
            $walk($plan[0]['Plan']);
        }

        return $max;
    }

    public function interruption(\Throwable $e): ?string
    {
        // 57014 query_canceled covers both statement_timeout and pg_cancel_backend.
        if (($e->errorInfo[0] ?? null) !== '57014') {
            return null;
        }

        return str_contains($e->getMessage(), 'statement timeout') ? 'timeout' : 'cancelled';
    }
}
