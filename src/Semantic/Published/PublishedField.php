<?php

namespace Abdalla\ReportGenerator\Semantic\Published;

use Abdalla\ReportGenerator\Enums\Aggregation;
use Abdalla\ReportGenerator\Enums\DataType;
use Abdalla\ReportGenerator\Enums\FieldRole;

final class PublishedField
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
        public readonly bool $indexed,
    ) {
    }

    public static function fromArray(array $data): self
    {
        return new self(
            $data['key'],
            $data['column'],
            $data['label'],
            DataType::from($data['type']),
            FieldRole::from($data['role']),
            array_map(fn ($a) => Aggregation::from($a), $data['aggregations'] ?? []),
            $data['format'] ?? null,
            (bool) ($data['indexed'] ?? false),
        );
    }

    public function allows(Aggregation $aggregation): bool
    {
        // Keys are hidden from the field list but counting them is always meaningful.
        if ($this->role === FieldRole::Key) {
            return in_array($aggregation, [Aggregation::Count, Aggregation::CountDistinct], true);
        }

        return in_array($aggregation, $this->aggregations, true)
            || in_array($aggregation, [Aggregation::Count, Aggregation::CountDistinct], true);
    }

    public function toArray(): array
    {
        return [
            'key' => $this->key,
            'label' => $this->label,
            'type' => $this->type->value,
            'role' => $this->role->value,
            'aggregations' => array_map(fn (Aggregation $a) => $a->value, $this->aggregations),
            'format' => $this->format,
        ];
    }
}
