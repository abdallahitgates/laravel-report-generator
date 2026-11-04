<?php

namespace Abdalla\ReportGenerator\Query\Compiler\Dialects;

use Abdalla\ReportGenerator\Query\Compiler\Dialect;
use Illuminate\Database\Connection;

final class MySqlDialect implements Dialect
{
    public function name(): string
    {
        return 'mysql';
    }

    public function timeGrain(string $e, string $grain): string
    {
        return match ($grain) {
            'day' => "DATE({$e})",
            'week' => "DATE(DATE_SUB({$e}, INTERVAL WEEKDAY({$e}) DAY))",
            'month' => "CAST(DATE_FORMAT({$e}, '%Y-%m-01') AS DATE)",
            'quarter' => "(MAKEDATE(YEAR({$e}), 1) + INTERVAL (QUARTER({$e}) - 1) QUARTER)",
            'year' => "MAKEDATE(YEAR({$e}), 1)",
            'hour' => "DATE_FORMAT({$e}, '%Y-%m-%d %H:00:00')",
            'month_of_year' => "MONTH({$e})",
            'day_of_week' => "(WEEKDAY({$e}) + 1)",
            'hour_of_day' => "HOUR({$e})",
        };
    }

    public function booleanLiteral(bool $value): string
    {
        return $value ? '1' : '0';
    }

    public function booleanBinding(bool $value): mixed
    {
        return $value ? 1 : 0;
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
        return 'LIKE';
    }

    public function supportsRollup(): bool
    {
        return true;
    }

    public function rollupGroupBy(array $expressions): string
    {
        return implode(', ', $expressions).' WITH ROLLUP';
    }

    public function timeoutHint(int $milliseconds): string
    {
        return $milliseconds > 0 ? "/*+ MAX_EXECUTION_TIME({$milliseconds}) */ " : '';
    }

    public function withTimeout(Connection $connection, int $milliseconds, \Closure $callback): mixed
    {
        return $callback();
    }

    public function sessionId(Connection $connection): ?int
    {
        return (int) $connection->selectOne('SELECT CONNECTION_ID() AS id')->id;
    }

    public function cancel(Connection $connection, int $sessionId): void
    {
        $connection->statement('KILL QUERY '.(int) $sessionId);
    }

    public function estimateRows(Connection $connection, string $sql, array $bindings): ?int
    {
        // The largest per-table row estimate: a good proxy for "how much will this read".
        $max = null;
        foreach ($connection->select('EXPLAIN '.$sql, $bindings) as $row) {
            $rows = (array) $row;
            $estimate = $rows['rows'] ?? $rows['ROWS'] ?? null;
            if ($estimate !== null) {
                $max = max($max ?? 0, (int) $estimate);
            }
        }

        return $max;
    }

    public function interruption(\Throwable $e): ?string
    {
        $code = (int) ($e->errorInfo[1] ?? 0);

        return match ($code) {
            3024 => 'timeout',   // maximum statement execution time exceeded
            1317 => 'cancelled', // query execution was interrupted (KILL QUERY)
            default => null,
        };
    }
}
