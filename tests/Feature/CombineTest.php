<?php

use Abdalla\ReportGenerator\Combine\CombineRun;
use Abdalla\ReportGenerator\Combine\ExistingReport;
use Abdalla\ReportGenerator\Facades\ReportGenerator;
use Abdalla\ReportGenerator\Query\QueryEngine;
use Abdalla\ReportGenerator\Query\QueryException;
use Abdalla\ReportGenerator\Tests\Fixtures\Combine\SalesController;
use Abdalla\ReportGenerator\Tests\Fixtures\Combine\VisitStatsController;
use Abdalla\ReportGenerator\Tests\Support\EngineFixture;
use Illuminate\Auth\GenericUser;
use Illuminate\Support\Facades\Gate;

beforeEach(function () {
    $this->artisan('migrate')->assertSuccessful();
    config()->set('cache.default', 'array');
    VisitStatsController::$seen = [];
    SalesController::$lines = [];
    SalesController::$fail = false;
    EngineFixture::create('testing');
    EngineFixture::publish('testing');
    config()->set('report-generator.context.lines', ['topic' => 'lines', 'label' => 'name']);
    ReportGenerator::unrestricted(['lines']);

    Gate::define('report-generator.report.create', fn () => true);
    Gate::define('report-generator.report.export', fn () => true);
    Gate::define('show_reports_sales', fn ($user) => (int) $user->id === 1);

    ReportGenerator::combinable(fn () => [
        ExistingReport::make('visit_statistics', 'Visit statistics')
            ->inGroup('Visits')
            ->action([VisitStatsController::class, 'filter'])
            ->payload(fn (CombineRun $run) => ['visitFilter' => [
                'fromDate' => $run->fromDate(), 'toDate' => $run->toDate(), 'lines' => $run->lines, 'filter' => $run->byEmployee() ? 2 : 1,
            ]])
            ->hiding(['color']),
        ExistingReport::make('sales', 'Sales achievement')
            ->at('employee')
            ->requires('show_reports_sales')
            ->action([SalesController::class, 'filter'])
            ->perLine(fn (int $line) => $line === 1 ? 'L1' : 'L2')
            ->payload(fn (CombineRun $run) => ['salesFilter' => ['from' => $run->fromDate()]]),
    ]);

    $this->admin = new GenericUser(['id' => 1]);
    $this->rep = new GenericUser(['id' => 3]);
});

afterEach(fn () => EngineFixture::drop('testing'));

$combined = ['type' => 'combined', 'level' => 'employee', 'context' => ['period' => ['2026-01-01', '2026-01-31']], 'fields' => [
    ['base' => 'employee', 'as' => 'employee'],
    ['report' => 'visit_statistics', 'field' => 'total_actual', 'as' => 'visits', 'label' => 'Visits'],
    ['report' => 'visit_statistics', 'field' => 'avg_visits', 'as' => 'per_day'],
    ['report' => 'sales', 'field' => 'value', 'as' => 'sales'],
    ['report' => 'sales', 'field' => 'achievement', 'as' => 'achievement'],
    ['formula' => 'ROUND(sales / visits, 2)', 'as' => 'sales_per_visit', 'label' => 'Sales per visit'],
], 'sort' => [['field' => 'visits', 'dir' => 'desc']]];

it('puts columns of several reports side by side per employee', function () use ($combined) {
    $this->actingAs($this->admin);
    $result = app(QueryEngine::class)->rows($combined, [], $this->admin, 0, 50);

    expect(array_column($result['rows'], 'employee'))->toBe(['Ali', 'Omar', 'Mona', 'Sara'])
        // Ali is listed once per product in the sales report: amounts add up, rates cannot.
        ->and($result['rows'][0])->toBe(['employee' => 'Ali', 'visits' => 12, 'per_day' => 4.0, 'sales' => 1500.5, 'achievement' => null, 'sales_per_visit' => 125.04])
        ->and($result['rows'][3]['achievement'])->toBe(0.305)
        // Omar has no sales value of note, Sara no visits: rows stay, values are empty.
        ->and($result['rows'][1]['sales_per_visit'])->toEqual(0)
        ->and($result['rows'][3]['visits'])->toBeNull()
        ->and($result['rows'][3]['sales_per_visit'])->toBeNull()
        ->and($result['meta']['mode'])->toBe('combined');

    $columns = array_column($result['columns'], null, 'alias');
    expect($columns['visits']['label'])->toBe('Visits')
        ->and($columns['per_day']['label'])->toBe('Visit statistics: Avg Visits')
        ->and($columns['achievement']['format'])->toBe('0.00%')
        ->and($columns['visits']['total'])->toBe('sum')
        ->and($columns['per_day']['total'])->toBeNull()
        ->and($columns['achievement']['total'])->toBeNull();

    // Each report ran once per line it needs, with the period and the viewer's lines.
    expect(VisitStatsController::$seen)->toHaveCount(1)
        ->and(VisitStatsController::$seen[0]['payload'])->toBe(['fromDate' => '2026-01-01', 'toDate' => '2026-01-31', 'lines' => [1, 2], 'filter' => 2])
        ->and(VisitStatsController::$seen[0]['helper'])->toBe(2)
        ->and(VisitStatsController::$seen[0]['user'])->toBe(1)
        ->and(SalesController::$lines)->toBe(['L1', 'L2']);

    $summary = app(QueryEngine::class)->summary($combined, [], $this->admin);
    expect($summary['rows'])->toBe(4)
        ->and($summary['totals'])->toBe(['visits' => 24, 'sales' => 1800.5]);

    // Cached: paging and totals do not run the reports again.
    app(QueryEngine::class)->rows($combined, [], $this->admin, 2, 2);
    expect(VisitStatsController::$seen)->toHaveCount(1);
});

it('runs per division and refuses reports that do not support it', function () use ($combined) {
    $division = ['type' => 'combined', 'level' => 'division', 'fields' => [
        ['base' => 'division', 'as' => 'division'],
        ['report' => 'visit_statistics', 'field' => 'total_actual', 'as' => 'visits'],
    ]];
    $rows = app(QueryEngine::class)->rows($division, [], $this->admin, 0, 10)['rows'];
    expect($rows)->toBe([['division' => 'Cairo', 'visits' => 17]])
        ->and(VisitStatsController::$seen[0]['payload']['filter'])->toBe(1);

    $error = null;
    try {
        app(QueryEngine::class)->rows(['level' => 'division'] + $combined, [], $this->admin, 0, 10);
    } catch (QueryException $e) {
        $error = $e;
    }
    expect($error?->errorCode)->toBe('LEVEL_UNSUPPORTED')->and($error->path)->toBe('fields.3.report');
});

it('needs each report\'s own permission', function () use ($combined) {
    $keys = array_column($this->actingAs($this->rep)->getJson('/api/report-generator/combine/reports')->assertOk()->json('data'), 'key');
    expect($keys)->toBe(['visit_statistics']);

    $this->actingAs($this->rep)->postJson('/api/report-generator/query/rows', ['definition' => $combined])
        ->assertStatus(403)->assertJsonPath('code', 'DATASET_FORBIDDEN')->assertJsonPath('path', 'fields.3.report');
});

it('discovers the columns a report offers', function () {
    $fields = $this->actingAs($this->admin)->getJson('/api/report-generator/combine/reports/visit_statistics/fields?level=employee')->assertOk()->json('data');
    expect(array_column($fields, 'type', 'key'))->toBe(['total_actual' => 'integer', 'avg_visits' => 'decimal']);

    $sales = $this->actingAs($this->admin)->getJson('/api/report-generator/combine/reports/sales/fields?level=employee&lines[]=1')->assertOk()->json('data');
    expect(array_column($sales, 'type', 'key'))->toBe(['product' => 'string', 'value' => 'decimal', 'achievement' => 'percent']);
});

it('saves, publishes the usual way, runs and exports a combined report', function () use ($combined) {
    $uuid = $this->actingAs($this->admin)->postJson('/api/report-generator/reports', ['name' => 'Visits and sales', 'definition' => $combined])
        ->assertCreated()->json('data.uuid');

    $view = $this->actingAs($this->admin)->getJson("/api/report-generator/reports/{$uuid}/view")->assertOk()->json('data');
    expect($view['type'])->toBe('combined')
        ->and(array_column($view['fields'], 'kind', 'as'))->toBe(['employee' => 'dimension', 'visits' => 'value', 'per_day' => 'value', 'sales' => 'value', 'achievement' => 'value', 'sales_per_visit' => 'value']);

    $rows = $this->actingAs($this->admin)->postJson("/api/report-generator/reports/{$uuid}/rows", ['sort' => [['field' => 'employee', 'dir' => 'asc']]])->assertOk()->json('rows');
    expect(array_column($rows, 'employee'))->toBe(['Ali', 'Mona', 'Omar', 'Sara']);

    $csv = $this->actingAs($this->admin)->post("/api/report-generator/reports/{$uuid}/export", ['format' => 'csv'])->assertOk()->streamedContent();
    expect(explode("\n", trim($csv)))->toHaveCount(5)
        ->and(explode("\n", $csv)[0])->toContain('Visits,"Visit statistics: Avg Visits"');

    // Saving checks the definition.
    $this->actingAs($this->admin)->postJson('/api/report-generator/reports', ['name' => 'Bad', 'definition' => ['type' => 'combined', 'level' => 'employee', 'fields' => [
        ['formula' => 'nope * 2', 'as' => 'x'],
    ]]])->assertStatus(422)->assertJsonPath('code', 'UNKNOWN_FIELD');
});

it('reports which report failed', function () use ($combined) {
    SalesController::$fail = true;
    $this->actingAs($this->admin)->postJson('/api/report-generator/query/rows', ['definition' => $combined])
        ->assertStatus(422)->assertJsonPath('code', 'REPORT_FAILED')->assertJsonPath('details.report', 'sales');
});
