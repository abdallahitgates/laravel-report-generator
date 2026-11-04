<?php

namespace Abdalla\ReportGenerator\Schema\Relationships;

use Abdalla\ReportGenerator\Enums\Cardinality;

/**
 * A join the scanner believes exists. Always stored from the "many" side
 * (the table holding the reference) to the "one" side (the referenced key),
 * so each physical link is recorded exactly once.
 */
final class InferredRelationship
{
    public const ORIGIN_FOREIGN_KEY = 'foreign_key';
    public const ORIGIN_ELOQUENT = 'eloquent';
    public const ORIGIN_CONVENTION = 'convention';
    public const ORIGIN_MANUAL = 'manual';

    public function __construct(
        public readonly string $fromTable,
        public readonly string $fromColumn,
        public readonly string $toTable,
        public readonly string $toColumn,
        public readonly Cardinality $cardinality,
        public readonly string $origin,
        public readonly float $confidence,
    ) {
    }

    public function edgeKey(): string
    {
        return "{$this->fromTable}.{$this->fromColumn}>{$this->toTable}.{$this->toColumn}";
    }
}
