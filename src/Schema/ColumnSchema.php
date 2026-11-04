<?php

namespace Abdalla\ReportGenerator\Schema;

final class ColumnSchema
{
    public function __construct(
        public readonly string $name,
        /** Full declared type, e.g. "int unsigned", "varchar(255)", "timestamp(0) without time zone". */
        public readonly string $type,
        /** Base type name, lowercase, e.g. "int", "varchar", "int4", "timestamptz". */
        public readonly string $typeName,
        public readonly bool $nullable,
        public readonly ?string $comment = null,
        public readonly bool $autoIncrement = false,
    ) {
    }

    public function toArray(): array
    {
        return [
            'name' => $this->name,
            'type' => $this->type,
            'type_name' => $this->typeName,
            'nullable' => $this->nullable,
            'comment' => $this->comment,
            'auto_increment' => $this->autoIncrement,
        ];
    }

    public static function fromArray(array $data): self
    {
        return new self(
            $data['name'],
            $data['type'],
            $data['type_name'],
            (bool) $data['nullable'],
            $data['comment'] ?? null,
            (bool) ($data['auto_increment'] ?? false),
        );
    }
}
