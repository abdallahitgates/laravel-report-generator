<?php

use Abdalla\ReportGenerator\Tests\Support\EngineFixture;
use Illuminate\Auth\GenericUser;
use Illuminate\Support\Facades\Gate;

beforeEach(function () {
    $this->artisan('migrate')->assertSuccessful();
    config()->set('cache.default', 'array');
    Gate::define('report-generator.report.create', fn ($user) => in_array((int) $user->id, [1, 2], true));
    Gate::define('report-generator.report.view-all', fn ($user) => (int) $user->id === 1);
    Gate::define('report-generator.model.manage', fn ($user) => (int) $user->id === 1);
});

afterEach(fn () => EngineFixture::drop('testing'));

$definition = ['root' => 'visits', 'fields' => [
    ['ref' => 'specialities.name', 'as' => 'speciality'],
    ['ref' => 'visits.amount', 'agg' => 'sum', 'as' => 'amount'],
]];

it('explains that nothing is published yet', function () use ($definition) {
    $this->actingAs(new GenericUser(['id' => 1]))
        ->postJson('/api/report-generator/query/rows', ['definition' => $definition])
        ->assertStatus(422)
        ->assertJsonPath('code', 'MODEL_NOT_PUBLISHED');
});

it('runs a report page', function () use ($definition) {
    EngineFixture::create('testing');
    EngineFixture::publish('testing');

    $this->actingAs(new GenericUser(['id' => 1]))
        ->postJson('/api/report-generator/query/rows', ['definition' => $definition, 'page' => ['size' => 1]])
        ->assertOk()
        ->assertJsonPath('columns.1.alias', 'amount')
        ->assertJsonPath('rows.0.speciality', 'Cardio')
        ->assertJsonPath('rows.0.amount', 360)
        ->assertJsonPath('page.has_more', true)
        ->assertJsonPath('meta.mode', 'aggregate')
        ->assertJsonStructure(['meta' => ['duration_ms', 'model_version', 'sql']]);
});

it('only shows the SQL to people who manage the model', function () use ($definition) {
    EngineFixture::create('testing');
    EngineFixture::publish('testing');
    \Abdalla\ReportGenerator\Facades\ReportGenerator::unrestricted(['visits', 'doctors', 'specialities']);

    $this->actingAs(new GenericUser(['id' => 2]))
        ->postJson('/api/report-generator/query/validate', ['definition' => $definition])
        ->assertOk()
        ->assertJsonPath('mode', 'aggregate')
        ->assertJsonMissingPath('sql');
});

it('returns definition errors as 422 with a code and a path', function () {
    EngineFixture::create('testing');
    EngineFixture::publish('testing');

    $this->actingAs(new GenericUser(['id' => 1]))
        ->postJson('/api/report-generator/query/rows', ['definition' => ['root' => 'visits', 'fields' => [['ref' => 'visits.nope', 'as' => 'x']]]])
        ->assertStatus(422)
        ->assertJsonPath('code', 'UNKNOWN_FIELD')
        ->assertJsonPath('path', 'fields.0.ref');
});

it('keeps report building to users with the ability', function () use ($definition) {
    $this->actingAs(new GenericUser(['id' => 3]))
        ->postJson('/api/report-generator/query/rows', ['definition' => $definition])
        ->assertForbidden();
    $this->actingAs(new GenericUser(['id' => 3]))->getJson('/api/report-generator/catalog')->assertForbidden();
});

it('lists the datasets a user may start from', function () {
    EngineFixture::create('testing');
    EngineFixture::publish('testing');
    \Abdalla\ReportGenerator\Facades\ReportGenerator::unrestricted(['doctors']);

    $admin = collect($this->actingAs(new GenericUser(['id' => 1]))->getJson('/api/report-generator/catalog')->assertOk()->json('datasets'))->pluck('key');
    $rep = collect($this->actingAs(new GenericUser(['id' => 2]))->getJson('/api/report-generator/catalog')->assertOk()->json('datasets'))->pluck('key');

    expect($admin)->toContain('visits', 'sales')->and($rep->all())->toBe(['doctors']);
});

it('serves filter values', function () {
    EngineFixture::create('testing');
    EngineFixture::publish('testing');

    $this->actingAs(new GenericUser(['id' => 1]))
        ->postJson('/api/report-generator/query/values', ['field' => 'specialities.name', 'search' => 'car'])
        ->assertOk()
        ->assertJsonPath('data', ['Cardio']);
});

it('cancels only your own queries', function () {
    EngineFixture::create('testing');
    EngineFixture::publish('testing');

    $this->actingAs(new GenericUser(['id' => 1]))
        ->postJson('/api/report-generator/query/unknown-id/cancel')
        ->assertOk()
        ->assertJsonPath('cancelled', false);
});
