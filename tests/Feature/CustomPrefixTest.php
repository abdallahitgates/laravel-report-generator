<?php

use Illuminate\Support\Facades\Schema;

beforeEach(function () {
    config()->set('report-generator.table_prefix', 'reporting_');
});

it('honours a custom table prefix', function () {
    $this->artisan('migrate')->assertSuccessful();

    expect(Schema::hasTable('reporting_reports'))->toBeTrue()
        ->and(Schema::hasTable('rg_reports'))->toBeFalse();
});
