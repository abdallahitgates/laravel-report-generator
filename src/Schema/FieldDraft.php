<?php

namespace Abdalla\ReportGenerator\Schema;

use Abdalla\ReportGenerator\Enums\Aggregation;
use Abdalla\ReportGenerator\Enums\DataType;
use Abdalla\ReportGenerator\Enums\FieldRole;

/** What the scanner proposes for one column, before the admin reviews it. */
final class FieldDraft
{
    /** @param  list<Aggregation>  $aggregations */
    public function __construct(
        public readonly string $key,
        public readonly string $column,
        public readonly string $label,
        public readonly DataType $type,
        public readonly FieldRole $role,
        public readonly array $aggregations,
        public readonly ?string $format,
        public readonly bool $hidden,
        public readonly bool $sensitive,
        public readonly bool $indexed,
        public readonly int $sortOrder,
        /** Why the field starts hidden, shown to the admin: key, sensitive, unsupported, soft_delete, json. */
        public readonly ?string $hiddenReason = null,
    ) {
    }
}
