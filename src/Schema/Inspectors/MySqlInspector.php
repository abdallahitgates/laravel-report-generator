<?php

namespace Abdalla\ReportGenerator\Schema\Inspectors;

use Abdalla\ReportGenerator\Schema\ColumnSchema;
use Abdalla\ReportGenerator\Schema\ForeignKeySchema;
use Abdalla\ReportGenerator\Schema\IndexSchema;
use Abdalla\ReportGenerator\Schema\SchemaSnapshot;
use Abdalla\ReportGenerator\Schema\TableSchema;
use Illuminate\Database\Connection;

/**
 * Reads the whole schema in four information_schema queries, however many
 * tables there are (Laravel's per-table methods would need three per table).
 * Works for MySQL 5.7+, MySQL 8 and MariaDB. Every column is aliased in
 * lowercase because MySQL 8 returns information_schema names in uppercase.
 */
final class MySqlInspector implements SchemaInspector
{
    use BuildsTables;

    public function inspect(Connection $connection): SchemaSnapshot
    {
        $database = $connection->getDatabaseName();

        $tables = $connection->select(
            "select table_name as name,
                    case when table_type = 'VIEW' then 'view' else 'table' end as type,
                    table_comment as comment,
                    table_rows as row_estimate
             from information_schema.tables
             where table_schema = ? and table_type in ('BASE TABLE', 'VIEW', 'SYSTEM VERSIONED')
             order by table_name",
            [$database]
        );

        $columns = [];
        foreach ($connection->select(
            'select table_name as table_name, column_name as name, data_type as type_name, column_type as type,
                    is_nullable as nullable, column_comment as comment, extra as extra
             from information_schema.columns
             where table_schema = ?
             order by table_name, ordinal_position',
            [$database]
        ) as $row) {
            $columns[$row->table_name][] = new ColumnSchema(
                name: $row->name,
                type: strtolower($row->type),
                typeName: strtolower($row->type_name),
                nullable: $row->nullable === 'YES',
                comment: $this->nullIfEmpty($row->comment),
                autoIncrement: str_contains(strtolower((string) $row->extra), 'auto_increment'),
            );
        }

        $indexes = [];
        $indexRows = $connection->select(
            'select table_name as table_name, index_name as name, column_name as column_name, non_unique as non_unique
             from information_schema.statistics
             where table_schema = ?
             order by table_name, index_name, seq_in_index',
            [$database]
        );
        foreach ($this->groupByName($indexRows) as $table => $byName) {
            foreach ($byName as $name => $entry) {
                $indexes[$table][] = new IndexSchema(
                    name: strtolower($name) === 'primary' ? 'primary' : $name,
                    columns: $entry['columns'],
                    unique: ! (int) $entry['row']->non_unique,
                    primary: $name === 'PRIMARY',
                );
            }
        }

        $foreignKeys = [];
        $fkRows = $connection->select(
            'select table_name as table_name, constraint_name as name, column_name as column_name,
                    referenced_table_name as foreign_table, referenced_column_name as foreign_column
             from information_schema.key_column_usage
             where table_schema = ? and referenced_table_schema = ? and referenced_table_name is not null
             order by table_name, constraint_name, ordinal_position',
            [$database, $database]
        );
        foreach ($this->groupByName($fkRows) as $table => $byName) {
            foreach ($byName as $name => $entry) {
                $foreignKeys[$table][] = new ForeignKeySchema(
                    $name, $entry['columns'], $entry['row']->foreign_table, $entry['foreign_columns']
                );
            }
        }

        return new SchemaSnapshot(
            driver: $connection->getDriverName(),
            connection: $connection->getName(),
            tables: $this->assemble($tables, $columns, $indexes, $foreignKeys),
            database: $database,
        );
    }
}
