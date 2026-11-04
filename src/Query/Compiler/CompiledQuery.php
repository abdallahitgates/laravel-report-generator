<?php

namespace Abdalla\ReportGenerator\Query\Compiler;

use Illuminate\Database\Connection;
use Illuminate\Database\Query\Builder;

final class CompiledQuery
{
    public const DETAIL = 'detail';
    public const AGGREGATE = 'aggregate';
    /** Measures from different one-to-many branches, aggregated separately and combined. */
    public const MULTI_FACT = 'multi_fact';

    /**
     * @param  list<array{alias: string, label: string, type: string, kind: string, format: ?string}>  $columns
     * @param  list<string>  $datasets
     * @param  list<string>  $filteredFields  dataset.field refs used in filters (index advisor input)
     */
    public function __construct(
        public readonly Builder $query,
        public readonly array $columns,
        public readonly string $mode,
        public readonly Dialect $dialect,
        public readonly Connection $connection,
        public readonly array $datasets,
        public readonly array $filteredFields,
    ) {
    }

    public function sql(): string
    {
        return $this->query->toSql();
    }

    public function bindings(): array
    {
        return $this->query->getBindings();
    }
}
