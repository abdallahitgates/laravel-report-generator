<?php

namespace Abdalla\ReportGenerator\Semantic\Published;

final class PublishedDataset
{
    /**
     * @param  array<string, PublishedField>  $fields  by key
     * @param  list<string>  $primaryKey
     * @param  array<string, scalar|list<scalar>|null>  $conditions  column => value(s) every row has
     */
    public function __construct(
        public readonly string $key,
        public readonly string $label,
        public readonly string $sourceName,
        public readonly array $fields,
        public readonly array $primaryKey,
        public readonly ?string $defaultTimeField,
        public readonly ?string $softDeleteColumn,
        public readonly ?int $rowEstimate,
        public readonly ?string $group = null,
        public readonly ?string $description = null,
        public readonly ?string $countLabel = null,
        public readonly ?string $employeeColumn = null,
        public readonly ?string $divisionColumn = null,
        public readonly ?string $lineColumn = null,
        public readonly ?string $dateColumn = null,
        public readonly bool $public = false,
        public readonly ?string $permission = null,
        public readonly ?string $validFromColumn = null,
        public readonly ?string $validToColumn = null,
        public readonly array $conditions = [],
        public readonly bool $lookup = false,
        public readonly array $scopeColumns = [],
    ) {
    }

    /**
     * Restricts a query to the rows this topic is made of: not soft-deleted,
     * and matching the topic's fixed conditions. On a join, pass the
     * JoinClause: rows pointing at excluded lookup rows are kept.
     */
    public function constrain(\Illuminate\Database\Query\Builder $query, string $alias): void
    {
        if ($this->softDeleteColumn) {
            $query->whereNull("{$alias}.{$this->softDeleteColumn}");
        }
        foreach ($this->conditions as $column => $value) {
            match (true) {
                $value === null => $query->whereNull("{$alias}.{$column}"),
                is_array($value) => $query->whereIn("{$alias}.{$column}", $value),
                default => $query->where("{$alias}.{$column}", '=', $value),
            };
        }
    }

    /** Whether the row scope applies: the topic has an employee, division or extra scope column. */
    public function isScoped(): bool
    {
        return $this->employeeColumn !== null || $this->divisionColumn !== null || $this->scopeColumns !== [];
    }

    public static function fromArray(array $data): self
    {
        $fields = [];
        foreach ($data['fields'] ?? [] as $field) {
            $fields[$field['key']] = PublishedField::fromArray($field);
        }

        return new self(
            $data['key'],
            $data['label'],
            $data['source_name'],
            $fields,
            $data['primary_key'] ?? [],
            $data['default_time_field'] ?? null,
            $data['soft_delete_column'] ?? null,
            $data['row_estimate'] ?? null,
        );
    }

    public function field(string $key): ?PublishedField
    {
        return $this->fields[$key] ?? null;
    }
}
