<?php

namespace Abdalla\ReportGenerator\Query\Compiler;

use Illuminate\Database\Connection;

/**
 * What differs between databases in the SQL the engine writes and in how
 * it guards and cancels queries.
 */
interface Dialect
{
    public function name(): string;

    /** SQL bucketing a date/datetime expression: day, week (Monday), month, quarter, year, hour, or a part like month_of_year. */
    public function timeGrain(string $expression, string $grain): string;

    public function booleanLiteral(bool $value): string;

    /** A PHP boolean as a binding the database compares correctly with a boolean column. */
    public function booleanBinding(bool $value): mixed;

    /** @param  list<string>  $parts */
    public function concat(array $parts): string;

    /** @param  list<string>  $args */
    public function greatestLeast(string $function, array $args): string;

    /** Case-insensitive LIKE keyword. */
    public function likeOperator(): string;

    public function supportsRollup(): bool;

    /** @param  list<string>  $expressions */
    public function rollupGroupBy(array $expressions): string;

    /** Prefix placed right after SELECT to cap execution time, or '' when done another way. */
    public function timeoutHint(int $milliseconds): string;

    /** Runs $callback with a statement timeout in force, for dialects that cannot use a hint. */
    public function withTimeout(Connection $connection, int $milliseconds, \Closure $callback): mixed;

    /** Identifier of the server-side session, so another request can cancel the query. */
    public function sessionId(Connection $connection): ?int;

    public function cancel(Connection $connection, int $sessionId): void;

    /** Largest number of rows the planner expects to read, or null if unknown. */
    public function estimateRows(Connection $connection, string $sql, array $bindings): ?int;

    /** Whether an exception means the statement hit the timeout or was cancelled. */
    public function interruption(\Throwable $e): ?string;
}
