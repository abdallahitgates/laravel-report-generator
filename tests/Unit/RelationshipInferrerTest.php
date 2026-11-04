<?php

use Abdalla\ReportGenerator\Enums\Cardinality;
use Abdalla\ReportGenerator\Schema\ColumnSchema;
use Abdalla\ReportGenerator\Schema\ForeignKeySchema;
use Abdalla\ReportGenerator\Schema\IndexSchema;
use Abdalla\ReportGenerator\Schema\Relationships\InferredRelationship;
use Abdalla\ReportGenerator\Schema\Relationships\RelationshipInferrer;
use Abdalla\ReportGenerator\Schema\SchemaSnapshot;
use Abdalla\ReportGenerator\Schema\TableSchema;

function idCol(string $name = 'id'): ColumnSchema
{
    return new ColumnSchema($name, 'bigint unsigned', 'bigint', false);
}

function tbl(string $name, array $columns, array $fks = [], array $extraIndexes = []): TableSchema
{
    return new TableSchema($name, columns: [idCol(), ...array_map(fn ($c) => is_string($c) ? idCol($c) : $c, $columns)],
        indexes: [new IndexSchema('primary', ['id'], true, true), ...$extraIndexes], foreignKeys: $fks);
}

function edges(SchemaSnapshot $snapshot, array $eloquent = []): array
{
    return collect((new RelationshipInferrer())->infer($snapshot, $eloquent))->keyBy(fn ($e) => $e->edgeKey())->all();
}

it('turns single-column foreign keys into confirmed-strength edges', function () {
    $edges = edges(new SchemaSnapshot('mysql', 'c', [
        tbl('specialities', []),
        tbl('doctors', ['speciality_id'], [new ForeignKeySchema('fk', ['speciality_id'], 'specialities', ['id'])]),
    ]));

    $edge = $edges['doctors.speciality_id>specialities.id'];
    expect($edge->origin)->toBe(InferredRelationship::ORIGIN_FOREIGN_KEY)
        ->and($edge->confidence)->toBe(1.0)
        ->and($edge->cardinality)->toBe(Cardinality::ManyToOne);
});

it('guesses links from *_id names, plural and singular tables', function () {
    $edges = edges(new SchemaSnapshot('mysql', 'c', [
        tbl('lines', ['parent_id']),
        tbl('doctors', []),
        tbl('brick', []),
        tbl('users', []),
        tbl('visits', ['doctor_id', 'line_id', 'brick_id', 'from_user_id', 'unknown_id']),
    ]));

    expect($edges)->toHaveKeys([
        'visits.doctor_id>doctors.id', 'visits.line_id>lines.id', 'visits.brick_id>brick.id',
        'visits.from_user_id>users.id', 'lines.parent_id>lines.id',
    ])
        ->and($edges['visits.doctor_id>doctors.id']->confidence)->toBe(0.7)
        ->and($edges['visits.from_user_id>users.id']->confidence)->toBe(0.5)
        ->and($edges['lines.parent_id>lines.id']->confidence)->toBe(0.6)
        ->and(collect($edges)->keys()->filter(fn ($k) => str_contains($k, 'unknown_id')))->toBeEmpty();
});

it('skips polymorphic pairs and type mismatches', function () {
    $edges = edges(new SchemaSnapshot('mysql', 'c', [
        tbl('commentables', []),
        tbl('codes', []),
        tbl('comments', ['commentable_id', new ColumnSchema('commentable_type', 'varchar(255)', 'varchar', false),
            new ColumnSchema('code_id', 'varchar(10)', 'varchar', false)]),
    ]));

    expect($edges)->toBeEmpty();
});

it('marks unique references as one-to-one', function () {
    $edges = edges(new SchemaSnapshot('mysql', 'c', [
        tbl('users', []),
        tbl('user_profiles', ['user_id'], [], [new IndexSchema('u', ['user_id'], true, false)]),
    ]));

    expect($edges['user_profiles.user_id>users.id']->cardinality)->toBe(Cardinality::OneToOne);
});

it('keeps the most confident source for the same link', function () {
    $snapshot = new SchemaSnapshot('mysql', 'c', [tbl('users', []), tbl('visits', ['user_id'])]);
    $eloquent = [new InferredRelationship('visits', 'user_id', 'users', 'id', Cardinality::ManyToOne, InferredRelationship::ORIGIN_ELOQUENT, 0.9)];

    expect(edges($snapshot, $eloquent)['visits.user_id>users.id']->origin)->toBe(InferredRelationship::ORIGIN_ELOQUENT);
});

it('drops eloquent edges that point at columns the database does not have', function () {
    $snapshot = new SchemaSnapshot('mysql', 'c', [tbl('users', []), tbl('visits', [])]);
    $eloquent = [new InferredRelationship('visits', 'rep_id', 'users', 'id', Cardinality::ManyToOne, InferredRelationship::ORIGIN_ELOQUENT, 0.9)];

    expect(edges($snapshot, $eloquent))->toBeEmpty();
});

it('does not add naming guesses for a column whose target is already known', function () {
    $edges = edges(new SchemaSnapshot('mysql', 'c', [
        tbl('pharmacy_types', []),
        tbl('pharmacy_type_visits', []),
        tbl('visits', ['pharmacy_type_id'], [new ForeignKeySchema('fk', ['pharmacy_type_id'], 'pharmacy_type_visits', ['id'])]),
    ]));

    expect(array_keys($edges))->toBe(['visits.pharmacy_type_id>pharmacy_type_visits.id']);
});
