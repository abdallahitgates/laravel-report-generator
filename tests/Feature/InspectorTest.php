<?php

use Abdalla\ReportGenerator\Schema\Inspectors\GenericInspector;
use Abdalla\ReportGenerator\Schema\Inspectors\InspectorFactory;
use Abdalla\ReportGenerator\Schema\Inspectors\MySqlInspector;
use Abdalla\ReportGenerator\Schema\Inspectors\PostgresInspector;
use Abdalla\ReportGenerator\Schema\TableSchema;
use Abdalla\ReportGenerator\Tests\Support\FixtureSchema;
use Illuminate\Support\Facades\DB;

afterEach(function () {
    foreach (dataConnections() as $connection) {
        FixtureSchema::drop($connection);
    }
});

it('picks the bulk inspector for the driver', function (string $connection) {
    $expected = match (DB::connection($connection)->getDriverName()) {
        'mysql' => MySqlInspector::class,
        'pgsql' => PostgresInspector::class,
        default => GenericInspector::class,
    };

    expect(app(InspectorFactory::class)->for(DB::connection($connection)))->toBeInstanceOf($expected);
})->with(dataConnections());

it('reads tables, views, columns, keys and indexes', function (string $connection) {
    FixtureSchema::create($connection);
    $db = DB::connection($connection);

    $snapshot = app(InspectorFactory::class)->for($db)->inspect($db);

    expect($snapshot->table('lines'))->not->toBeNull()
        ->and($snapshot->table('visit_totals')->type)->toBe(TableSchema::VIEW)
        ->and($snapshot->table('visit_totals')->hasColumn('visits_count'))->toBeTrue();

    $visits = $snapshot->table('visits');
    expect(array_keys($visits->columns))->toBe([
        'id', 'user_id', 'doctor_id', 'visit_date', 'duration_min', 'status', 'amount', 'notes', 'payload', 'created_at', 'updated_at',
    ])
        ->and($visits->primaryKey())->toBe(['id'])
        ->and($visits->isIndexed('visit_date'))->toBeTrue()
        ->and($visits->isIndexed('doctor_id'))->toBeFalse()
        ->and($visits->column('notes')->nullable)->toBeTrue()
        ->and($visits->column('status')->nullable)->toBeFalse();

    $doctors = $snapshot->table('doctors');
    expect($doctors->foreignKeys)->toHaveCount(1)
        ->and($doctors->foreignKeys[0]->columns)->toBe(['speciality_id'])
        ->and($doctors->foreignKeys[0]->foreignTable)->toBe('specialities')
        ->and($doctors->foreignKeys[0]->foreignColumns)->toBe(['id']);

    expect($snapshot->table('user_profiles')->isUnique('user_id'))->toBeTrue()
        ->and($snapshot->table('doctors')->isUnique('line_id'))->toBeFalse();

    if ($db->getDriverName() !== 'sqlite') {
        // SQLite has no column comments and no row estimates.
        expect($visits->column('duration_min')->comment)->toBe('Visit duration (minutes)')
            ->and($visits->column('id')->autoIncrement)->toBeTrue();
    }
})->with(dataConnections());

it('produces the same hash for the same structure', function (string $connection) {
    FixtureSchema::create($connection);
    $db = DB::connection($connection);
    $inspector = app(InspectorFactory::class)->for($db);

    $first = $inspector->inspect($db);
    expect($inspector->inspect($db)->hash())->toBe($first->hash());

    $db->getSchemaBuilder()->table('visits', fn ($t) => $t->string('channel')->nullable());
    expect($inspector->inspect($db)->hash())->not->toBe($first->hash());
})->with(dataConnections());

it('round-trips a snapshot through an array', function () {
    FixtureSchema::create('testing');
    $db = DB::connection('testing');
    $snapshot = app(InspectorFactory::class)->for($db)->inspect($db);

    $copy = \Abdalla\ReportGenerator\Schema\SchemaSnapshot::fromArray(json_decode(json_encode($snapshot->toArray()), true));

    expect($copy->hash())->toBe($snapshot->hash())
        ->and(array_keys($copy->tables))->toBe(array_keys($snapshot->tables));
});
