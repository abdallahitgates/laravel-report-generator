<?php

namespace Abdalla\ReportGenerator\Security;

use Illuminate\Contracts\Auth\Authenticatable;

/**
 * What a row policy needs to know. The engine aliases tables in generated
 * SQL, so policies must qualify columns through column() rather than
 * hard-coding "table.column".
 */
final class PolicyContext
{
    public function __construct(
        public readonly string $dataset,
        public readonly string $alias,
        public readonly ?Authenticatable $user,
    ) {
    }

    public function column(string $column): string
    {
        return $this->alias.'.'.$column;
    }
}
