<?php

use Abdalla\ReportGenerator\Enums\DataType;
use Abdalla\ReportGenerator\Schema\ColumnSchema;
use Abdalla\ReportGenerator\Schema\TypeMapper;

function col(string $type, ?string $name = null): ColumnSchema
{
    return new ColumnSchema('c', $type, $name ?? strtok($type, '( '), false);
}

it('maps MySQL and PostgreSQL types', function (string $type, string $typeName, ?DataType $expected) {
    expect(TypeMapper::map(col($type, $typeName)))->toBe($expected);
})->with([
    ['int unsigned', 'int', DataType::Integer],
    ['bigint', 'int8', DataType::Integer],
    ['tinyint(1)', 'tinyint', DataType::Boolean],
    ['tinyint(4)', 'tinyint', DataType::Integer],
    ['decimal(12,2)', 'decimal', DataType::Decimal],
    ['numeric(12,2)', 'numeric', DataType::Decimal],
    ['double precision', 'float8', DataType::Decimal],
    ['boolean', 'bool', DataType::Boolean],
    ['date', 'date', DataType::Date],
    ['timestamp(0) without time zone', 'timestamp', DataType::DateTime],
    ['timestamp with time zone', 'timestamptz', DataType::DateTime],
    ['varchar(255)', 'varchar', DataType::String],
    ['character varying(255)', 'varchar', DataType::String],
    ["enum('a','b')", 'enum', DataType::String],
    ['uuid', 'uuid', DataType::String],
    ['longtext', 'longtext', DataType::Text],
    ['jsonb', 'jsonb', DataType::Json],
    ['integer[]', '_int4', null],
    ['blob', 'blob', null],
    ['geometry', 'geometry', null],
]);

it('only joins compatible types', function () {
    expect(TypeMapper::joinable(DataType::Integer, DataType::Decimal))->toBeTrue()
        ->and(TypeMapper::joinable(DataType::String, DataType::Text))->toBeTrue()
        ->and(TypeMapper::joinable(DataType::Integer, DataType::String))->toBeFalse()
        ->and(TypeMapper::joinable(null, DataType::String))->toBeFalse();
});
