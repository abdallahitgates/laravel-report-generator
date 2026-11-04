<?php

use Abdalla\ReportGenerator\Enums\FieldRole;
use Abdalla\ReportGenerator\Models\Dataset;
use Abdalla\ReportGenerator\Models\Field;
use Abdalla\ReportGenerator\Models\Relationship;
use Abdalla\ReportGenerator\Models\SchemaSnapshotRecord;
use Abdalla\ReportGenerator\Schema\SchemaScanner;
use Abdalla\ReportGenerator\Tests\Support\FixtureSchema;
use Illuminate\Support\Facades\Schema;

beforeEach(function () {
    $this->artisan('migrate')->assertSuccessful();
    config()->set('report-generator.schema.eloquent.paths', [__DIR__.'/../Fixtures/Models' => true]);
});

afterEach(function () {
    foreach (dataConnections() as $connection) {
        FixtureSchema::drop($connection);
    }
});

function scanFixture(string $connection): \Abdalla\ReportGenerator\Schema\ScanSummary
{
    config()->set('report-generator.data_connection', $connection);

    return app(SchemaScanner::class)->scan();
}

function relationshipKeys(): array
{
    return Relationship::query()->with(['fromDataset', 'toDataset'])->get()
        ->mapWithKeys(fn ($r) => ["{$r->fromDataset->key}.{$r->from_column}>{$r->toDataset->key}.{$r->to_column}" => $r])
        ->all();
}

it('creates datasets for tables and views but not excluded or package tables', function (string $connection) {
    FixtureSchema::create($connection);

    $summary = scanFixture($connection);

    expect(Dataset::query()->pluck('key')->sort()->values()->all())->toBe([
        'comments', 'doctors', 'lines', 'specialities', 'user_profiles', 'users', 'visit_totals', 'visits',
    ])
        ->and(Dataset::query()->where('key', 'visit_totals')->value('source_type'))->toBe('view')
        ->and($summary->tables)->toBe(7)
        ->and($summary->views)->toBe(1)
        ->and($summary->datasetsAdded)->toBe(8);
})->with(dataConnections());

it('classifies fields and protects sensitive ones', function (string $connection) {
    FixtureSchema::create($connection);
    scanFixture($connection);

    $field = fn (string $dataset, string $column) => Field::query()
        ->whereHas('dataset', fn ($q) => $q->where('key', $dataset))->where('column', $column)->firstOrFail();

    expect($field('visits', 'amount')->role)->toBe(FieldRole::Measure)
        ->and($field('visits', 'duration_min')->role)->toBe(FieldRole::Measure)
        ->and($field('visits', 'status')->role)->toBe(FieldRole::Dimension)
        ->and($field('visits', 'visit_date')->role)->toBe(FieldRole::Time)
        ->and($field('visits', 'user_id')->is_hidden)->toBeTrue()
        ->and($field('users', 'password')->is_sensitive)->toBeTrue()
        ->and($field('users', 'password')->is_hidden)->toBeTrue()
        ->and($field('users', 'remember_token')->is_hidden)->toBeTrue()
        ->and(Dataset::query()->where('key', 'visits')->value('default_time_field'))->toBe('visit_date')
        ->and(Dataset::query()->where('key', 'users')->first()->meta['soft_delete_column'])->toBe('deleted_at');

    // SQLite stores json as text; real databases report a json type, which starts hidden.
    if ($connection !== 'testing') {
        expect($field('visits', 'payload')->is_hidden)->toBeTrue();
    }
})->with(dataConnections());

it('finds relationships from foreign keys, eloquent and naming', function (string $connection) {
    FixtureSchema::create($connection);
    scanFixture($connection);

    $edges = relationshipKeys();

    expect($edges['doctors.speciality_id>specialities.id']->status)->toBe(Relationship::CONFIRMED)
        ->and($edges['user_profiles.user_id>users.id']->cardinality->value)->toBe('one_to_one')
        ->and($edges['visits.user_id>users.id']->origin)->toBe('eloquent')
        ->and($edges['visits.user_id>users.id']->status)->toBe(Relationship::PENDING)
        ->and($edges['visits.doctor_id>doctors.id']->origin)->toBe('convention')
        ->and($edges['lines.parent_id>lines.id']->confidence)->toBe(0.6)
        ->and(collect($edges)->keys()->filter(fn ($k) => str_starts_with($k, 'comments.')))->toBeEmpty();
})->with(dataConnections());

it('keeps admin edits on rescan and flags what disappeared', function (string $connection) {
    FixtureSchema::create($connection);
    scanFixture($connection);

    $visits = Dataset::query()->where('key', 'visits')->first();
    $visits->update(['label' => 'Doctor visits']);
    $status = $visits->fields()->where('column', 'status')->first();
    $status->update(['label' => 'Visit outcome', 'is_hidden' => true]);
    $edge = Relationship::query()->where('from_column', 'doctor_id')->first();
    $edge->update(['status' => Relationship::REJECTED]);

    Schema::connection($connection)->table('visits', fn ($t) => $t->dropColumn('notes'));
    Schema::connection($connection)->table('visits', fn ($t) => $t->string('channel')->nullable());

    $summary = scanFixture($connection);

    expect($summary->structureChanged)->toBeTrue()
        ->and($summary->fieldsAdded)->toBe(1)
        ->and($summary->fieldsMissing)->toBe(1)
        ->and($summary->datasetsAdded)->toBe(0)
        ->and($visits->refresh()->label)->toBe('Doctor visits')
        ->and($status->refresh()->label)->toBe('Visit outcome')
        ->and($status->is_hidden)->toBeTrue()
        ->and($edge->refresh()->status)->toBe(Relationship::REJECTED)
        ->and($visits->fields()->where('column', 'notes')->first()->isMissing())->toBeTrue()
        ->and($visits->fields()->where('column', 'channel')->exists())->toBeTrue()
        ->and(SchemaSnapshotRecord::query()->count())->toBe(2);
})->with(dataConnections());

it('flags dropped tables as missing and restores them when they return', function () {
    FixtureSchema::create('testing');
    scanFixture('testing');

    Schema::connection('testing')->drop('comments');
    $summary = scanFixture('testing');
    expect($summary->datasetsMissing)->toBe(1)
        ->and(Dataset::query()->where('key', 'comments')->first()->isMissing())->toBeTrue();

    Schema::connection('testing')->create('comments', fn ($t) => $t->id());
    $summary = scanFixture('testing');
    expect($summary->datasetsRestored)->toBe(1)
        ->and(Dataset::query()->where('key', 'comments')->first()->isMissing())->toBeFalse();
});

it('does not pile up snapshots when nothing changed', function () {
    FixtureSchema::create('testing');
    scanFixture('testing');
    $summary = scanFixture('testing');

    expect($summary->structureChanged)->toBeFalse()
        ->and(SchemaSnapshotRecord::query()->count())->toBe(1)
        ->and($summary->fieldsAdded)->toBe(0)
        ->and($summary->relationshipsAdded)->toBe(0);
});

it('runs from the command line', function () {
    FixtureSchema::create('testing');
    config()->set('report-generator.data_connection', 'testing');

    $this->artisan('report-generator:scan', ['--no-eloquent' => true])
        ->expectsOutputToContain('Scanned 7 tables and 1 views')
        ->assertSuccessful();

    expect(Relationship::query()->where('origin', 'eloquent')->exists())->toBeFalse();
});
