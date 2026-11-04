<?php

use Abdalla\ReportGenerator\Enums\FieldRole;
use Abdalla\ReportGenerator\Schema\ColumnSchema;
use Abdalla\ReportGenerator\Schema\FieldClassifier;
use Abdalla\ReportGenerator\Schema\IndexSchema;
use Abdalla\ReportGenerator\Schema\TableSchema;

function classifyVisits(): array
{
    $table = new TableSchema('plan_visits', columns: [
        new ColumnSchema('id', 'bigint unsigned', 'bigint', false, autoIncrement: true),
        new ColumnSchema('doctor_id', 'bigint unsigned', 'bigint', false),
        new ColumnSchema('visit_date', 'date', 'date', false),
        new ColumnSchema('quantity', 'int', 'int', false),
        new ColumnSchema('class_level', 'int', 'int', false),
        new ColumnSchema('amount', 'decimal(12,2)', 'decimal', false),
        new ColumnSchema('latitude', 'decimal(10,7)', 'decimal', true),
        new ColumnSchema('achievement_ratio', 'decimal(5,4)', 'decimal', true),
        new ColumnSchema('api_token', 'varchar(80)', 'varchar', true),
        new ColumnSchema('shape', 'geometry', 'geometry', true),
        new ColumnSchema('extra', 'json', 'json', true),
        new ColumnSchema('status', 'varchar(16)', 'varchar', false, comment: 'Visit status'),
        new ColumnSchema('created_at', 'timestamp', 'timestamp', true),
        new ColumnSchema('deleted_at', 'timestamp', 'timestamp', true),
    ], indexes: [new IndexSchema('primary', ['id'], true, true), new IndexSchema('v_date', ['visit_date'], false, false)]);

    $draft = (new FieldClassifier(['*token*']))->dataset($table, 'plan_visits');

    return [$draft, collect($draft->fields)->keyBy('column')];
}

it('labels the dataset and finds its keys and default date', function () {
    [$draft] = classifyVisits();

    expect($draft->label)->toBe('Plan Visits')
        ->and($draft->primaryKey)->toBe('id')
        ->and($draft->defaultTimeField)->toBe('visit_date')
        ->and($draft->softDeleteColumn)->toBe('deleted_at');
});

it('guesses roles from type and name', function () {
    [, $f] = classifyVisits();

    expect($f['id']->role)->toBe(FieldRole::Key)
        ->and($f['doctor_id']->role)->toBe(FieldRole::Key)
        ->and($f['doctor_id']->label)->toBe('Doctor')
        ->and($f['visit_date']->role)->toBe(FieldRole::Time)
        ->and($f['quantity']->role)->toBe(FieldRole::Measure)
        ->and($f['quantity']->format)->toBe('#,##0')
        ->and($f['class_level']->role)->toBe(FieldRole::Dimension)
        ->and($f['amount']->role)->toBe(FieldRole::Measure)
        ->and($f['amount']->format)->toBe('#,##0.00')
        ->and($f['latitude']->role)->toBe(FieldRole::Dimension)
        ->and($f['achievement_ratio']->format)->toBe('0.00%')
        ->and($f['status']->label)->toBe('Visit status')
        ->and($f['visit_date']->indexed)->toBeTrue()
        ->and($f['amount']->indexed)->toBeFalse();
});

it('hides keys, secrets, unsupported types, json and the soft-delete column', function () {
    [, $f] = classifyVisits();

    expect($f['id']->hiddenReason)->toBe('key')
        ->and($f['api_token']->hiddenReason)->toBe('sensitive')
        ->and($f['api_token']->sensitive)->toBeTrue()
        ->and($f['shape']->hiddenReason)->toBe('unsupported')
        ->and($f['extra']->hiddenReason)->toBe('json')
        ->and($f['deleted_at']->hiddenReason)->toBe('soft_delete')
        ->and($f['amount']->hidden)->toBeFalse();
});

it('matches sensitive patterns case-insensitively', function () {
    $classifier = new FieldClassifier(['*password*', 'remember_*']);

    expect($classifier->isSensitive('User_PASSWORD'))->toBeTrue()
        ->and($classifier->isSensitive('remember_token'))->toBeTrue()
        ->and($classifier->isSensitive('passport_no'))->toBeFalse();
});
