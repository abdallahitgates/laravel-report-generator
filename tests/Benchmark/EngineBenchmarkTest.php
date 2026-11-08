<?php

use Abdalla\ReportGenerator\Export\ReportWriter;
use Abdalla\ReportGenerator\Facades\ReportGenerator;
use Abdalla\ReportGenerator\Query\QueryEngine;
use Abdalla\ReportGenerator\Tests\Fixtures\Bench;
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
    config()->set('report-generator.topics.paths', []);
    config()->set('report-generator.limits.preview_timeout_ms', 120_000);
    Gate::define('report-generator.report.view-all', fn () => true);
});

it('runs typical reports on the benchmark dataset', function () {
    ReportGenerator::topics([
        Bench\LineTopic::class, Bench\SpecialityTopic::class, Bench\BrickTopic::class, Bench\UserTopic::class,
        Bench\DoctorTopic::class, Bench\ProductTopic::class, Bench\VisitTopic::class, Bench\SaleTopic::class,
    ]);

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
        if ($cold['rows'] === []) {
            fwrite(STDERR, "\n{$name}: no rows\n");
        }
        $warm = $engine->rows($definition, [], $user, 0, 200, $groups);
        expect($cold['rows'])->not->toBeEmpty("{$name} returned nothing");
        expect($warm['meta']['cached'])->toBeTrue();
        $lines[] = sprintf('%-58s %10d %10d %8d %12s  %s', $name, $cold['meta']['duration_ms'], $warm['meta']['duration_ms'],
            count($cold['rows']), number_format($cold['meta']['estimated_rows'] ?? 0), $cold['meta']['mode']);
    }

    $values = microtime(true);
    $found = $engine->values('doctors.name', 'Dr 1234', 20, $user);
    $lines[] = sprintf('%-58s %10d %10s %8d', 'Filter values: search doctors by name', (microtime(true) - $values) * 1000, '-', count($found));

    // Count and grand totals of a whole-table aggregate.
    $summary = $engine->summary($reports['Visits by line and month, whole table'][0], [], $user);
    $lines[] = sprintf('%-58s %10d %10s %8d', 'Summary: count + totals, visits by line and month', $summary['duration_ms'], '-', $summary['rows']);

    // Every visit to CSV, streamed: memory must stay flat.
    $before = memory_get_peak_usage(true);
    $started = microtime(true);
    $result = $engine->stream(['root' => 'visits', 'fields' => [
        ['ref' => 'visits.id', 'as' => 'id'],
        ['ref' => 'visits.visit_date', 'as' => 'date'],
        ['ref' => 'users.name', 'as' => 'rep'],
        ['ref' => 'doctors.name', 'as' => 'doctor'],
        ['ref' => 'visits.status', 'as' => 'status'],
        ['ref' => 'visits.duration_min', 'as' => 'minutes'],
    ]], [], $user);
    $out = fopen('/dev/null', 'wb');
    $counted = (function () use ($result, &$exported) {
        $exported = 0;
        foreach ($result['rows'] as $row) {
            $exported++;
            yield $row;
        }
    })();
    app(ReportWriter::class)->csv($result['columns'], $counted, $out);
    fclose($out);
    $growth = (memory_get_peak_usage(true) - $before) / 1048576;
    $lines[] = sprintf('%-58s %10d %10s %8s  peak memory +%.1f MB', 'Export: every visit to CSV (streamed)', (microtime(true) - $started) * 1000, '-', number_format($exported), $growth);
    expect($exported)->toBe($visits)->and($growth)->toBeLessThan(64);

    fwrite(STDERR, PHP_EOL.sprintf('Benchmark: %s visits', number_format($visits)).PHP_EOL.implode(PHP_EOL, $lines).PHP_EOL);
});
