<?php

use Abdalla\ReportGenerator\Export\ReportWriter;
use Abdalla\ReportGenerator\Facades\ReportGenerator;
use Abdalla\ReportGenerator\Query\QueryEngine;
use Abdalla\ReportGenerator\Security\ScopeContext;
use Abdalla\ReportGenerator\Tests\Support\EngineFixture;
use Illuminate\Auth\GenericUser;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\Gate;

beforeEach(function () {
    $this->artisan('migrate')->assertSuccessful();
    config()->set('cache.default', 'array');
    Gate::define('report-generator.report.view-all', fn ($user) => (int) $user->id === 1);
    Gate::define('report-generator.report.create', fn ($user) => true);
    Gate::define('report-generator.report.export', fn ($user) => (int) $user->id === 1);
    $this->admin = new GenericUser(['id' => 1]);
});

afterEach(function () {
    foreach (dataConnections() as $connection) {
        EngineFixture::drop($connection);
    }
});

$perRep = ['root' => 'visits', 'fields' => [
    ['ref' => 'users.name', 'as' => 'rep'],
    ['ref' => 'visits.__rows', 'agg' => 'count', 'as' => 'visits'],
    ['ref' => 'visits.amount', 'agg' => 'sum', 'as' => 'amount'],
    ['ref' => 'visits.amount', 'agg' => 'max', 'as' => 'biggest'],
    ['ref' => 'visits.duration_min', 'agg' => 'avg', 'as' => 'avg_minutes'],
    ['formula' => 'amount / visits', 'as' => 'per_visit'],
], 'sort' => [['field' => 'rep', 'dir' => 'asc']]];

it('counts the whole report and totals what adds up', function (string $connection) use ($perRep) {
    EngineFixture::create($connection);
    EngineFixture::publish($connection);
    $engine = app(QueryEngine::class);

    $summary = $engine->summary($perRep, [], $this->admin);
    expect($summary['rows'])->toBe(4)
        ->and(array_keys($summary['totals']))->toBe(['visits', 'amount', 'biggest'])
        ->and($summary['totals']['visits'])->toBe(8)
        ->and(round($summary['totals']['amount'], 2))->toBe(430.5)
        ->and(round($summary['totals']['biggest'], 2))->toBe(200.0);

    $detail = $engine->summary(['root' => 'visits', 'fields' => [
        ['ref' => 'visits.status', 'as' => 'status'],
        ['ref' => 'visits.amount', 'as' => 'amount'],
        ['ref' => 'visits.id', 'as' => 'id'],
    ], 'context' => ['period' => ['2026-01-01', '2026-01-31']]], [], $this->admin);
    expect($detail['rows'])->toBe(3)
        ->and(array_keys($detail['totals']))->toBe(['amount'])
        ->and(round($detail['totals']['amount'], 2))->toBe(160.0);

    // Subtotal rows are not counted twice (ROLLUP: MySQL and PostgreSQL).
    if ($connection === 'testing') {
        return;
    }
    $withSubtotals = $engine->summary(['subtotals' => true] + $perRep, [], $this->admin);
    expect($withSubtotals['rows'])->toBe(4)->and($withSubtotals['totals']['visits'])->toBe(8);
})->with(dataConnections());

it('streams every row of a report', function (string $connection) {
    EngineFixture::create($connection);
    EngineFixture::publish($connection);
    config()->set('report-generator.exports.chunk', 100);

    $result = app(QueryEngine::class)->stream(['root' => 'visits', 'fields' => [
        ['ref' => 'visits.id', 'as' => 'id'],
        ['ref' => 'users.name', 'as' => 'rep'],
        ['ref' => 'visits.amount', 'as' => 'amount'],
    ], 'sort' => [['field' => 'id', 'dir' => 'asc']]], [], $this->admin);

    $rows = iterator_to_array($result['rows'], false);
    expect(array_column($result['columns'], 'alias'))->toBe(['id', 'rep', 'amount'])
        ->and(array_column($rows, 'id'))->toBe([1, 2, 3, 4, 5, 6, 7, 8])
        ->and($rows[0]['amount'])->toBe(100.0);

    // The connection still works normally afterwards (buffering restored, cursor closed).
    expect((int) \Illuminate\Support\Facades\DB::connection($connection)->table('visits')->count())->toBe(8);
})->with(dataConnections());

it('exports CSV with labels and a byte order mark, and Excel files', function () use ($perRep) {
    EngineFixture::create('testing');
    EngineFixture::publish('testing');

    $csv = $this->actingAs($this->admin)->post('/api/report-generator/query/export', ['definition' => $perRep, 'format' => 'csv', 'name' => 'Visits / rep'])
        ->assertOk()
        ->assertHeader('Content-Type', 'text/csv; charset=UTF-8');
    expect($csv->headers->get('Content-Disposition'))->toContain('Visits rep '.date('Y-m-d').'.csv');
    $lines = explode("\n", trim($csv->streamedContent()));
    expect($lines[0])->toStartWith("\xEF\xBB\xBF")
        ->and(substr($lines[0], 3))->toBe('Employee,"Number of visits",Amount,Amount,"Duration (minutes)",per_visit')
        ->and($lines)->toHaveCount(5)
        ->and($lines[1])->toStartWith(',1,10,10,5');

    if (! class_exists(\ZipArchive::class)) {
        $this->actingAs($this->admin)->postJson('/api/report-generator/query/export', ['definition' => $perRep, 'format' => 'xlsx'])->assertStatus(422)->assertJsonPath('code', 'EXPORT_UNAVAILABLE');

        return;
    }
    $xlsx = $this->actingAs($this->admin)->post('/api/report-generator/query/export', ['definition' => $perRep, 'format' => 'xlsx'])->assertOk();
    $file = tempnam(sys_get_temp_dir(), 'rg').'.xlsx';
    file_put_contents($file, $xlsx->streamedContent());
    $reader = new \OpenSpout\Reader\XLSX\Reader();
    $reader->open($file);
    $read = [];
    foreach ($reader->getSheetIterator() as $sheet) {
        foreach ($sheet->getRowIterator() as $row) {
            $read[] = $row->toArray();
        }
    }
    $reader->close();
    unlink($file);
    expect($read)->toHaveCount(5)->and($read[2][0])->toBe('Ali')->and($read[2][1])->toBe(3);

    // Exporting needs the export ability.
    $this->actingAs(new GenericUser(['id' => 2]))->post('/api/report-generator/query/export', ['definition' => $perRep, 'format' => 'csv'])->assertForbidden();
});

it('exports and totals a saved report with the viewer choices', function () use ($perRep) {
    EngineFixture::create('testing');
    EngineFixture::publish('testing');
    $uuid = $this->actingAs($this->admin)->postJson('/api/report-generator/reports', ['name' => 'Per rep', 'definition' => $perRep])->json('data.uuid');

    $this->actingAs($this->admin)->postJson("/api/report-generator/reports/{$uuid}/summary", ['context' => ['period' => ['2026-03-01', '2026-03-31']]])
        ->assertOk()->assertJsonPath('rows', 1)->assertJsonPath('totals.visits', 3);

    $csv = $this->actingAs($this->admin)->post("/api/report-generator/reports/{$uuid}/export", ['format' => 'csv', 'context' => ['period' => ['2026-03-01', '2026-03-31']]])->assertOk();
    expect(explode("\n", trim($csv->streamedContent())))->toHaveCount(2);
});

it('lists report filter choices the user may see', function () {
    EngineFixture::create('testing');
    EngineFixture::publish('testing');
    config()->set('report-generator.context.lines', ['topic' => 'lines', 'label' => 'name']);
    config()->set('report-generator.context.employees', ['topic' => 'users', 'label' => 'name']);
    ReportGenerator::unrestricted(['lines']);

    $get = fn ($user, string $uri) => $this->actingAs($user)->getJson('/api/report-generator/context/'.$uri)->assertOk()->json('data');

    expect(array_column($get($this->admin, 'lines'), 'label'))->toBe(['North', 'South'])
        ->and(array_column($get($this->admin, 'employees'), 'label'))->toBe(['Ali', 'Mona', 'Omar'])
        ->and(array_column($get($this->admin, 'employees?lines[]=1'), 'label'))->toBe(['Ali', 'Mona'])
        ->and(array_column($get($this->admin, 'employees?search=om'), 'id'))->toBe([3])
        ->and($get($this->admin, 'divisions'))->toBe([]);

    ReportGenerator::scopeUsing(fn (Builder $q, ScopeContext $ctx) => $q->where($ctx->employeeColumn(), 3));
    expect(array_column($get(new GenericUser(['id' => 3]), 'employees'), 'label'))->toBe(['Omar']);

    $this->actingAs($this->admin)->getJson('/api/report-generator/context/nope')->assertNotFound();
});

it('keeps exported text from running as an Excel formula', function () {
    $writer = new ReportWriter();
    $out = fopen('php://memory', 'w+');
    $writer->csv(
        [['alias' => 'name', 'label' => 'Name'], ['alias' => 'n', 'label' => 'N']],
        [['name' => '=HYPERLINK("http://x","click")', 'n' => -5], ['name' => '+1 555', 'n' => 3], ['name' => '@SUM(A1)', 'n' => '-2.5'], ['name' => 'Dr Ali', 'n' => 1]],
        $out,
    );
    rewind($out);
    $lines = explode("\n", trim(substr(stream_get_contents($out), 3)));

    expect($lines[1])->toBe("\"'=HYPERLINK(\"\"http://x\"\",\"\"click\"\")\",-5")
        ->and($lines[2])->toBe("\"'+1 555\",3")
        ->and($lines[3])->toBe("'@SUM(A1),-2.5")
        ->and($lines[4])->toBe('"Dr Ali",1');
});

it('counts an export as a running report', function () {
    EngineFixture::create('testing');
    EngineFixture::publish('testing');
    config()->set('report-generator.limits.max_concurrent_per_user', 1);
    config()->set('report-generator.limits.queue_wait_ms', 200);

    $result = app(QueryEngine::class)->stream(['root' => 'visits', 'fields' => [['ref' => 'visits.id', 'as' => 'id']]], [], $this->admin);
    $first = $result['rows']->current(); // the export is being written

    $error = null;
    try {
        app(QueryEngine::class)->rows(['root' => 'visits', 'fields' => [['ref' => 'visits.__rows', 'agg' => 'count', 'as' => 'n']]], [], $this->admin, 0, 10);
    } catch (\Abdalla\ReportGenerator\Query\QueryException $e) {
        $error = $e;
    }
    iterator_to_array($result['rows']); // finish the export: the slot is released

    expect($first['id'])->toBe(1)
        ->and($error?->errorCode)->toBe('TOO_MANY_QUERIES')
        ->and((int) app(QueryEngine::class)->rows(['root' => 'visits', 'fields' => [['ref' => 'visits.__rows', 'agg' => 'count', 'as' => 'n']]], [], $this->admin, 0, 10)['rows'][0]['n'])->toBe(8);
});
