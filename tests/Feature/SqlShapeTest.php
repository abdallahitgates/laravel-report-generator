<?php

use Abdalla\ReportGenerator\Query\Compiler\Dialects\MySqlDialect;
use Abdalla\ReportGenerator\Query\Compiler\Dialects\PostgresDialect;
use Abdalla\ReportGenerator\Query\Compiler\QueryCompiler;
use Abdalla\ReportGenerator\Query\Definition\DefinitionParser;
use Abdalla\ReportGenerator\Query\Planning\QueryPlanner;
use Abdalla\ReportGenerator\Semantic\Published\SemanticModelRepository;
use Abdalla\ReportGenerator\Tests\Support\EngineFixture;
use Illuminate\Database\MySqlConnection;
use Illuminate\Database\PostgresConnection;

/*
 * The SQL written for MySQL and PostgreSQL, checked without a server: the
 * model comes from the SQLite fixture, the statement is only compiled.
 */

beforeEach(function () {
    $this->artisan('migrate')->assertSuccessful();
    EngineFixture::create('testing');
    EngineFixture::publish('testing');
});

afterEach(fn () => EngineFixture::drop('testing'));

function compileFor(string $driver, array $definition): string
{
    $connection = $driver === 'mysql'
        ? new MySqlConnection(fn () => null, 'crm', '', ['driver' => 'mysql', 'name' => 'fake'])
        : new PostgresConnection(fn () => null, 'crm', '', ['driver' => 'pgsql', 'name' => 'fake']);
    $dialect = $driver === 'mysql' ? new MySqlDialect() : new PostgresDialect();

    $plan = app(QueryPlanner::class)->plan(
        app(DefinitionParser::class)->parse($definition),
        app(SemanticModelRepository::class)->current(),
        fn () => null,
    );

    return app(QueryCompiler::class)->compile($plan, $connection, $dialect, null, 15000)->sql();
}

$byLine = [
    'root' => 'visits',
    'fields' => [
        ['ref' => 'lines.name', 'as' => 'line', 'path' => null],
        ['ref' => 'visits.visit_date', 'grain' => 'month', 'as' => 'month'],
        ['ref' => 'visits.amount', 'agg' => 'sum', 'as' => 'amount'],
    ],
];

it('quotes every identifier, including reserved words, and caps MySQL execution time', function () use ($byLine) {
    $byLine['fields'][0]['path'] = [EngineFixture::relationshipId('visits', 'user_id'), EngineFixture::relationshipId('users', 'line_id')];

    $sql = compileFor('mysql', $byLine);

    expect($sql)->toContain('select /*+ MAX_EXECUTION_TIME(15000) */ `t2`.`name` as `line`')
        ->toContain('left join `lines` as `t2` on `t1`.`line_id` = `t2`.`id`')
        // the deleted rep's visits stay; only the rep disappears from the join
        ->toContain('left join `users` as `t1` on `t0`.`user_id` = `t1`.`id` and `t1`.`deleted_at` is null')
        ->toContain("CAST(DATE_FORMAT(`t0`.`visit_date`, '%Y-%m-01') AS DATE)")
        ->toContain('group by `t2`.`name`, CAST(DATE_FORMAT(')
        ->and(substr_count($sql, 'MAX_EXECUTION_TIME'))->toBe(1);
});

it('writes PostgreSQL date buckets and leaves the timeout to SET LOCAL', function () use ($byLine) {
    $byLine['fields'][0]['path'] = [EngineFixture::relationshipId('visits', 'user_id'), EngineFixture::relationshipId('users', 'line_id')];

    $sql = compileFor('pgsql', $byLine);

    expect($sql)->toContain('left join "lines" as "t2"')
        ->toContain("CAST(DATE_TRUNC('month', \"t0\".\"visit_date\") AS DATE)")
        ->not->toContain('MAX_EXECUTION_TIME');
});

it('stacks per-branch aggregates with UNION ALL for multi-fact reports', function () {
    $sql = compileFor('mysql', ['root' => 'doctors', 'fields' => [
        ['ref' => 'doctors.name', 'as' => 'doctor'],
        ['ref' => 'visits.__rows', 'agg' => 'count', 'as' => 'visits'],
        ['ref' => 'sales.value', 'agg' => 'sum', 'as' => 'sales'],
    ]]);

    expect($sql)->toContain('union all')
        ->toContain('MAX(`u`.`visits`) as `visits`')
        ->toContain('MAX(`u`.`sales`) as `sales`')
        ->toContain('NULL as `sales`');
});

it('drills into a month with an index-friendly range, not a computed bucket', function () use ($byLine) {
    $definition = ['root' => 'visits', 'fields' => [
        ['ref' => 'visits.visit_date', 'grain' => 'month', 'as' => 'month'],
        ['ref' => 'visits.amount', 'agg' => 'sum', 'as' => 'amount'],
    ], 'filters' => ['and' => [['field' => 'month', 'op' => '=', 'value' => '2026-02-01']]]];

    expect(compileFor('mysql', $definition))->toContain('(`t0`.`visit_date` >= ? AND `t0`.`visit_date` < ?)');
});

it('turns a filter across a one-to-many join into EXISTS', function () {
    $sql = compileFor('pgsql', [
        'root' => 'doctors',
        'fields' => [['ref' => 'doctors.name', 'as' => 'doctor']],
        'filters' => ['and' => [['field' => 'visits.status', 'op' => '=', 'value' => 'missed']]],
    ]);

    expect($sql)->toContain('EXISTS (select 1 from "visits" as "t1" where "t1"."doctor_id" = "t0"."id"')
        ->not->toContain('join "visits"');
});
