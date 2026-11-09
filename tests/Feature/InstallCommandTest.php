<?php

use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Schema;

afterEach(function () {
    File::delete(config_path('report-generator.php'));
});

it('publishes the config file', function () {
    File::delete(config_path('report-generator.php'));

    $this->artisan('report-generator:install')->assertSuccessful();

    expect(File::exists(config_path('report-generator.php')))->toBeTrue();
});

it('runs the migrations with --migrate', function () {
    $this->artisan('report-generator:install', ['--migrate' => true])->assertSuccessful();

    expect(Schema::hasTable('rg_reports'))->toBeTrue();
});

it('prunes the old query log, finished runs and long-deleted reports', function () {
    $this->artisan('migrate')->assertSuccessful();
    $db = app('db')->connection();
    $db->table('rg_query_log')->insert([
        ['sql_hash' => 'a', 'duration_ms' => 1, 'created_at' => now()->subDays(100)],
        ['sql_hash' => 'b', 'duration_ms' => 1, 'created_at' => now()->subDays(5)],
    ]);
    $old = \Abdalla\ReportGenerator\Models\Report::query()->create(['name' => 'Old', 'definition' => ['root' => 'x', 'fields' => []]]);
    $old->delete();
    \Abdalla\ReportGenerator\Models\Report::withTrashed()->whereKey($old->id)->update(['deleted_at' => now()->subDays(40)]);
    $recent = \Abdalla\ReportGenerator\Models\Report::query()->create(['name' => 'Recent', 'definition' => ['root' => 'x', 'fields' => []]]);
    $recent->delete();

    $this->artisan('report-generator:prune')->assertSuccessful()->expectsOutputToContain('Pruned 1 query log entries');

    expect($db->table('rg_query_log')->pluck('sql_hash')->all())->toBe(['b'])
        ->and(\Abdalla\ReportGenerator\Models\Report::withTrashed()->pluck('name')->all())->toBe(['Recent']);
});
