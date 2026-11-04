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
