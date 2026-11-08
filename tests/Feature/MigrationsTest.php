<?php

use Illuminate\Support\Facades\Schema;
use Abdalla\ReportGenerator\Support\Tables;

it('creates every package table with the prefix', function () {
    $this->artisan('migrate')->assertSuccessful();

    foreach (Tables::ALL as $table) {
        expect(Schema::hasTable('rg_'.$table))->toBeTrue("Missing table rg_{$table}");
    }
});

it('rolls back cleanly', function () {
    $this->artisan('migrate')->assertSuccessful();
    $this->artisan('migrate:rollback')->assertSuccessful();

    foreach (Tables::ALL as $table) {
        expect(Schema::hasTable('rg_'.$table))->toBeFalse();
    }
});

it('drops the tables of the scanned model v0.1 used', function () {
    $this->artisan('migrate')->assertSuccessful();

    foreach (['datasets', 'fields', 'relationships', 'model_versions', 'schema_snapshots'] as $table) {
        expect(Schema::hasTable('rg_'.$table))->toBeFalse("rg_{$table} should be gone");
    }
});
