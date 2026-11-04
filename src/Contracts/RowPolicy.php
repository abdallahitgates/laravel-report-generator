<?php

namespace Abdalla\ReportGenerator\Contracts;

use Illuminate\Database\Query\Builder;
use Abdalla\ReportGenerator\Security\PolicyContext;

/**
 * Row-level security for one dataset. Applied every time the dataset takes
 * part in a query, whether it is the report's root or reached through a join.
 */
interface RowPolicy
{
    public function apply(Builder $query, PolicyContext $context): void;
}
