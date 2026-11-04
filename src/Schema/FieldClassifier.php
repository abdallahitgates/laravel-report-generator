<?php

namespace Abdalla\ReportGenerator\Schema;

use Abdalla\ReportGenerator\Enums\Aggregation;
use Abdalla\ReportGenerator\Enums\DataType;
use Abdalla\ReportGenerator\Enums\FieldRole;
use Illuminate\Support\Str;

/**
 * First guess at how each table and column should appear in the builder:
 * label, role, default format and whether it starts hidden. The admin
 * reviews and corrects these in Model Studio; a rescan never overwrites
 * those edits.
 */
final class FieldClassifier
{
    /** Integer columns with these words are amounts you add up; other integers are codes or categories. */
    private const MEASURE_WORDS = [
        'qty', 'quantity', 'quantities', 'amount', 'total', 'count', 'value', 'price', 'cost', 'units', 'unit',
        'sales', 'target', 'duration', 'minutes', 'hours', 'days', 'seconds', 'score', 'points', 'discount',
        'budget', 'balance', 'stock', 'weight', 'percentage', 'percent', 'ratio', 'rate', 'revenue', 'paid',
        'tax', 'fees', 'fee', 'salary', 'bonus', 'incentive', 'visits', 'calls', 'frequency', 'achievement',
    ];

    /** Decimal columns with these words describe something rather than measure it. */
    private const DIMENSION_WORDS = ['lat', 'lng', 'lon', 'latitude', 'longitude', 'code', 'version', 'phone', 'zip'];

    /** @param  list<string>  $sensitivePatterns */
    public function __construct(private readonly array $sensitivePatterns = [])
    {
    }

    public function dataset(TableSchema $table, string $key, ?string $sourceName = null): DatasetDraft
    {
        $primaryKey = $table->primaryKey();
        $foreignKeyColumns = [];
        foreach ($table->foreignKeys as $fk) {
            array_push($foreignKeyColumns, ...$fk->columns);
        }

        $polymorphic = [];
        foreach ($table->columns as $name => $column) {
            if (preg_match('/^(.+)_type$/', $name, $m) && $table->hasColumn($m[1].'_id')) {
                $polymorphic[] = $m[1];
            }
        }

        $fields = [];
        $softDelete = null;
        $order = 0;
        $usedKeys = [];

        foreach ($table->columns as $column) {
            $field = $this->field($column, $table, $primaryKey, $foreignKeyColumns, $order++, $usedKeys);
            $usedKeys[$field->key] = true;
            $fields[] = $field;

            if ($field->hiddenReason === 'soft_delete') {
                $softDelete = $column->name;
            }
        }

        return new DatasetDraft(
            key: $key,
            sourceType: $table->type,
            sourceName: $sourceName ?? $table->name,
            label: $this->tableLabel($table),
            description: $table->comment !== null && mb_strlen($table->comment) > 60 ? $table->comment : null,
            primaryKey: $primaryKey ? implode(',', $primaryKey) : null,
            defaultTimeField: $this->defaultTimeField($fields),
            rowEstimate: $table->rowEstimate,
            fields: $fields,
            softDeleteColumn: $softDelete,
            polymorphic: $polymorphic,
        );
    }

    /**
     * @param  list<string>  $primaryKey
     * @param  list<string>  $foreignKeyColumns
     * @param  array<string, true>  $usedKeys
     */
    private function field(ColumnSchema $column, TableSchema $table, array $primaryKey, array $foreignKeyColumns, int $order, array $usedKeys): FieldDraft
    {
        $name = $column->name;
        $lower = strtolower($name);
        $type = TypeMapper::map($column);
        $sensitive = $this->isSensitive($lower);
        $isKey = in_array($name, $primaryKey, true)
            || in_array($name, $foreignKeyColumns, true)
            || $lower === 'id'
            || str_ends_with($lower, '_id')
            || str_ends_with($lower, '_type') && $table->hasColumn(substr($name, 0, -5).'_id');

        [$role, $hiddenReason] = match (true) {
            $type === null => [FieldRole::Dimension, 'unsupported'],
            $lower === 'deleted_at' => [FieldRole::Time, 'soft_delete'],
            $isKey => [FieldRole::Key, 'key'],
            $type->isTemporal() => [FieldRole::Time, null],
            $type === DataType::Json => [FieldRole::Dimension, 'json'],
            $type === DataType::Decimal => [$this->hasWord($lower, self::DIMENSION_WORDS) ? FieldRole::Dimension : FieldRole::Measure, null],
            $type === DataType::Integer => [$this->hasWord($lower, self::MEASURE_WORDS) ? FieldRole::Measure : FieldRole::Dimension, null],
            default => [FieldRole::Dimension, null],
        };

        if ($sensitive) {
            $hiddenReason = 'sensitive';
        }

        $type ??= DataType::String;

        return new FieldDraft(
            key: $this->uniqueKey($name, $usedKeys),
            column: $name,
            label: $this->columnLabel($column, $role),
            type: $type,
            role: $role,
            aggregations: $role === FieldRole::Measure ? Aggregation::forType($type) : [],
            format: $this->defaultFormat($type, $role, $lower),
            hidden: $hiddenReason !== null,
            sensitive: $sensitive,
            indexed: $table->isIndexed($name),
            sortOrder: $order,
            hiddenReason: $hiddenReason,
        );
    }

    public function isSensitive(string $column): bool
    {
        foreach ($this->sensitivePatterns as $pattern) {
            if (Str::is(strtolower($pattern), strtolower($column))) {
                return true;
            }
        }

        return false;
    }

    public function tableLabel(TableSchema $table): string
    {
        if ($table->comment !== null && mb_strlen($table->comment) <= 60 && ! str_contains($table->comment, "\n")) {
            return $table->comment;
        }

        return Str::headline($table->name);
    }

    public function columnLabel(ColumnSchema $column, FieldRole $role): string
    {
        if ($column->comment !== null && mb_strlen($column->comment) <= 60 && ! str_contains($column->comment, "\n")) {
            return $column->comment;
        }

        $name = strtolower($column->name);

        if ($name === 'id') {
            return 'ID';
        }

        // doctor_id -> "Doctor"; the label names the thing, the key stays a key.
        if ($role === FieldRole::Key && str_ends_with($name, '_id')) {
            $name = substr($name, 0, -3);
        }

        return Str::headline($name);
    }

    /** @param  list<FieldDraft>  $fields */
    private function defaultTimeField(array $fields): ?string
    {
        $candidates = array_values(array_filter(
            $fields,
            fn (FieldDraft $f) => $f->role === FieldRole::Time && $f->hiddenReason === null,
        ));

        $score = function (FieldDraft $f): int {
            $name = strtolower($f->column);

            return match (true) {
                $name === 'date' || str_ends_with($name, '_date') => 3,
                ! in_array($name, ['created_at', 'updated_at'], true) => 2,
                $name === 'created_at' => 1,
                default => 0,
            };
        };

        usort($candidates, fn (FieldDraft $a, FieldDraft $b) => [$score($b), $a->sortOrder] <=> [$score($a), $b->sortOrder]);

        return $candidates[0]->key ?? null;
    }

    private function defaultFormat(DataType $type, FieldRole $role, string $name): ?string
    {
        if ($role !== FieldRole::Measure) {
            return null;
        }

        if ($this->hasWord($name, ['percentage', 'percent', 'ratio', 'rate'])) {
            return '0.00%';
        }

        return $type === DataType::Decimal ? '#,##0.00' : '#,##0';
    }

    /** @param  list<string>  $words */
    private function hasWord(string $name, array $words): bool
    {
        return (bool) array_intersect(preg_split('/[_\s]+/', $name), $words);
    }

    /** @param  array<string, true>  $used */
    private function uniqueKey(string $column, array $used): string
    {
        $key = preg_replace('/[^a-z0-9_]+/i', '_', $column);
        $key = preg_match('/^[a-z_]/i', $key) ? $key : 'f_'.$key;

        $candidate = $key;
        for ($i = 2; isset($used[$candidate]); $i++) {
            $candidate = $key.'_'.$i;
        }

        return $candidate;
    }
}
