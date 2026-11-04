<?php

namespace Abdalla\ReportGenerator\Semantic\Published;

use Abdalla\ReportGenerator\Enums\Cardinality;

final class PublishedRelationship
{
    public function __construct(
        public readonly int $id,
        public readonly string $from,
        public readonly string $fromColumn,
        public readonly string $to,
        public readonly string $toColumn,
        public readonly Cardinality $cardinality,
        public readonly string $joinType,
        public readonly ?string $label,
    ) {
    }

    public static function fromArray(array $data): self
    {
        return new self(
            (int) $data['id'],
            $data['from'],
            $data['from_column'],
            $data['to'],
            $data['to_column'],
            Cardinality::from($data['cardinality']),
            $data['join_type'] ?? 'left',
            $data['label'] ?? null,
        );
    }
}
