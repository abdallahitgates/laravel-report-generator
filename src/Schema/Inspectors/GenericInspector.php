<?php

namespace Abdalla\ReportGenerator\Schema\Inspectors;

use Abdalla\ReportGenerator\Schema\ColumnSchema;
use Abdalla\ReportGenerator\Schema\ForeignKeySchema;
use Abdalla\ReportGenerator\Schema\IndexSchema;
use Abdalla\ReportGenerator\Schema\SchemaSnapshot;
use Abdalla\ReportGenerator\Schema\TableSchema;
use Illuminate\Database\Connection;

/**
 * Fallback for drivers without a bulk inspector (SQLite, SQL Server, ...),
 * built on Laravel's schema builder. Three queries per table and no row
 * estimates, so it is slower on large schemas.
 */
final class GenericInspector implements SchemaInspector
{
    public function inspect(Connection $connection): SchemaSnapshot
    {
        $builder = $connection->getSchemaBuilder();

        $entries = [];
        foreach ($builder->getTables() as $table) {
            $entries[] = [$table['name'], TableSchema::TABLE, $table['comment'] ?? null];
        }
        foreach ($builder->getViews() as $view) {
            $entries[] = [$view['name'], TableSchema::VIEW, null];
        }

        $tables = [];
        foreach ($entries as [$name, $type, $comment]) {
            if (str_starts_with($name, 'sqlite_')) {
                continue;
            }

            $columns = array_map(fn (array $c) => new ColumnSchema(
                name: $c['name'],
                type: strtolower((string) $c['type']),
                typeName: strtolower((string) $c['type_name']),
                nullable: (bool) $c['nullable'],
                comment: ($c['comment'] ?? null) ?: null,
                autoIncrement: (bool) ($c['auto_increment'] ?? false),
            ), $builder->getColumns($name));

            $indexes = $type === TableSchema::VIEW ? [] : array_map(fn (array $i) => new IndexSchema(
                $i['name'], array_values($i['columns']), (bool) $i['unique'], (bool) $i['primary']
            ), $builder->getIndexes($name));

            $foreignKeys = $type === TableSchema::VIEW ? [] : array_map(fn (array $f) => new ForeignKeySchema(
                (string) ($f['name'] ?? ''), array_values($f['columns']), $f['foreign_table'], array_values($f['foreign_columns'])
            ), $builder->getForeignKeys($name));

            $tables[] = new TableSchema($name, $type, $columns, $indexes, $foreignKeys, $comment ?: null);
        }

        return new SchemaSnapshot(
            driver: $connection->getDriverName(),
            connection: $connection->getName(),
            tables: $tables,
            database: $connection->getDatabaseName(),
        );
    }
}
