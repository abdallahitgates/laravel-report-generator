<?php

use Abdalla\ReportGenerator\Facades\ReportGenerator;
use Abdalla\ReportGenerator\Tests\Fixtures\Publishing\Member;
use Abdalla\ReportGenerator\Tests\Support\EngineFixture;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Schema;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionServiceProvider;

beforeEach(function () {
    $this->app->register(PermissionServiceProvider::class);
    config()->set('auth.providers.users.model', Member::class);
    config()->set('cache.default', 'array');
    $this->artisan('migrate')->assertSuccessful();
    // spatie 6 returns an anonymous migration; spatie 5 declares CreatePermissionTables (once per process).
    $migration = class_exists('CreatePermissionTables', false)
        ? new CreatePermissionTables()
        : include __DIR__.'/../../vendor/spatie/laravel-permission/database/migrations/create_permission_tables.php.stub';
    (is_object($migration) ? $migration : new CreatePermissionTables())->up();

    Schema::create('members', function (Blueprint $t) {
        $t->id();
        $t->string('name');
    });
    EngineFixture::create('testing');
    EngineFixture::publish('testing');
    ReportGenerator::unrestricted(EngineFixture::TABLES);

    $this->admin = Member::query()->create(['name' => 'Admin']);
    $this->rep = Member::query()->create(['name' => 'Rep']);
    $this->other = Member::query()->create(['name' => 'Other']);
    Role::findOrCreate('Medical Rep', 'web');
    Role::findOrCreate('Area Manager', 'web');
    $this->rep->assignRole('Medical Rep');

    $isAdmin = fn ($user) => $user->name === 'Admin';
    Gate::define('report-generator.report.create', $isAdmin);
    Gate::define('report-generator.report.publish', $isAdmin);
    Gate::define('report-generator.report.view-all', $isAdmin);

    $this->report = $this->actingAs($this->admin)->postJson('/api/report-generator/reports', [
        'name' => 'Visits per rep',
        'definition' => ['root' => 'visits', 'fields' => [
            ['ref' => 'users.name', 'as' => 'rep'],
            ['ref' => 'visits.__rows', 'agg' => 'count', 'as' => 'visits'],
        ], 'sort' => [['field' => 'rep', 'dir' => 'asc']]],
    ])->assertCreated()->json('data');
});

afterEach(fn () => EngineFixture::drop('testing'));

function publish(array $overrides = []): \Illuminate\Testing\TestResponse
{
    return test()->actingAs(test()->admin)->putJson('/api/report-generator/reports/'.test()->report['uuid'].'/publish', $overrides + [
        'permission' => 'show_reports_visits_per_rep',
        'roles' => ['Medical Rep'],
        'in_menu' => true,
        'menu_group' => 'Visits',
    ]);
}

it('suggests a permission named after the report and lists the roles', function () {
    $this->actingAs($this->admin)->getJson('/api/report-generator/reports/'.$this->report['uuid'].'/publish')
        ->assertOk()
        ->assertJsonPath('data.published', false)
        ->assertJsonPath('data.suggested_permission', 'show_reports_visits_per_rep');

    expect(array_column($this->actingAs($this->admin)->getJson('/api/report-generator/permissions/roles')->json('data'), 'name'))
        ->toBe(['Area Manager', 'Medical Rep']);

    $this->actingAs($this->rep)->getJson('/api/report-generator/permissions/roles')->assertForbidden();
});

it('creates the permission, gives it to the roles and lists the report in their menu', function () {
    publish()->assertOk()
        ->assertJsonPath('data.published', true)
        ->assertJsonPath('data.roles', ['Medical Rep'])
        ->assertJsonPath('data.in_menu', true);

    expect($this->rep->fresh()->hasPermissionTo('show_reports_visits_per_rep'))->toBeTrue()
        ->and($this->other->fresh()->hasPermissionTo('show_reports_visits_per_rep'))->toBeFalse();

    $this->actingAs($this->rep->fresh())->getJson('/api/report-generator/menu')
        ->assertOk()->assertJsonPath('data.0.uuid', $this->report['uuid'])->assertJsonPath('data.0.group', 'Visits');
    $this->actingAs($this->other)->getJson('/api/report-generator/menu')->assertOk()->assertJsonCount(0, 'data');

    // Changing the roles moves the permission.
    publish(['roles' => ['Area Manager']])->assertOk()->assertJsonPath('data.roles', ['Area Manager']);
    expect($this->rep->fresh()->hasPermissionTo('show_reports_visits_per_rep'))->toBeFalse();
});

it('lets holders open and run the report without being able to build reports', function () {
    publish()->assertOk();
    $rep = $this->rep->fresh();
    $uuid = $this->report['uuid'];

    $this->actingAs($rep)->getJson("/api/report-generator/reports/{$uuid}/view")
        ->assertOk()->assertJsonPath('data.name', 'Visits per rep')->assertJsonPath('data.can_edit', false);

    $rows = $this->actingAs($rep)->postJson("/api/report-generator/reports/{$uuid}/rows")->assertOk()->json('rows');
    expect(array_column($rows, 'rep'))->toBe([null, 'Ali', 'Mona', 'Omar']);

    $view = $this->actingAs($rep)->getJson("/api/report-generator/reports/{$uuid}/view")->json('data');
    expect(array_column($view['fields'], 'kind', 'as'))->toBe(['rep' => 'dimension', 'visits' => 'value'])
        ->and($view['sort'])->toBe([['field' => 'rep', 'dir' => 'asc']]);

    $sorted = $this->actingAs($rep)->postJson("/api/report-generator/reports/{$uuid}/rows", ['sort' => [['field' => 'visits', 'dir' => 'desc'], ['field' => 'nope', 'dir' => 'asc']]])
        ->assertOk()->json('rows');
    expect((int) $sorted[0]['visits'])->toBe(3);

    // The viewer picks the period.
    $january = $this->actingAs($rep)->postJson("/api/report-generator/reports/{$uuid}/rows", ['context' => ['period' => ['2026-01-01', '2026-01-31']]])
        ->assertOk()->json('rows');
    expect(array_sum(array_column($january, 'visits')))->toBe(3);

    // Values only for the report's own fields.
    $this->actingAs($rep)->postJson("/api/report-generator/reports/{$uuid}/values", ['field' => 'users.name'])->assertOk()->assertJsonPath('data', ['Ali', 'Mona', 'Omar']);
    $this->actingAs($rep)->postJson("/api/report-generator/reports/{$uuid}/values", ['field' => 'doctors.name'])->assertStatus(422);

    // Building is still off limits, and so is the editable report.
    $this->actingAs($rep)->postJson('/api/report-generator/query/rows', ['definition' => ['root' => 'visits', 'fields' => [['ref' => 'visits.__rows', 'agg' => 'count', 'as' => 'n']]]])->assertForbidden();
    $this->actingAs($this->other)->getJson("/api/report-generator/reports/{$uuid}/view")->assertForbidden();
    $this->actingAs($this->other)->postJson("/api/report-generator/reports/{$uuid}/rows")->assertForbidden();
});

it('stops opening the report once it is unpublished', function () {
    publish()->assertOk();
    $uuid = $this->report['uuid'];
    $this->actingAs($this->admin)->deleteJson("/api/report-generator/reports/{$uuid}/publish")->assertOk()->assertJsonPath('data.published', false);

    $this->actingAs($this->rep->fresh())->getJson("/api/report-generator/reports/{$uuid}/view")->assertForbidden();
    $this->actingAs($this->rep->fresh())->getJson('/api/report-generator/menu')->assertJsonCount(0, 'data');
});

it('does not let publishers publish what they cannot open', function () {
    // A publisher who is not an admin and does not own the report.
    $publisher = Member::query()->create(['name' => 'Publisher']);
    Gate::define('report-generator.report.publish', fn ($user) => in_array($user->name, ['Admin', 'Publisher'], true));

    $this->actingAs($publisher)->getJson('/api/report-generator/reports/'.$this->report['uuid'].'/publish')->assertForbidden();
    $this->actingAs($publisher)->putJson('/api/report-generator/reports/'.$this->report['uuid'].'/publish', ['permission' => 'x_y', 'roles' => []])->assertForbidden();
});

it('validates the permission name and roles', function () {
    publish(['permission' => 'Show Reports!'])->assertStatus(422)->assertJsonValidationErrors('permission');
    publish(['roles' => ['Nobody']])->assertStatus(422)->assertJsonValidationErrors('roles.0');

    publish()->assertOk();
    $second = $this->actingAs($this->admin)->postJson('/api/report-generator/reports', [
        'name' => 'Another', 'definition' => ['root' => 'visits', 'fields' => [['ref' => 'visits.__rows', 'agg' => 'count', 'as' => 'n']]],
    ])->json('data');
    $this->actingAs($this->admin)->putJson("/api/report-generator/reports/{$second['uuid']}/publish", ['permission' => 'show_reports_visits_per_rep', 'roles' => []])
        ->assertStatus(422)->assertJsonValidationErrors('permission');

    $this->actingAs($this->rep)->putJson('/api/report-generator/reports/'.$this->report['uuid'].'/publish', ['permission' => 'x', 'roles' => []])->assertForbidden();
});

it('shows holders the report in their library and marks it published', function () {
    publish()->assertOk();
    Gate::define('report-generator.report.create', fn ($user) => true);

    $library = $this->actingAs($this->rep->fresh())->getJson('/api/report-generator/reports')->assertOk()->json('data');
    expect(array_column($library, 'uuid'))->toBe([$this->report['uuid']])
        ->and($library[0]['published'])->toBeTrue()
        ->and($library[0]['can_publish'])->toBeFalse();
    $this->actingAs($this->other)->getJson('/api/report-generator/reports')->assertOk()->assertJsonCount(0, 'data');
});
