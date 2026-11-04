<?php

namespace Abdalla\ReportGenerator\Schema\Inspectors;

use Abdalla\ReportGenerator\Schema\ColumnSchema;
use Abdalla\ReportGenerator\Schema\ForeignKeySchema;
use Abdalla\ReportGenerator\Schema\IndexSchema;
use Abdalla\ReportGenerator\Schema\TableSchema;

/**
 * Assembles TableSchema objects from the flat, one-row-per-column result
 * sets the bulk catalog queries return.
 */
trait BuildsTables
{
    /**
     * @param  iterable<object{name: string, type: string, comment: ?string, row_estimate: mixed}>  $tables
     * @param  array<string, list<ColumnSchema>>  $columns
     * @param  array<string, list<IndexSchema>>  $indexes
     * @param  array<string, list<ForeignKeySchema>>  $foreignKeys
     * @return list<TableSchema>
     */
    protected function assemble(iterable $tables, array $columns, array $indexes, array $foreignKeys): array
    {
        $result = [];

        foreach ($tables as $table) {
            $result[] = new TableSchema(
                name: $table->name,
                type: $table->type,
                columns: $columns[$table->name] ?? [],
                indexes: $indexes[$table->name] ?? [],
                foreignKeys: $foreignKeys[$table->name] ?? [],
                comment: $this->nullIfEmpty($table->comment ?? null),
                rowEstimate: $table->row_estimate === null ? null : max(0, (int) $table->row_estimate),
            );
        }

        return $result;
    }

    /**
     * Groups ordered rows (table, name, column, ...) into one object per
     * (table, name), collecting the columns in order.
     *
     * @param  iterable<object>  $rows
     * @return array<string, array<string, array{row: object, columns: list<string>, foreign_columns: list<string>}>>
     */
    protected function groupByName(iterable $rows): array
    {
        $grouped = [];

        foreach ($rows as $row) {
            $entry = &$grouped[$row->table_name][$row->name];
            $entry['row'] ??= $row;
            $entry['columns'][] = $row->column_name;
            if (isset($row->foreign_column)) {
                $entry['foreign_columns'][] = $row->foreign_column;
            }
            unset($entry);
        }

        return $grouped;
    }

    protected function nullIfEmpty(?string $value): ?string
    {
        $value = $value === null ? null : trim($value);

        return $value === '' ? null : $value;
    }
}
