<?php

namespace Abdalla\ReportGenerator\Schema;

use Abdalla\ReportGenerator\Enums\DataType;

/**
 * Maps MySQL / PostgreSQL / SQLite column types to the package's DataType.
 * Returns null for types reports cannot use (binary, arrays, geometry, ...).
 */
final class TypeMapper
{
    private const MAP = [
        // integers
        'tinyint' => DataType::Integer, 'smallint' => DataType::Integer, 'mediumint' => DataType::Integer,
        'int' => DataType::Integer, 'integer' => DataType::Integer, 'bigint' => DataType::Integer,
        'int2' => DataType::Integer, 'int4' => DataType::Integer, 'int8' => DataType::Integer,
        'serial' => DataType::Integer, 'bigserial' => DataType::Integer, 'smallserial' => DataType::Integer,
        'year' => DataType::Integer,
        // decimals
        'decimal' => DataType::Decimal, 'numeric' => DataType::Decimal, 'float' => DataType::Decimal,
        'double' => DataType::Decimal, 'real' => DataType::Decimal, 'float4' => DataType::Decimal,
        'float8' => DataType::Decimal, 'money' => DataType::Decimal, 'double precision' => DataType::Decimal,
        // booleans
        'bool' => DataType::Boolean, 'boolean' => DataType::Boolean, 'bit' => DataType::Boolean,
        // temporal
        'date' => DataType::Date,
        'datetime' => DataType::DateTime, 'timestamp' => DataType::DateTime, 'timestamptz' => DataType::DateTime,
        'time' => DataType::Time, 'timetz' => DataType::Time,
        // documents
        'json' => DataType::Json, 'jsonb' => DataType::Json,
        // long text
        'text' => DataType::Text, 'tinytext' => DataType::Text, 'mediumtext' => DataType::Text, 'longtext' => DataType::Text,
        // short text
        'varchar' => DataType::String, 'char' => DataType::String, 'bpchar' => DataType::String,
        'character varying' => DataType::String, 'character' => DataType::String, 'enum' => DataType::String,
        'set' => DataType::String, 'uuid' => DataType::String, 'citext' => DataType::String, 'name' => DataType::String,
        'inet' => DataType::String, 'string' => DataType::String,
    ];

    public static function map(ColumnSchema $column): ?DataType
    {
        $name = $column->typeName;

        // MySQL convention: tinyint(1) is a boolean flag.
        if ($name === 'tinyint' && str_starts_with($column->type, 'tinyint(1)')) {
            return DataType::Boolean;
        }

        // PostgreSQL arrays (_int4, int4[]) and anything unknown are unsupported.
        if (str_starts_with($name, '_') || str_ends_with($column->type, '[]')) {
            return null;
        }

        return self::MAP[$name] ?? null;
    }

    /** Whether values of the two types can be compared in a join. */
    public static function joinable(?DataType $a, ?DataType $b): bool
    {
        if ($a === null || $b === null) {
            return false;
        }

        return self::family($a) === self::family($b);
    }

    private static function family(DataType $type): string
    {
        return match ($type) {
            DataType::Integer, DataType::Decimal => 'number',
            DataType::String, DataType::Text => 'text',
            DataType::Date, DataType::DateTime => 'time',
            default => $type->value,
        };
    }
}
