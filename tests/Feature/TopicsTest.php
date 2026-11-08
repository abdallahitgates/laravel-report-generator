<?php

use Abdalla\ReportGenerator\Facades\ReportGenerator;
use Abdalla\ReportGenerator\Query\QueryEngine;
use Abdalla\ReportGenerator\Security\ScopeContext;
use Abdalla\ReportGenerator\Semantic\Published\SemanticModelRepository;
use Abdalla\ReportGenerator\Tests\Fixtures\Engine\UsersTopic;
use Abdalla\ReportGenerator\Tests\Fixtures\Engine\VisitsTopic;
use Abdalla\ReportGenerator\Tests\Fixtures\Topics\LonelyVisitsTopic;
use Abdalla\ReportGenerator\Tests\Fixtures\Topics\NotesTopic;
use Abdalla\ReportGenerator\Tests\Support\EngineFixture;
use Abdalla\ReportGenerator\Topics\InvalidTopicException;
use Abdalla\ReportGenerator\Topics\TopicModelBuilder;
use Abdalla\ReportGenerator\Topics\TopicRegistry;
use Illuminate\Auth\GenericUser;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\Gate;

beforeEach(function () {
    $this->artisan('migrate')->assertSuccessful();
    config()->set('cache.default', 'array');
    config()->set('report-generator.topics.paths', []);
    Gate::define('report-generator.report.view-all', fn ($user) => (int) $user->id === 1);
    Gate::define('report-generator.model.manage', fn ($user) => (int) $user->id === 1);
});

afterEach(fn () => EngineFixture::drop('testing'));

function fixtureEngine(): QueryEngine
{
    EngineFixture::create('testing');
    EngineFixture::publish('testing');

    return app(QueryEngine::class);
}

function countVisits(array $extra, ?GenericUser $user = null): int
{
    $result = app(QueryEngine::class)->rows(
        ['root' => 'visits', 'fields' => [['ref' => 'visits.__rows', 'agg' => 'count', 'as' => 'n']]] + $extra,
        [], $user ?? new GenericUser(['id' => 1]), 0, 10,
    );

    return (int) $result['rows'][0]['n'];
}

it('derives keys and labels from the class name', function () {
    $topic = new VisitsTopic();
    $users = new UsersTopic();

    expect($topic->key())->toBe('visits')
        ->and($topic->label())->toBe('Visits')
        ->and($topic->table())->toBe('visits')
        ->and($topic->softDeleteColumn())->toBeNull()
        ->and($users->label())->toBe('Employees')
        ->and($users->softDeleteColumn())->toBe('deleted_at');
});

it('refuses a relation to a topic that is not registered', function () {
    ReportGenerator::topics([LonelyVisitsTopic::class]);

    app(TopicRegistry::class)->all();
})->throws(InvalidTopicException::class, 'DoctorsTopic');

it('refuses two topics with the same key', function () {
    ReportGenerator::topics([VisitsTopic::class, LonelyVisitsTopic::class]);

    app(TopicRegistry::class)->all();
})->throws(InvalidTopicException::class, 'Two topics use the key [visits]');

it('finds topic classes in the configured folders', function () {
    config()->set('report-generator.topics.paths', [
        realpath(__DIR__.'/../Fixtures/Engine') => 'Abdalla\\ReportGenerator\\Tests\\Fixtures\\Engine',
    ]);

    expect(array_keys(app(TopicRegistry::class)->all()))
        ->toEqualCanonicalizing(['lines', 'specialities', 'users', 'doctors', 'visits', 'sales']);
});

it('stores each link once, whichever side declares it', function () {
    EngineFixture::publish('testing');
    $model = app(SemanticModelRepository::class)->current();

    $edges = array_map(fn ($r) => "{$r->from}.{$r->fromColumn}>{$r->to}.{$r->toColumn}", $model->relationships);
    sort($edges);

    expect($edges)->toBe([
        'doctors.line_id>lines.id',
        'doctors.speciality_id>specialities.id',
        'sales.doctor_id>doctors.id',
        'users.line_id>lines.id',
        'visits.doctor_id>doctors.id',
        'visits.user_id>users.id',
    ])->and($model->relationships[0]->id)->toBe(TopicModelBuilder::id(
        $model->relationships[0]->from, $model->relationships[0]->fromColumn, $model->relationships[0]->to, $model->relationships[0]->toColumn,
    ));
});

it('only shows fields to the roles they are for', function () {
    ReportGenerator::topics([NotesTopic::class]);
    ReportGenerator::rolesUsing(fn ($user) => (int) $user?->id === 7 ? ['supervisor'] : []);
    $models = app(SemanticModelRepository::class);

    expect($models->current(new GenericUser(['id' => 7]))->dataset('notes')->field('notes'))->not->toBeNull()
        ->and($models->current(new GenericUser(['id' => 8]))->dataset('notes')->field('notes'))->toBeNull()
        ->and($models->current(new GenericUser(['id' => 8]))->dataset('notes')->field('status'))->not->toBeNull();
});

it('filters every report by the period, lines and employees of its main topic', function () {
    fixtureEngine();

    expect(countVisits([]))->toBe(8)
        ->and(countVisits(['context' => ['period' => ['2026-01-01', '2026-01-31']]]))->toBe(3)
        ->and(countVisits(['context' => ['employees' => [1, 2]]]))->toBe(4)
        ->and(countVisits(['context' => ['period' => ['2026-02-01', '2026-03-31'], 'employees' => [3]]]))->toBe(3)
        // Visits have no line column of their own: the line filter does not apply to them.
        ->and(countVisits(['context' => ['lines' => [1]]]))->toBe(8);

    $users = app(QueryEngine::class)->rows(
        ['root' => 'users', 'fields' => [['ref' => 'users.name', 'as' => 'name']], 'context' => ['lines' => [1]], 'sort' => [['field' => 'name', 'dir' => 'asc']]],
        [], new GenericUser(['id' => 1]), 0, 10,
    );
    expect(array_column($users['rows'], 'name'))->toBe(['Ali', 'Mona']);
});

it('keeps rows valid at any time in the period for topics with a validity span', function () {
    $schema = \Illuminate\Support\Facades\Schema::connection('testing');
    $schema->dropIfExists('assignments');
    $schema->create('assignments', function (\Illuminate\Database\Schema\Blueprint $t) {
        $t->id();
        $t->unsignedBigInteger('user_id');
        $t->date('from_date');
        $t->dateTime('to_date')->nullable();
    });
    \Illuminate\Support\Facades\DB::table('assignments')->insert([
        ['id' => 1, 'user_id' => 1, 'from_date' => '2025-01-01', 'to_date' => '2025-12-31 23:59:59'],
        ['id' => 2, 'user_id' => 1, 'from_date' => '2026-01-15', 'to_date' => null],
        ['id' => 3, 'user_id' => 2, 'from_date' => '2026-03-01', 'to_date' => '2026-03-31 00:00:00'],
        ['id' => 4, 'user_id' => 2, 'from_date' => '2024-01-01', 'to_date' => '2024-06-30 00:00:00'],
    ]);
    config()->set('report-generator.data_connection', 'testing');
    ReportGenerator::topics([\Abdalla\ReportGenerator\Tests\Fixtures\Topics\AssignmentsTopic::class]);

    $ids = fn (array $period) => array_map('intval', array_column(app(QueryEngine::class)->rows(
        ['root' => 'assignments', 'fields' => [['ref' => 'assignments.id', 'as' => 'id']], 'sort' => [['field' => 'id', 'dir' => 'asc']], 'context' => ['period' => $period]],
        [], new GenericUser(['id' => 1]), 0, 10,
    )['rows'], 'id'));

    expect($ids(['2026-01-01', '2026-01-31']))->toBe([2])
        ->and($ids(['2025-12-31', '2026-03-01']))->toBe([1, 2, 3])
        ->and($ids(['2024-06-30', '2024-06-30']))->toBe([4])
        ->and(app(QueryEngine::class)->catalog(null, new GenericUser(['id' => 1]))['datasets'][0]['filters']['period'])->toBeTrue();

    $schema->drop('assignments');
});

it('rejects a malformed period', function () {
    fixtureEngine();

    countVisits(['context' => ['period' => 'someday']]);
})->throws(\Abdalla\ReportGenerator\Query\QueryException::class, 'Unknown period');

it('limits employees to their own rows through the row scope and lets admins see everything', function () {
    fixtureEngine();
    ReportGenerator::unrestricted(['doctors', 'specialities', 'sales', 'lines']);
    $seen = null;
    ReportGenerator::scopeUsing(function (Builder $query, ScopeContext $context) use (&$seen) {
        $seen = [$context->from, $context->to];
        if ($column = $context->employeeColumn()) {
            $query->where($column, $context->user->getAuthIdentifier());
        } else {
            $query->whereRaw('1 = 0');
        }
    });

    $omar = new GenericUser(['id' => 3]);

    expect(countVisits([], $omar))->toBe(3)
        ->and(countVisits(['context' => ['period' => ['2026-03-01', '2026-03-10']]], $omar))->toBe(1)
        ->and($seen)->toBe(['2026-03-01', '2026-03-10'])
        // Topics without an employee, division or line column are not scoped.
        ->and((int) app(QueryEngine::class)->rows(['root' => 'specialities', 'fields' => [['ref' => 'specialities.__rows', 'agg' => 'count', 'as' => 'n']]], [], $omar, 0, 10)['rows'][0]['n'])->toBe(2)
        ->and(countVisits([]))->toBe(8);
});

it('keeps topics away from users who are neither scoped, allowed nor admins', function () {
    fixtureEngine();

    $error = null;
    try {
        countVisits([], new GenericUser(['id' => 3]));
    } catch (\Abdalla\ReportGenerator\Query\QueryException $e) {
        $error = $e;
    }

    expect($error?->errorCode)->toBe('DATASET_FORBIDDEN');
});

it('lists topics by tab and walks relations as a tree without going back', function () {
    $engine = fixtureEngine();
    $admin = new GenericUser(['id' => 1]);

    $catalog = $engine->catalog(null, $admin);
    expect(array_column($catalog['groups'], 'name'))->toBe(['Structure', 'List', 'Employees', 'Visits', 'Sales']);

    $tree = $engine->catalog('visits', $admin)['tree'];
    $names = fn (array $node) => array_column($node['relations'], 'name');

    expect($tree['topic'])->toBe('visits')
        ->and($tree['fields'][0]['key'])->toBe('__rows')
        ->and($tree['fields'][0]['label'])->toBe('Number of visits')
        ->and($names($tree))->toBe(['employee', 'doctor'])
        ->and($names($tree['relations'][0]))->toBe(['line'])
        ->and($names($tree['relations'][1]))->toBe(['speciality', 'line', 'sales'])
        ->and($tree['relations'][1]['relations'][2]['multiplies_rows'])->toBeTrue()
        ->and($tree['relations'][0]['multiplies_rows'])->toBeFalse()
        ->and($tree['relations'][0]['relations'][0]['path'])->toBe([
            EngineFixture::relationshipId('visits', 'user_id'),
            EngineFixture::relationshipId('users', 'line_id'),
        ]);

    // The path from the tree runs a report.
    $result = $engine->rows(['root' => 'visits', 'fields' => [
        ['ref' => 'lines.name', 'path' => $tree['relations'][0]['relations'][0]['path'], 'as' => 'line'],
        ['ref' => 'visits.__rows', 'agg' => 'count', 'as' => 'n'],
    ], 'sort' => [['field' => 'line', 'dir' => 'asc']]], [], $admin, 0, 10);
    expect(array_map('intval', array_column($result['rows'], 'n')))->toBe([1, 4, 3]);
});

it('describes every topic for the admin page', function () {
    fixtureEngine();

    $this->actingAs(new GenericUser(['id' => 2]))->getJson('/api/report-generator/topics')->assertForbidden();

    $response = $this->actingAs(new GenericUser(['id' => 1]))->getJson('/api/report-generator/topics')->assertOk();
    $visits = collect($response->json('groups'))->firstWhere('name', 'Visits')['topics'][0];

    expect($visits['key'])->toBe('visits')
        ->and($visits['table'])->toBe('visits')
        ->and($visits['filters'])->toBe(['period' => true, 'line' => false, 'division' => false, 'employee' => true])
        ->and($visits['columns'])->toBe(['period' => 'visit_date', 'line' => null, 'division' => null, 'employee' => 'user_id'])
        ->and(array_column($visits['relations'], 'topic'))->toBe(['users', 'doctors'])
        ->and(collect($visits['fields'])->firstWhere('key', 'status')['options'])->toBe(['done' => 'Done', 'missed' => 'Missed']);
});
