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

it('cascades fields when a dataset is deleted', function () {
    $this->artisan('migrate')->assertSuccessful();
    $db = app('db')->connection();

    $datasetId = $db->table('rg_datasets')->insertGetId([
        'key' => 'visits', 'source_type' => 'table', 'source_name' => 'visits', 'label' => 'Visits',
    ]);
    $db->table('rg_fields')->insert([
        'dataset_id' => $datasetId, 'key' => 'id', 'column' => 'id', 'label' => 'ID',
        'data_type' => 'integer', 'role' => 'key',
    ]);

    $db->table('rg_datasets')->where('id', $datasetId)->delete();

    expect($db->table('rg_fields')->count())->toBe(0);
});
