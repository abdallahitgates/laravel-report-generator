<?php

namespace Abdalla\ReportGenerator\Schema;

final class ForeignKeySchema
{
    /**
     * @param  list<string>  $columns
     * @param  list<string>  $foreignColumns
     */
    public function __construct(
        public readonly string $name,
        public readonly array $columns,
        public readonly string $foreignTable,
        public readonly array $foreignColumns,
    ) {
    }

    public function toArray(): array
    {
        return [
            'name' => $this->name,
            'columns' => $this->columns,
            'foreign_table' => $this->foreignTable,
            'foreign_columns' => $this->foreignColumns,
        ];
    }

    public static function fromArray(array $data): self
    {
        return new self($data['name'], array_values($data['columns']), $data['foreign_table'], array_values($data['foreign_columns']));
    }
}
