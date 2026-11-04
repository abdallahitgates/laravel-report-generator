<?php

namespace Abdalla\ReportGenerator\Definitions;

use Abdalla\ReportGenerator\Enums\Aggregation;
use Abdalla\ReportGenerator\Enums\DataType;
use Abdalla\ReportGenerator\Enums\FieldRole;

/**
 * Describes one column a dataset or ReportSource exposes to the builder.
 */
final class FieldDefinition implements \JsonSerializable
{
    /** @var list<Aggregation> */
    public readonly array $aggregations;

    /**
     * @param  list<Aggregation>|null  $aggregations  null = sensible defaults for the type
     */
    public function __construct(
        public readonly string $key,
        public readonly string $label,
        public readonly DataType $type,
        public readonly FieldRole $role,
        public readonly ?string $format = null,
        ?array $aggregations = null,
        public readonly ?string $description = null,
    ) {
        if (! preg_match('/^[a-z_][a-z0-9_]*$/i', $key)) {
            throw new \InvalidArgumentException("Invalid field key [{$key}]: use letters, digits and underscores.");
        }

        $this->aggregations = $aggregations ?? ($role === FieldRole::Measure ? Aggregation::forType($type) : []);
    }

    public static function dimension(string $key, string $label, DataType $type = DataType::String): self
    {
        return new self($key, $label, $type, $type->isTemporal() ? FieldRole::Time : FieldRole::Dimension);
    }

    public static function measure(string $key, string $label, DataType $type = DataType::Decimal, ?string $format = null): self
    {
        return new self($key, $label, $type, FieldRole::Measure, $format);
    }

    public function jsonSerialize(): array
    {
        return [
            'key' => $this->key,
            'label' => $this->label,
            'type' => $this->type->value,
            'role' => $this->role->value,
            'format' => $this->format,
            'aggregations' => array_map(fn (Aggregation $a) => $a->value, $this->aggregations),
            'description' => $this->description,
        ];
    }
}
