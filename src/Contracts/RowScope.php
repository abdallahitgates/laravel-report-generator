<?php

namespace Abdalla\ReportGenerator\Contracts;

use Abdalla\ReportGenerator\Security\ScopeContext;
use Illuminate\Database\Query\Builder;

/**
 * The host's rule for which rows of a topic a user may see, applied to
 * every topic that declares an employee or division column, wherever it
 * appears in a query (root, joins, EXISTS filters). Users with the
 * view-all ability are not scoped.
 */
interface RowScope
{
    public function apply(Builder $query, ScopeContext $context): void;
}
