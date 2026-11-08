<?php

use Abdalla\ReportGenerator\Facades\ReportGenerator;
use Abdalla\ReportGenerator\Models\Dashboard;
use Abdalla\ReportGenerator\Tests\Support\EngineFixture;
use Illuminate\Auth\GenericUser;
use Illuminate\Support\Facades\Gate;

beforeEach(function () {
    $this->artisan('migrate')->assertSuccessful();
    config()->set('cache.default', 'array');
    EngineFixture::create('testing');
    EngineFixture::publish('testing');
    ReportGenerator::unrestricted(EngineFixture::TABLES);
    Gate::define('report-generator.report.create', fn () => true);
    Gate::define('report-generator.report.view-all', fn ($user) => (int) $user->id === 1);
    $this->owner = new GenericUser(['id' => 2]);
    $this->other = new GenericUser(['id' => 3]);

    $this->report = $this->actingAs($this->owner)->postJson('/api/report-generator/reports', ['name' => 'Visits per rep', 'definition' => [
        'root' => 'visits',
        'fields' => [
            ['ref' => 'users.name', 'as' => 'rep'],
            ['ref' => 'specialities.name', 'as' => 'speciality'],
            ['ref' => 'visits.visit_date', 'grain' => 'month', 'as' => 'month'],
            ['ref' => 'visits.__rows', 'agg' => 'count', 'as' => 'visits'],
            ['ref' => 'visits.amount', 'agg' => 'sum', 'as' => 'amount'],
            ['ref' => 'visits.duration_min', 'agg' => 'avg', 'as' => 'avg_minutes'],
            ['formula' => 'amount / visits', 'as' => 'per_visit'],
        ],
    ]])->assertCreated()->json('data');
});

afterEach(fn () => EngineFixture::drop('testing'));

function widget(array $widget, array $context = []): array
{
    return test()->actingAs(test()->owner)->postJson('/api/report-generator/dashboards-preview', ['widget' => $widget + ['report' => test()->report['uuid']], 'context' => $context])
        ->assertOk()->json();
}

it('proposes a dashboard from a report', function () {
    $layout = $this->actingAs($this->owner)->postJson('/api/report-generator/dashboards-generate', ['report' => $this->report['uuid']])->assertOk()->json('data');

    expect($layout['name'])->toBe('Visits per rep dashboard')
        ->and(array_column($layout['widgets'], 'type'))->toBe(['kpi', 'kpi', 'kpi', 'kpi', 'line', 'line', 'bar', 'share', 'table'])
        ->and($layout['widgets'][4])->toMatchArray(['category' => 'month', 'values' => ['visits']])
        ->and($layout['widgets'][5])->toMatchArray(['category' => 'month', 'values' => ['amount']])
        ->and($layout['widgets'][6])->toMatchArray(['category' => 'rep', 'values' => ['visits'], 'top' => 10])
        ->and($layout['widgets'][7])->toMatchArray(['category' => 'speciality', 'top' => 6]);
});

it('totals numbers for KPI widgets, averages included', function () {
    $kpi = widget(['type' => 'kpi', 'values' => ['visits', 'amount', 'avg_minutes', 'per_visit']]);

    expect($kpi['rows'])->toHaveCount(1)
        ->and($kpi['rows'][0]['visits'])->toBe(8)
        ->and(round($kpi['rows'][0]['amount'], 2))->toBe(430.5)
        ->and(round($kpi['rows'][0]['avg_minutes'], 2))->toBe(10.0)
        ->and(round($kpi['rows'][0]['per_visit'], 4))->toBe(round(430.5 / 8, 4))
        ->and(array_column($kpi['columns'], 'alias'))->toBe(['visits', 'amount', 'avg_minutes', 'per_visit']);

    // The dashboard's period applies.
    expect(widget(['type' => 'kpi', 'values' => ['visits']], ['period' => ['2026-01-01', '2026-01-31']])['rows'][0]['visits'])->toBe(3);
});

it('charts the report by one of its columns', function () {
    $bar = widget(['type' => 'bar', 'category' => 'speciality', 'values' => ['visits', 'amount'], 'top' => 5]);
    expect($bar['rows'])->toEqual([['speciality' => 'Cardio', 'visits' => 6, 'amount' => 360.0], ['speciality' => 'GP', 'visits' => 2, 'amount' => 70.5]]);

    $line = widget(['type' => 'line', 'category' => 'month', 'values' => ['visits']]);
    expect(array_map(fn ($r) => substr((string) $r['month'], 0, 7), $line['rows']))->toBe(['2026-01', '2026-02', '2026-03'])
        ->and(array_column($line['rows'], 'visits'))->toBe([3, 2, 3]);

    $top = widget(['type' => 'bar', 'category' => 'rep', 'values' => ['visits'], 'top' => 2]);
    expect($top['rows'])->toHaveCount(2)->and(array_column($top['rows'], 'visits'))->toBe([3, 3]);

    $table = widget(['type' => 'table', 'top' => 3, 'values' => ['rep', 'visits']]);
    expect($table['rows'])->toHaveCount(3)->and(array_keys($table['rows'][0]))->toBe(['rep', 'visits']);

    $this->actingAs($this->owner)->postJson('/api/report-generator/dashboards-preview', ['widget' => ['type' => 'bar', 'category' => 'visits', 'values' => ['amount'], 'report' => $this->report['uuid']]])
        ->assertStatus(422)->assertJsonPath('code', 'INVALID_WIDGET');
});

it('saves dashboards for their owner and opens them for viewers', function () {
    $layout = $this->actingAs($this->owner)->postJson('/api/report-generator/dashboards-generate', ['report' => $this->report['uuid']])->json('data');
    $saved = $this->actingAs($this->owner)->postJson('/api/report-generator/dashboards', ['name' => 'Visits', 'layout' => $layout])->assertCreated()->json('data');

    expect($saved['widgets_count'])->toBe(9)->and($saved['layout']['widgets'][0]['report_name'])->toBe('Visits per rep');

    $uuid = $saved['uuid'];
    $this->actingAs($this->other)->getJson("/api/report-generator/dashboards/{$uuid}")->assertForbidden();
    $this->actingAs($this->other)->postJson("/api/report-generator/dashboards/{$uuid}/widgets/w1")->assertForbidden();

    $this->actingAs($this->owner)->patchJson("/api/report-generator/dashboards/{$uuid}", ['visibility' => 'public'])->assertOk();
    $this->actingAs($this->other)->postJson("/api/report-generator/dashboards/{$uuid}/widgets/w1")->assertOk()->assertJsonPath('rows.0.visits', 8);
    $this->actingAs($this->other)->postJson("/api/report-generator/dashboards/{$uuid}/widgets/nope")->assertNotFound();
    $this->actingAs($this->other)->patchJson("/api/report-generator/dashboards/{$uuid}", ['name' => 'Mine'])->assertForbidden();

    expect(array_column($this->actingAs($this->other)->getJson('/api/report-generator/dashboards')->json('data'), 'uuid'))->toBe([$uuid]);

    $this->actingAs($this->owner)->deleteJson("/api/report-generator/dashboards/{$uuid}")->assertNoContent();
    expect(Dashboard::query()->count())->toBe(0);
});

it('publishes a dashboard to the holders of its permission, who see its widgets', function () {
    Gate::define('report-generator.report.publish', fn ($user) => (int) $user->id === 2);
    $this->app->bind(\Abdalla\ReportGenerator\Contracts\PermissionStore::class, fn () => new class implements \Abdalla\ReportGenerator\Contracts\PermissionStore {
        public function roles(): array { return [['id' => '1', 'name' => 'Reps']]; }
        public function ensure(string $permission): void {}
        public function syncRoles(string $permission, array $roles): void {}
        public function rolesWith(string $permission): array { return ['Reps']; }
    });
    $uuid = $this->actingAs($this->owner)->postJson('/api/report-generator/dashboards', ['name' => 'Visits board', 'layout' => ['widgets' => [
        ['id' => 'k', 'type' => 'kpi', 'report' => $this->report['uuid'], 'values' => ['visits']],
    ]]])->json('data.uuid');

    $settings = $this->actingAs($this->owner)->getJson("/api/report-generator/dashboards/{$uuid}/publish")->assertOk()->json('data');
    expect($settings['suggested_permission'])->toBe('show_reports_dashboard_visits_board');

    $this->actingAs($this->owner)->putJson("/api/report-generator/dashboards/{$uuid}/publish", ['permission' => 'see_visits_board', 'roles' => ['Reps'], 'in_menu' => true, 'menu_group' => 'Boards'])->assertOk();
    Gate::define('see_visits_board', fn ($user) => (int) $user->id === 3);

    // The viewer cannot open the report itself, but the dashboard is enough for its widgets.
    $this->actingAs($this->other)->getJson('/api/report-generator/reports/'.$this->report['uuid'].'/view')->assertForbidden();
    $this->actingAs($this->other)->postJson("/api/report-generator/dashboards/{$uuid}/widgets/k")->assertOk()->assertJsonPath('rows.0.visits', 8);

    $menu = $this->actingAs($this->other)->getJson('/api/report-generator/menu')->assertOk()->json('data');
    expect($menu)->toBe([['type' => 'dashboard', 'uuid' => $uuid, 'name' => 'Visits board', 'description' => null, 'group' => 'Boards']]);

    // One permission, one thing.
    $this->actingAs($this->owner)->putJson('/api/report-generator/reports/'.$this->report['uuid'].'/publish', ['permission' => 'see_visits_board', 'roles' => []])
        ->assertStatus(422)->assertJsonValidationErrors('permission');
});

it('refuses widgets on reports the builder cannot open', function () {
    $private = $this->actingAs($this->other)->postJson('/api/report-generator/reports', ['name' => 'Private', 'definition' => ['root' => 'visits', 'fields' => [['ref' => 'visits.__rows', 'agg' => 'count', 'as' => 'n']]]])->json('data.uuid');

    $this->actingAs($this->owner)->postJson('/api/report-generator/dashboards', ['name' => 'X', 'layout' => ['widgets' => [['id' => 'a', 'type' => 'kpi', 'report' => $private, 'values' => ['n']]]]])->assertForbidden();
    $this->actingAs($this->owner)->postJson('/api/report-generator/dashboards', ['name' => 'X', 'layout' => ['widgets' => [['id' => 'a', 'type' => 'donut', 'report' => $private]]]])->assertStatus(422);
});
