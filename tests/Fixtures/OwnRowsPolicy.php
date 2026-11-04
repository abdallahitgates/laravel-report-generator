<?php

namespace Abdalla\ReportGenerator\Tests\Fixtures;

use Illuminate\Database\Query\Builder;
use Abdalla\ReportGenerator\Contracts\RowPolicy;
use Abdalla\ReportGenerator\Security\PolicyContext;

class OwnRowsPolicy implements RowPolicy
{
    public function apply(Builder $query, PolicyContext $context): void
    {
        $query->where($context->column('user_id'), $context->user?->getAuthIdentifier());
    }
}
