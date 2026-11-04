<?php

namespace Abdalla\ReportGenerator\Query\Compiler\Dialects;

use Abdalla\ReportGenerator\Query\Compiler\Dialect;
use Illuminate\Database\Connection;

/** For tests and small embedded setups: no rollup, no timeouts, no cancellation. */
final class SqliteDialect implements Dialect
{
    public function name(): string
    {
        return 'sqlite';
    }

    public function timeGrain(string $e, string $grain): string
    {
        return match ($grain) {
            'day' => "date({$e})",
            'week' => "date({$e}, '-' || ((CAST(strftime('%w', {$e}) AS INTEGER) + 6) % 7) || ' days')",
            'month' => "strftime('%Y-%m-01', {$e})",
            'quarter' => "printf('%s-%02d-01', strftime('%Y', {$e}), ((CAST(strftime('%m', {$e}) AS INTEGER) - 1) / 3) * 3 + 1)",
            'year' => "strftime('%Y-01-01', {$e})",
            'hour' => "strftime('%Y-%m-%d %H:00:00', {$e})",
            'month_of_year' => "CAST(strftime('%m', {$e}) AS INTEGER)",
            'day_of_week' => "((CAST(strftime('%w', {$e}) AS INTEGER) + 6) % 7 + 1)",
            'hour_of_day' => "CAST(strftime('%H', {$e}) AS INTEGER)",
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
        return '('.implode(' || ', array_map(fn ($p) => "COALESCE({$p}, '')", $parts)).')';
    }

    public function greatestLeast(string $function, array $args): string
    {
        // SQLite's multi-argument MAX/MIN are the scalar GREATEST/LEAST.
        return ($function === 'GREATEST' ? 'MAX' : 'MIN').'('.implode(', ', $args).')';
    }

    public function likeOperator(): string
    {
        return 'LIKE';
    }

    public function supportsRollup(): bool
    {
        return false;
    }

    public function rollupGroupBy(array $expressions): string
    {
        throw new \LogicException('SQLite has no ROLLUP.');
    }

    public function timeoutHint(int $milliseconds): string
    {
        return '';
    }

    public function withTimeout(Connection $connection, int $milliseconds, \Closure $callback): mixed
    {
        return $callback();
    }

    public function sessionId(Connection $connection): ?int
    {
        return null;
    }

    public function cancel(Connection $connection, int $sessionId): void
    {
    }

    public function estimateRows(Connection $connection, string $sql, array $bindings): ?int
    {
        return null;
    }

    public function interruption(\Throwable $e): ?string
    {
        return null;
    }
}
