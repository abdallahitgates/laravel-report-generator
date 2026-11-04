<?php

namespace Abdalla\ReportGenerator\Schema;

final class TableSchema
{
    public const TABLE = 'table';
    public const VIEW = 'view';

    /** @var array<string, ColumnSchema> */
    public readonly array $columns;

    /**
     * @param  iterable<ColumnSchema>  $columns  in ordinal order
     * @param  list<IndexSchema>  $indexes
     * @param  list<ForeignKeySchema>  $foreignKeys
     */
    public function __construct(
        public readonly string $name,
        public readonly string $type = self::TABLE,
        iterable $columns = [],
        public readonly array $indexes = [],
        public readonly array $foreignKeys = [],
        public readonly ?string $comment = null,
        public readonly ?int $rowEstimate = null,
    ) {
        $byName = [];
        foreach ($columns as $column) {
            $byName[$column->name] = $column;
        }
        $this->columns = $byName;
    }

    public function column(string $name): ?ColumnSchema
    {
        return $this->columns[$name] ?? null;
    }

    public function hasColumn(string $name): bool
    {
        return isset($this->columns[$name]);
    }

    /** @return list<string> */
    public function primaryKey(): array
    {
        foreach ($this->indexes as $index) {
            if ($index->primary) {
                return $index->columns;
            }
        }

        return [];
    }

    /** True when an index can serve lookups on this column (it is the index's first column). */
    public function isIndexed(string $column): bool
    {
        foreach ($this->indexes as $index) {
            if (($index->columns[0] ?? null) === $column) {
                return true;
            }
        }

        return false;
    }

    /** True when this column alone is unique, which makes a join on it one-to-one. */
    public function isUnique(string $column): bool
    {
        foreach ($this->indexes as $index) {
            if (($index->unique || $index->primary) && $index->columns === [$column]) {
                return true;
            }
        }

        return false;
    }

    public function isView(): bool
    {
        return $this->type === self::VIEW;
    }

    public function toArray(): array
    {
        return [
            'name' => $this->name,
            'type' => $this->type,
            'comment' => $this->comment,
            'row_estimate' => $this->rowEstimate,
            'columns' => array_values(array_map(fn (ColumnSchema $c) => $c->toArray(), $this->columns)),
            'indexes' => array_map(fn (IndexSchema $i) => $i->toArray(), $this->indexes),
            'foreign_keys' => array_map(fn (ForeignKeySchema $f) => $f->toArray(), $this->foreignKeys),
        ];
    }

    public static function fromArray(array $data): self
    {
        return new self(
            $data['name'],
            $data['type'] ?? self::TABLE,
            array_map([ColumnSchema::class, 'fromArray'], $data['columns'] ?? []),
            array_map([IndexSchema::class, 'fromArray'], $data['indexes'] ?? []),
            array_map([ForeignKeySchema::class, 'fromArray'], $data['foreign_keys'] ?? []),
            $data['comment'] ?? null,
            isset($data['row_estimate']) ? (int) $data['row_estimate'] : null,
        );
    }
}
