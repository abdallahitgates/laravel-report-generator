<?php

use Abdalla\ReportGenerator\Facades\ReportGenerator;
use Abdalla\ReportGenerator\Query\Compiler\CompiledQuery;
use Abdalla\ReportGenerator\Query\QueryEngine;
use Abdalla\ReportGenerator\Query\QueryException;
use Abdalla\ReportGenerator\Security\PolicyContext;
use Abdalla\ReportGenerator\Tests\Support\EngineFixture;
use Illuminate\Auth\GenericUser;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\Gate;

/*
 * Every expected number here is worked out by hand from EngineFixture.
 * The same tests run on SQLite, and on MySQL / PostgreSQL when configured.
 */

beforeEach(function () {
    $this->artisan('migrate')->assertSuccessful();
    config()->set('cache.default', 'array');
    Gate::define('report-generator.report.view-all', fn ($user) => (int) $user->id === 1);
    Gate::define('report-generator.model.manage', fn ($user) => (int) $user->id === 1);
    $this->admin = new GenericUser(['id' => 1]);
});

afterEach(function () {
    foreach (dataConnections() as $connection) {
        EngineFixture::drop($connection);
    }
});

function engineFor(string $connection): QueryEngine
{
    EngineFixture::create($connection);
    EngineFixture::publish($connection);

    return app(QueryEngine::class);
}

/** Runs a report as the admin and returns rows keyed by the first column. */
function runReport(QueryEngine $engine, array $definition, array $options = []): array
{
    $result = $engine->rows($definition, $options['parameters'] ?? [], $options['user'] ?? new GenericUser(['id' => 1]),
        $options['offset'] ?? 0, $options['size'] ?? 100, $options['groups'] ?? null);

    return $result;
}

function keyed(array $result, string $key, string $value): array
{
    $out = [];
    foreach ($result['rows'] as $row) {
        $out[$row[$key] ?? '(none)'] = is_numeric($row[$value]) ? round((float) $row[$value], 2) : $row[$value];
    }
    ksort($out);

    return $out;
}

function reportError(callable $run): QueryException
{
    try {
        $run();
    } catch (QueryException $e) {
        return $e;
    }
    throw new RuntimeException('Expected a QueryException.');
}

it('counts rows per joined dimension and keeps rows whose lookup was deleted', function (string $connection) {
    $engine = engineFor($connection);

    $result = runReport($engine, ['root' => 'visits', 'fields' => [
        ['ref' => 'users.name', 'as' => 'rep'],
        ['ref' => 'visits.__rows', 'agg' => 'count', 'as' => 'visits'],
    ]]);

    expect($result['meta']['mode'])->toBe(CompiledQuery::AGGREGATE)
        ->and(keyed($result, 'rep', 'visits'))->toBe(['(none)' => 1.0, 'Ali' => 3.0, 'Mona' => 1.0, 'Omar' => 3.0]);
})->with(dataConnections());

it('sums a measure across two joins', function (string $connection) {
    $engine = engineFor($connection);

    $result = runReport($engine, ['root' => 'visits', 'fields' => [
        ['ref' => 'specialities.name', 'as' => 'speciality'],
        ['ref' => 'visits.amount', 'agg' => 'sum', 'as' => 'amount'],
    ]]);

    expect(keyed($result, 'speciality', 'amount'))->toBe(['Cardio' => 360.0, 'GP' => 70.5])
        ->and($result['rows'][0]['amount'])->toBeFloat();
})->with(dataConnections());

it('groups by month', function (string $connection) {
    $engine = engineFor($connection);

    $result = runReport($engine, ['root' => 'visits', 'fields' => [
        ['ref' => 'visits.visit_date', 'grain' => 'month', 'as' => 'month'],
        ['ref' => 'visits.__rows', 'agg' => 'count', 'as' => 'visits'],
    ]]);

    $months = [];
    foreach ($result['rows'] as $row) {
        $months[substr((string) $row['month'], 0, 7)] = (int) $row['visits'];
    }

    expect($months)->toBe(['2026-01' => 3, '2026-02' => 2, '2026-03' => 3]);
})->with(dataConnections());

it('never double counts totals from two one-to-many branches', function (string $connection) {
    $engine = engineFor($connection);

    // A plain join would give Dr A 6 visits and 4500 in sales.
    $result = runReport($engine, ['root' => 'doctors', 'fields' => [
        ['ref' => 'doctors.name', 'as' => 'doctor'],
        ['ref' => 'visits.__rows', 'agg' => 'count', 'as' => 'visits'],
        ['ref' => 'sales.value', 'agg' => 'sum', 'as' => 'sales'],
        ['formula' => 'sales / visits', 'as' => 'sales_per_visit'],
    ]]);

    expect($result['meta']['mode'])->toBe(CompiledQuery::MULTI_FACT)
        ->and(keyed($result, 'doctor', 'visits'))->toBe(['Dr A' => 3.0, 'Dr B' => 2.0, 'Dr C' => 3.0])
        ->and(keyed($result, 'doctor', 'sales'))->toBe(['Dr A' => 1500.0, 'Dr B' => null, 'Dr C' => 300.0])
        ->and(keyed($result, 'doctor', 'sales_per_visit'))->toBe(['Dr A' => 500.0, 'Dr B' => null, 'Dr C' => 100.0]);
})->with(dataConnections());

it('filters through a one-to-many join with EXISTS instead of multiplying rows', function (string $connection) {
    $engine = engineFor($connection);

    // Dr C has two missed visits but must appear once.
    $result = runReport($engine, [
        'root' => 'doctors',
        'fields' => [['ref' => 'doctors.name', 'as' => 'doctor']],
        'filters' => ['and' => [['field' => 'visits.status', 'op' => '=', 'value' => 'missed']]],
    ]);

    expect(array_column($result['rows'], 'doctor'))->toBe(['Dr B', 'Dr C'])
        ->and($result['meta']['mode'])->toBe(CompiledQuery::DETAIL);
})->with(dataConnections());

it('counts distinct rows when a join repeats them', function (string $connection) {
    $engine = engineFor($connection);

    $result = runReport($engine, ['root' => 'doctors', 'fields' => [
        ['ref' => 'visits.status', 'as' => 'status'],
        ['ref' => 'doctors.__rows', 'agg' => 'count', 'as' => 'doctors'],
    ]]);

    expect(keyed($result, 'status', 'doctors'))->toBe(['(none)' => 1.0, 'done' => 3.0, 'missed' => 2.0]);
})->with(dataConnections());

it('refuses totals a join would count more than once', function (string $connection) {
    $engine = engineFor($connection);

    $error = reportError(fn () => runReport($engine, ['root' => 'doctors', 'fields' => [
        ['ref' => 'visits.status', 'as' => 'status'],
        ['ref' => 'sales.value', 'agg' => 'sum', 'as' => 'sales'],
    ]]));

    expect($error->errorCode)->toBe('FAN_TRAP')
        ->and($error->details['fields'])->toBe(['status']);
})->with(dataConnections());

it('evaluates formulas and filters on totals', function (string $connection) {
    $engine = engineFor($connection);

    $result = runReport($engine, [
        'root' => 'visits',
        'fields' => [
            ['ref' => 'users.name', 'as' => 'rep'],
            ['ref' => 'visits.__rows', 'agg' => 'count', 'as' => 'visits'],
            ['ref' => 'doctors.__rows', 'agg' => 'count_distinct', 'as' => 'doctors'],
            ['formula' => 'ROUND(visits / doctors, 2)', 'as' => 'per_doctor'],
            ['formula' => "IF(visits >= 3, 'busy', 'quiet')", 'as' => 'load'],
        ],
        'filters' => ['and' => [['field' => 'visits', 'op' => '>', 'value' => 1]]],
        'sort' => [['field' => 'rep', 'dir' => 'asc']],
    ]);

    expect(keyed($result, 'rep', 'per_doctor'))->toBe(['Ali' => 1.5, 'Omar' => 3.0])
        ->and(keyed($result, 'rep', 'load'))->toBe(['Ali' => 'busy', 'Omar' => 'busy']);
})->with(dataConnections());

it('works out percentages and days between dates', function (string $connection) {
    $engine = engineFor($connection);

    $result = runReport($engine, [
        'root' => 'visits',
        'fields' => [
            ['ref' => 'users.name', 'as' => 'rep'],
            ['ref' => 'visits.__rows', 'agg' => 'count', 'as' => 'visits'],
            ['ref' => 'visits.visit_date', 'agg' => 'min', 'as' => 'first'],
            ['ref' => 'visits.visit_date', 'agg' => 'max', 'as' => 'last'],
            ['formula' => 'PERCENT(visits, 8)', 'as' => 'share'],
            ['formula' => 'DAYS_BETWEEN(first, last)', 'as' => 'span'],
        ],
        'sort' => [['field' => 'rep', 'dir' => 'asc']],
    ]);

    foreach ($result['rows'] as $row) {
        $days = (int) (new DateTimeImmutable(substr((string) $row['first'], 0, 10)))->diff(new DateTimeImmutable(substr((string) $row['last'], 0, 10)))->format('%r%a');
        expect(round((float) $row['share'], 2))->toBe(round($row['visits'] * 100 / 8, 2))
            ->and((int) $row['span'])->toBe($days);
    }
    expect(array_sum(array_map(fn ($r) => (float) $r['share'], $result['rows'])))->toEqualWithDelta(100.0, 0.01);
})->with(dataConnections());

it('applies every filter operator', function (string $connection) {
    $engine = engineFor($connection);
    $count = function (array $condition) use ($engine) {
        $result = runReport($engine, [
            'root' => 'visits',
            'fields' => [['ref' => 'visits.__rows', 'agg' => 'count', 'as' => 'n']],
            'filters' => ['and' => [$condition]],
        ]);

        return (int) $result['rows'][0]['n'];
    };

    expect($count(['field' => 'visits.status', 'op' => '=', 'value' => 'done']))->toBe(4)
        // != keeps rows where the value is empty: what people mean by "not done".
        ->and($count(['field' => 'visits.status', 'op' => '!=', 'value' => 'done']))->toBe(4)
        ->and($count(['field' => 'visits.status', 'op' => 'in', 'value' => ['done', 'missed']]))->toBe(7)
        ->and($count(['field' => 'visits.status', 'op' => 'not_in', 'value' => ['done']]))->toBe(4)
        ->and($count(['field' => 'visits.status', 'op' => 'is_null']))->toBe(1)
        ->and($count(['field' => 'visits.amount', 'op' => '>=', 'value' => 70.5]))->toBe(3)
        ->and($count(['field' => 'visits.visit_date', 'op' => 'between', 'value' => ['2026-01-01 00:00:00', '2026-01-31 23:59:59']]))->toBe(3)
        ->and($count(['field' => 'users.name', 'op' => 'contains', 'value' => 'MA']))->toBe(3)
        ->and($count(['field' => 'users.name', 'op' => 'starts_with', 'value' => 'a']))->toBe(3)
        ->and($count(['field' => 'users.name', 'op' => 'contains', 'value' => '%']))->toBe(0)
        ->and($count(['field' => 'doctors.is_kol', 'op' => '=', 'value' => true]))->toBe(3);
})->with(dataConnections());

it('asks which path to use when a dataset is reachable two ways', function (string $connection) {
    $engine = engineFor($connection);
    $definition = ['root' => 'visits', 'fields' => [
        ['ref' => 'lines.name', 'as' => 'line'],
        ['ref' => 'visits.__rows', 'agg' => 'count', 'as' => 'visits'],
    ]];

    $error = reportError(fn () => runReport($engine, $definition));
    expect($error->errorCode)->toBe('AMBIGUOUS_PATH')
        ->and($error->details['options'])->toHaveCount(2)
        ->and($error->path)->toBe('fields.0.ref');

    // Through the rep: the deleted rep's visit has no line.
    $definition['fields'][0]['path'] = [EngineFixture::relationshipId('visits', 'user_id'), EngineFixture::relationshipId('users', 'line_id')];
    expect(keyed(runReport($engine, $definition), 'line', 'visits'))->toBe(['(none)' => 1.0, 'North' => 4.0, 'South' => 3.0]);

    // Through the doctor.
    $definition['fields'][0]['path'] = [EngineFixture::relationshipId('visits', 'doctor_id'), EngineFixture::relationshipId('doctors', 'line_id')];
    expect(keyed(runReport($engine, $definition), 'line', 'visits'))->toBe(['North' => 5.0, 'South' => 3.0]);
})->with(dataConnections());

it('drills down level by level for the grid', function (string $connection) {
    $engine = engineFor($connection);
    $definition = ['root' => 'visits', 'fields' => [
        ['ref' => 'specialities.name', 'as' => 'speciality'],
        ['ref' => 'visits.visit_date', 'grain' => 'month', 'as' => 'month'],
        ['ref' => 'visits.__rows', 'agg' => 'count', 'as' => 'visits'],
    ]];
    $groups = fn (array $keys) => ['columns' => ['speciality', 'month'], 'keys' => $keys];

    $top = runReport($engine, $definition, ['groups' => $groups([])]);
    expect(array_column($top['columns'], 'alias'))->toBe(['speciality', 'visits'])
        ->and(keyed($top, 'speciality', 'visits'))->toBe(['Cardio' => 6.0, 'GP' => 2.0]);

    $cardio = runReport($engine, $definition, ['groups' => $groups(['Cardio'])]);
    expect(array_map(fn ($r) => substr((string) $r['month'], 0, 7), $cardio['rows']))->toBe(['2026-01', '2026-03'])
        ->and(array_map('intval', array_column($cardio['rows'], 'visits')))->toBe([3, 3]);

    $january = runReport($engine, $definition, ['groups' => $groups(['Cardio', $cardio['rows'][0]['month']])]);
    expect($january['rows'])->toHaveCount(1)
        ->and((int) $january['rows'][0]['visits'])->toBe(3);
})->with(dataConnections());

it('groups a detail report into distinct values with row counts', function (string $connection) {
    $engine = engineFor($connection);
    $definition = ['root' => 'visits', 'fields' => [
        ['ref' => 'visits.status', 'as' => 'status'],
        ['ref' => 'visits.amount', 'as' => 'amount'],
    ]];

    $top = runReport($engine, $definition, ['groups' => ['columns' => ['status'], 'keys' => []]]);
    $leaf = runReport($engine, $definition, ['groups' => ['columns' => ['status'], 'keys' => ['missed']]]);

    expect(keyed($top, 'status', '__group_count'))->toBe(['(none)' => 1.0, 'done' => 4.0, 'missed' => 3.0])
        ->and($leaf['rows'])->toHaveCount(3)
        ->and(array_column($leaf['columns'], 'alias'))->toBe(['status', 'amount']);
})->with(dataConnections());

it('pages through results', function (string $connection) {
    $engine = engineFor($connection);
    $definition = ['root' => 'visits', 'fields' => [['ref' => 'visits.amount', 'as' => 'amount']]];

    $first = runReport($engine, $definition, ['size' => 3]);
    $last = runReport($engine, $definition, ['size' => 3, 'offset' => 6]);

    expect($first['rows'])->toHaveCount(3)
        ->and($first['page']['has_more'])->toBeTrue()
        ->and($last['rows'])->toHaveCount(2)
        ->and($last['page']['has_more'])->toBeFalse();
})->with(dataConnections());

it('applies row policies everywhere a dataset appears, including EXISTS filters', function (string $connection) {
    $engine = engineFor($connection);
    $rep = new GenericUser(['id' => 2]);
    // A rep sees their own visits only.
    ReportGenerator::policy('visits', fn (Builder $q, PolicyContext $ctx) => $q->where($ctx->column('user_id'), $ctx->user->id));
    ReportGenerator::unrestricted(['users']);

    $mine = runReport($engine, ['root' => 'visits', 'fields' => [
        ['ref' => 'users.name', 'as' => 'rep'],
        ['ref' => 'visits.__rows', 'agg' => 'count', 'as' => 'visits'],
    ]], ['user' => $rep]);
    expect(keyed($mine, 'rep', 'visits'))->toBe(['Mona' => 1.0]);

    // Doctors have no policy and are not unrestricted: deny by default.
    $error = reportError(fn () => runReport($engine, ['root' => 'visits', 'fields' => [['ref' => 'doctors.name', 'as' => 'd']]], ['user' => $rep]));
    expect($error->errorCode)->toBe('DATASET_FORBIDDEN')->and($error->status)->toBe(403);

    // The EXISTS filter only sees Mona's visits, so only Dr B qualifies (not Dr C's missed visits).
    ReportGenerator::unrestricted(['doctors']);
    $result = runReport($engine, [
        'root' => 'doctors',
        'fields' => [['ref' => 'doctors.name', 'as' => 'doctor']],
        'filters' => ['and' => [['field' => 'visits.status', 'op' => 'in', 'value' => ['done', 'missed']]]],
    ], ['user' => $rep]);
    expect(array_column($result['rows'], 'doctor'))->toBe(['Dr B']);
})->with(dataConnections());

it('adds subtotals where the database supports them', function (string $connection) {
    $engine = engineFor($connection);
    $definition = ['root' => 'visits', 'subtotals' => true, 'fields' => [
        ['ref' => 'specialities.name', 'as' => 'speciality'],
        ['ref' => 'visits.status', 'as' => 'status'],
        ['ref' => 'visits.__rows', 'agg' => 'count', 'as' => 'visits'],
    ]];

    if ($connection === 'testing') {
        expect(reportError(fn () => runReport($engine, $definition))->errorCode)->toBe('SUBTOTALS_UNSUPPORTED');

        return;
    }

    $rows = runReport($engine, $definition)['rows'];
    $grand = array_values(array_filter($rows, fn ($r) => (int) $r['__subtotal'] === 2));
    $cardio = array_values(array_filter($rows, fn ($r) => (int) $r['__subtotal'] === 1 && $r['speciality'] === 'Cardio'));

    expect((int) $grand[0]['visits'])->toBe(8)->and((int) $cardio[0]['visits'])->toBe(6);
})->with(dataConnections());

it('serves a repeated report from the cache', function () {
    $engine = engineFor('testing');
    $definition = ['root' => 'visits', 'fields' => [['ref' => 'visits.__rows', 'agg' => 'count', 'as' => 'n']]];

    expect(runReport($engine, $definition)['meta']['cached'])->toBeFalse()
        ->and(runReport($engine, $definition)['meta']['cached'])->toBeTrue();
});

it('limits how many reports one user runs at once, after waiting for a slot', function () {
    $engine = engineFor('testing');
    config()->set('report-generator.limits.max_concurrent_per_user', 1);
    config()->set('report-generator.limits.queue_wait_ms', 300);
    $held = cache()->lock('report-generator:slot:user:1:0', 30);
    $held->get();

    $started = microtime(true);
    $error = reportError(fn () => runReport($engine, ['root' => 'visits', 'fields' => [['ref' => 'visits.__rows', 'agg' => 'count', 'as' => 'n']]]));
    $waited = microtime(true) - $started;
    $held->release();

    expect($error->errorCode)->toBe('TOO_MANY_QUERIES')->and($error->status)->toBe(429)
        ->and($waited)->toBeGreaterThanOrEqual(0.3);
});

it('runs as soon as a slot frees up while waiting', function () {
    $engine = engineFor('testing');
    config()->set('report-generator.limits.max_concurrent_per_user', 1);
    config()->set('report-generator.limits.queue_wait_ms', 3000);
    // Held by "another request" that finishes within a second.
    cache()->lock('report-generator:slot:user:1:0', 1)->get();

    $result = runReport($engine, ['root' => 'visits', 'fields' => [['ref' => 'visits.__rows', 'agg' => 'count', 'as' => 'n']]]);
    expect((int) $result['rows'][0]['n'])->toBe(8);
});

it('explains mistakes with a code and where they are', function (string $definitionPatch, string $code, string $path) {
    $engine = engineFor('testing');
    $definition = array_replace_recursive(['root' => 'visits', 'fields' => [['ref' => 'visits.amount', 'agg' => 'sum', 'as' => 'amount']]], json_decode($definitionPatch, true));

    $error = reportError(fn () => runReport($engine, $definition));

    expect($error->errorCode)->toBe($code)->and($error->path)->toBe($path);
})->with([
    'unknown dataset' => ['{"root": "nope"}', 'UNKNOWN_DATASET', 'root'],
    'unknown field' => ['{"fields": [{"ref": "visits.nope"}]}', 'UNKNOWN_FIELD', 'fields.0.ref'],
    'text aggregated' => ['{"fields": [{"ref": "visits.status", "agg": "sum"}]}', 'INVALID_AGGREGATION', 'fields.0.agg'],
    'grain on text' => ['{"fields": [{"ref": "visits.status", "agg": null, "grain": "month"}]}', 'INVALID_GRAIN', 'fields.0.grain'],
    'bad formula' => ['{"fields": [{"ref": null, "formula": "amount * (2", "as": "x"}]}', 'INVALID_FORMULA', 'fields.0.formula'],
    'unknown function' => ['{"fields": [{"ref": null, "formula": "SLEEP(10)", "as": "x"}]}', 'INVALID_FORMULA', 'fields'],
    'contains on number' => ['{"filters": {"and": [{"field": "visits.amount", "op": "contains", "value": "1"}]}}', 'INVALID_OPERATOR', 'filters.and.0.op'],
    'text for number' => ['{"filters": {"and": [{"field": "visits.amount", "op": ">", "value": "x"}]}}', 'INVALID_VALUE', 'filters.and.0.value'],
    'mixed or' => ['{"filters": {"or": [{"field": "amount", "op": ">", "value": 1}, {"field": "visits.status", "op": "=", "value": "done"}]}}', 'MIXED_FILTER', 'filters'],
    'missing parameter' => ['{"parameters": [{"key": "period", "required": true}], "filters": {"and": [{"field": "visits.visit_date", "op": "between", "value": "{{period}}"}]}}', 'MISSING_PARAMETER', 'parameters.0'],
]);

it('resolves date presets and parameters', function () {
    $engine = engineFor('testing');
    \Carbon\Carbon::setTestNow('2026-02-15 10:00:00');

    $definition = [
        'root' => 'visits',
        'parameters' => [['key' => 'period', 'type' => 'date_range', 'default' => 'last_month']],
        'fields' => [['ref' => 'visits.__rows', 'agg' => 'count', 'as' => 'n']],
        'filters' => ['and' => [['field' => 'visits.visit_date', 'op' => 'between', 'value' => '{{period}}']]],
    ];

    expect((int) runReport($engine, $definition)['rows'][0]['n'])->toBe(3)
        ->and((int) runReport($engine, $definition, ['parameters' => ['period' => 'this_month']])['rows'][0]['n'])->toBe(2);

    \Carbon\Carbon::setTestNow();
});

it('lists filter values from the server, searched and capped', function (string $connection) {
    $engine = engineFor($connection);

    expect($engine->values('users.name', 'o', 10, new GenericUser(['id' => 1])))->toBe(['Mona', 'Omar'])
        ->and($engine->values('visits.status', null, 10, new GenericUser(['id' => 1])))->toBe(['done', 'missed']);
})->with(dataConnections());

it('describes what can be reached from a root dataset', function () {
    $engine = engineFor('testing');

    $catalog = $engine->catalog('visits', new GenericUser(['id' => 1]));
    $datasets = collect($catalog['datasets'])->keyBy('key');

    expect($datasets->keys()->sort()->values()->all())->toBe(['doctors', 'lines', 'sales', 'specialities', 'users', 'visits'])
        ->and($datasets['lines']['paths'])->toHaveCount(2)
        ->and($datasets['sales']['paths'][0]['multiplies_rows'])->toBeTrue()
        ->and($datasets['users']['paths'][0]['multiplies_rows'])->toBeFalse()
        ->and($datasets['visits']['fields'][0]['key'])->toBe('__rows');
});
