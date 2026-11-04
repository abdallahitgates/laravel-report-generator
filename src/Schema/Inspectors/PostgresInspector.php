<?php

namespace Abdalla\ReportGenerator\Schema\Inspectors;

use Abdalla\ReportGenerator\Schema\ColumnSchema;
use Abdalla\ReportGenerator\Schema\ForeignKeySchema;
use Abdalla\ReportGenerator\Schema\IndexSchema;
use Abdalla\ReportGenerator\Schema\SchemaSnapshot;
use Illuminate\Database\Connection;

/**
 * Reads one PostgreSQL schema in four pg_catalog queries.
 */
final class PostgresInspector implements SchemaInspector
{
    use BuildsTables;

    public function __construct(private readonly ?string $schema = null)
    {
    }

    public function inspect(Connection $connection): SchemaSnapshot
    {
        $schema = $this->schema ?? self::defaultSchema($connection);

        // reltuples is -1 until the table is first analyzed (PG 14+): unknown, not zero.
        $tables = $connection->select(
            "select c.relname as name,
                    case when c.relkind in ('v', 'm') then 'view' else 'table' end as type,
                    obj_description(c.oid, 'pg_class') as comment,
                    case when c.reltuples < 0 then null else c.reltuples::bigint end as row_estimate
             from pg_class c
             join pg_namespace n on n.oid = c.relnamespace
             where n.nspname = ? and c.relkind in ('r', 'p', 'v', 'm')
               and not c.relispartition
             order by c.relname",
            [$schema]
        );

        $columns = [];
        foreach ($connection->select(
            "select c.relname as table_name, a.attname as name, t.typname as type_name,
                    format_type(a.atttypid, a.atttypmod) as type, not a.attnotnull as nullable,
                    col_description(c.oid, a.attnum) as comment,
                    (a.attidentity <> '' or coalesce(pg_get_expr(d.adbin, d.adrelid), '') like 'nextval(%') as auto_increment
             from pg_attribute a
             join pg_class c on c.oid = a.attrelid
             join pg_namespace n on n.oid = c.relnamespace
             join pg_type t on t.oid = a.atttypid
             left join pg_attrdef d on d.adrelid = c.oid and d.adnum = a.attnum
             where n.nspname = ? and c.relkind in ('r', 'p', 'v', 'm') and a.attnum > 0 and not a.attisdropped
             order by c.relname, a.attnum",
            [$schema]
        ) as $row) {
            $columns[$row->table_name][] = new ColumnSchema(
                name: $row->name,
                type: strtolower($row->type),
                typeName: strtolower($row->type_name),
                nullable: (bool) $row->nullable,
                comment: $this->nullIfEmpty($row->comment),
                autoIncrement: (bool) $row->auto_increment,
            );
        }

        $indexes = [];
        $indexRows = $connection->select(
            'select tc.relname as table_name, ic.relname as name, a.attname as column_name,
                    i.indisunique as is_unique, i.indisprimary as is_primary
             from pg_index i
             join pg_class tc on tc.oid = i.indrelid
             join pg_namespace n on n.oid = tc.relnamespace
             join pg_class ic on ic.oid = i.indexrelid
             cross join lateral unnest(i.indkey) with ordinality as k(attnum, ord)
             join pg_attribute a on a.attrelid = tc.oid and a.attnum = k.attnum
             where n.nspname = ?
             order by tc.relname, ic.relname, k.ord',
            [$schema]
        );
        foreach ($this->groupByName($indexRows) as $table => $byName) {
            foreach ($byName as $name => $entry) {
                $indexes[$table][] = new IndexSchema(
                    $name, $entry['columns'], (bool) $entry['row']->is_unique, (bool) $entry['row']->is_primary
                );
            }
        }

        $foreignKeys = [];
        $fkRows = $connection->select(
            "select tc.relname as table_name, con.conname as name, la.attname as column_name,
                    fc.relname as foreign_table, fa.attname as foreign_column
             from pg_constraint con
             join pg_class tc on tc.oid = con.conrelid
             join pg_namespace n on n.oid = tc.relnamespace
             join pg_class fc on fc.oid = con.confrelid
             join pg_namespace fn on fn.oid = fc.relnamespace
             cross join lateral unnest(con.conkey, con.confkey) with ordinality as k(lnum, fnum, ord)
             join pg_attribute la on la.attrelid = tc.oid and la.attnum = k.lnum
             join pg_attribute fa on fa.attrelid = fc.oid and fa.attnum = k.fnum
             where con.contype = 'f' and n.nspname = ? and fn.nspname = ?
             order by tc.relname, con.conname, k.ord",
            [$schema, $schema]
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
            database: $connection->getDatabaseName(),
            schema: $schema,
        );
    }

    /** First usable entry of the connection's search_path, else "public". */
    public static function defaultSchema(Connection $connection): string
    {
        $path = $connection->getConfig('search_path') ?? $connection->getConfig('schema') ?? 'public';
        $entries = is_array($path) ? $path : explode(',', (string) $path);

        foreach ($entries as $entry) {
            $entry = trim($entry, " \t\"'");
            if ($entry !== '' && $entry !== '$user') {
                return $entry;
            }
        }

        return 'public';
    }
}
