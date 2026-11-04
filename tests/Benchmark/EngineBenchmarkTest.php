<?php

use Abdalla\ReportGenerator\Models\Relationship;
use Abdalla\ReportGenerator\Query\QueryEngine;
use Abdalla\ReportGenerator\Schema\SchemaScanner;
use Abdalla\ReportGenerator\Semantic\ModelPublisher;
use Abdalla\ReportGenerator\Semantic\Published\SemanticModelRepository;
use Illuminate\Auth\GenericUser;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;

/*
 * Opt-in: runs against the benchmark database (benchmarks/seed.sh).
 *
 *   RG_BENCH_MYSQL_HOST=127.0.0.1 vendor/bin/pest tests/Benchmark
 *
 * Prints cold (no result cache) and warm timings per report. Timings depend
 * on the machine and buffer pool, so only correctness is asserted.
 */

beforeEach(function () {
    if (! getenv('RG_BENCH_MYSQL_HOST')) {
        $this->markTestSkipped('Set RG_BENCH_MYSQL_HOST to run the benchmark.');
    }

    $this->artisan('migrate')->assertSuccessful();
    config()->set('cache.default', 'array');
    config()->set('report-generator.data_connection', 'mysql_bench');
    config()->set('report-generator.schema.eloquent.enabled', false);
    config()->set('report-generator.limits.preview_timeout_ms', 120_000);
    Gate::define('report-generator.report.view-all', fn () => true);
});

it('runs typical reports on the benchmark dataset', function () {
    $started = microtime(true);
    $summary = app(SchemaScanner::class)->scan();
    $scanMs = (int) round((microtime(true) - $started) * 1000);
    Relationship::query()->update(['status' => Relationship::CONFIRMED]);
    app(ModelPublisher::class)->publish();
    app(SemanticModelRepository::class)->forget();

    $visits = (int) DB::connection('mysql_bench')->table('visits')->count();
    $engine = app(QueryEngine::class);
    $user = new GenericUser(['id' => 1]);
    $march = ['field' => 'visits.visit_date', 'op' => 'between', 'value' => ['2025-03-01', '2025-03-31']];

    $reports = [
        'Detail page, one month of visits' => [['root' => 'visits', 'fields' => [
            ['ref' => 'visits.visit_date', 'as' => 'date'],
            ['ref' => 'users.name', 'as' => 'rep'],
            ['ref' => 'doctors.name', 'as' => 'doctor'],
            ['ref' => 'visits.status', 'as' => 'status'],
        ], 'filters' => ['and' => [$march]]], null],
        'Visits by line and month, whole table' => [['root' => 'visits', 'fields' => [
            ['ref' => 'lines.name', 'as' => 'line'],
            ['ref' => 'visits.visit_date', 'grain' => 'month', 'as' => 'month'],
            ['ref' => 'visits.__rows', 'agg' => 'count', 'as' => 'visits'],
            ['ref' => 'visits.duration_min', 'agg' => 'avg', 'as' => 'avg_minutes'],
        ]], null],
        'Visits by speciality, one month' => [['root' => 'visits', 'fields' => [
            ['ref' => 'specialities.name', 'as' => 'speciality'],
            ['ref' => 'visits.__rows', 'agg' => 'count', 'as' => 'visits'],
        ], 'filters' => ['and' => [$march]]], null],
        'Grid top level: sales by line (products), whole table' => [['root' => 'sales', 'fields' => [
            ['ref' => 'lines.name', 'as' => 'line'],
            ['ref' => 'sales.sale_date', 'grain' => 'month', 'as' => 'month'],
            ['ref' => 'sales.value', 'agg' => 'sum', 'as' => 'value'],
        ]], ['columns' => ['line', 'month'], 'keys' => []]],
        'Grid drill-down: one line, one month' => [['root' => 'sales', 'fields' => [
            ['ref' => 'lines.name', 'as' => 'line'],
            ['ref' => 'sales.sale_date', 'grain' => 'month', 'as' => 'month'],
            ['ref' => 'sales.value', 'agg' => 'sum', 'as' => 'value'],
        ]], ['columns' => ['line', 'month'], 'keys' => ['Line 3', '2025-03-01']]],
        'Doctors with a missed visit in March (EXISTS)' => [['root' => 'doctors', 'fields' => [
            ['ref' => 'specialities.name', 'as' => 'speciality'],
            ['ref' => 'doctors.__rows', 'agg' => 'count', 'as' => 'doctors'],
        ], 'filters' => ['and' => [$march, ['field' => 'visits.status', 'op' => '=', 'value' => 'missed']]]], null],
        'Per brick: visits and sales (multi-fact), one month' => [['root' => 'bricks', 'fields' => [
            ['ref' => 'bricks.region', 'as' => 'region'],
            ['ref' => 'sales.value', 'agg' => 'sum', 'as' => 'sales'],
            ['ref' => 'visits.__rows', 'agg' => 'count', 'as' => 'visits'],
        ], 'filters' => ['and' => [
            ['field' => 'sales.sale_date', 'op' => 'between', 'value' => ['2025-03-01', '2025-03-31']],
            ['field' => 'visits.visit_date', 'op' => 'between', 'value' => ['2025-03-01', '2025-03-31']],
        ]]], null],
    ];

    $lines = [sprintf('%-58s %10s %10s %8s %12s  %s', 'Report', 'cold ms', 'warm ms', 'rows', 'est. read', 'mode')];
    foreach ($reports as $name => [$definition, $groups]) {
        $cold = $engine->rows($definition, [], $user, 0, 200, $groups);
        $warm = $engine->rows($definition, [], $user, 0, 200, $groups);
        expect($cold['rows'])->not->toBeEmpty("{$name} returned nothing");
        expect($warm['meta']['cached'])->toBeTrue();
        $lines[] = sprintf('%-58s %10d %10d %8d %12s  %s', $name, $cold['meta']['duration_ms'], $warm['meta']['duration_ms'],
            count($cold['rows']), number_format($cold['meta']['estimated_rows'] ?? 0), $cold['meta']['mode']);
    }

    $values = microtime(true);
    $found = $engine->values('doctors.name', 'Dr 1234', 20, $user);
    $lines[] = sprintf('%-58s %10d %10s %8d', 'Filter values: search doctors by name', (microtime(true) - $values) * 1000, '-', count($found));

    fwrite(STDERR, PHP_EOL.sprintf('Benchmark: %s visits, scan %d ms (%d tables)', number_format($visits), $scanMs, $summary->tables).PHP_EOL.implode(PHP_EOL, $lines).PHP_EOL);
});
