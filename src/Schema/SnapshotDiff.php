<?php

namespace Abdalla\ReportGenerator\Schema;

/**
 * What changed in the database structure between two scans.
 */
final class SnapshotDiff
{
    /**
     * @return array{
     *   tables_added: list<string>,
     *   tables_removed: list<string>,
     *   columns_added: array<string, list<string>>,
     *   columns_removed: array<string, list<string>>,
     *   columns_changed: array<string, list<array{column: string, from: string, to: string}>>
     * }
     */
    public static function between(?SchemaSnapshot $old, SchemaSnapshot $new): array
    {
        $oldTables = $old?->tables ?? [];
        $diff = [
            'tables_added' => array_values(array_diff(array_keys($new->tables), array_keys($oldTables))),
            'tables_removed' => array_values(array_diff(array_keys($oldTables), array_keys($new->tables))),
            'columns_added' => [],
            'columns_removed' => [],
            'columns_changed' => [],
        ];

        foreach (array_intersect_key($new->tables, $oldTables) as $name => $table) {
            $before = $oldTables[$name]->columns;

            if ($added = array_values(array_diff(array_keys($table->columns), array_keys($before)))) {
                $diff['columns_added'][$name] = $added;
            }
            if ($removed = array_values(array_diff(array_keys($before), array_keys($table->columns)))) {
                $diff['columns_removed'][$name] = $removed;
            }
            foreach (array_intersect_key($table->columns, $before) as $column => $schema) {
                if ($schema->type !== $before[$column]->type) {
                    $diff['columns_changed'][$name][] = ['column' => $column, 'from' => $before[$column]->type, 'to' => $schema->type];
                }
            }
        }

        return $diff;
    }

    public static function isEmpty(array $diff): bool
    {
        return ! array_filter($diff);
    }
}
