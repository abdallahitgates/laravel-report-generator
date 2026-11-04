<?php

namespace Abdalla\ReportGenerator\Schema;

final class IndexSchema
{
    /** @param  list<string>  $columns */
    public function __construct(
        public readonly string $name,
        public readonly array $columns,
        public readonly bool $unique,
        public readonly bool $primary,
    ) {
    }

    public function toArray(): array
    {
        return ['name' => $this->name, 'columns' => $this->columns, 'unique' => $this->unique, 'primary' => $this->primary];
    }

    public static function fromArray(array $data): self
    {
        return new self($data['name'], array_values($data['columns']), (bool) $data['unique'], (bool) $data['primary']);
    }
}
