<?php

namespace Abdalla\ReportGenerator\Schema;

/**
 * Everything the scanner learned about one database, at one point in time.
 */
final class SchemaSnapshot
{
    /** @var array<string, TableSchema> */
    public readonly array $tables;

    /** @param  iterable<TableSchema>  $tables */
    public function __construct(
        public readonly string $driver,
        public readonly string $connection,
        iterable $tables,
        public readonly ?string $database = null,
        public readonly ?string $schema = null,
    ) {
        $byName = [];
        foreach ($tables as $table) {
            $byName[$table->name] = $table;
        }
        ksort($byName);
        $this->tables = $byName;
    }

    public function table(string $name): ?TableSchema
    {
        return $this->tables[$name] ?? null;
    }

    /** @param  callable(TableSchema): bool  $keep */
    public function filter(callable $keep): self
    {
        return new self($this->driver, $this->connection, array_filter($this->tables, $keep), $this->database, $this->schema);
    }

    /**
     * Fingerprint of the structure only. Row estimates and comments change
     * without the structure changing, so they are left out.
     */
    public function hash(): string
    {
        $structure = array_map(fn (TableSchema $t) => [
            $t->type,
            array_map(fn (ColumnSchema $c) => [$c->name, $c->type, $c->nullable], array_values($t->columns)),
            array_map(fn (IndexSchema $i) => $i->toArray(), $t->indexes),
            array_map(fn (ForeignKeySchema $f) => $f->toArray(), $t->foreignKeys),
        ], $this->tables);

        return hash('sha256', json_encode($structure));
    }

    public function toArray(): array
    {
        return [
            'driver' => $this->driver,
            'connection' => $this->connection,
            'database' => $this->database,
            'schema' => $this->schema,
            'tables' => array_values(array_map(fn (TableSchema $t) => $t->toArray(), $this->tables)),
        ];
    }

    public static function fromArray(array $data): self
    {
        return new self(
            $data['driver'],
            $data['connection'],
            array_map([TableSchema::class, 'fromArray'], $data['tables'] ?? []),
            $data['database'] ?? null,
            $data['schema'] ?? null,
        );
    }
}
