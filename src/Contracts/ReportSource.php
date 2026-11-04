<?php

namespace Abdalla\ReportGenerator\Contracts;

use Illuminate\Contracts\Auth\Authenticatable;
use Abdalla\ReportGenerator\Definitions\FieldDefinition;
use Abdalla\ReportGenerator\Definitions\ParameterDefinition;

/**
 * Wraps an existing, code-computed report so it can be used as a dataset in
 * the builder ("Mode 1"). The package materializes the streamed rows into a
 * staging table and then filters, groups, sorts, pages and exports them in
 * SQL like any other dataset.
 */
interface ReportSource
{
    /** Stable identifier, e.g. "sales_incentive". Letters, digits, underscores. */
    public function key(): string;

    public function label(): string;

    /** @return list<FieldDefinition> */
    public function fields(): array;

    /** @return list<ParameterDefinition> */
    public function parameters(): array;

    /**
     * Stream rows as associative arrays keyed by field key. Return a
     * generator or LazyCollection; never build the full result in memory.
     *
     * @param  array<string, mixed>  $parameters
     * @return iterable<array<string, mixed>>
     */
    public function rows(array $parameters, ?Authenticatable $user): iterable;
}
