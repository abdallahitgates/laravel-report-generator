<?php

use Illuminate\Auth\GenericUser;
use Illuminate\Support\Facades\Gate;
use Abdalla\ReportGenerator\Facades\ReportGenerator;
use Abdalla\ReportGenerator\Tests\Fixtures\VisitsSource;

it('rejects guests', function () {
    $this->getJson('/api/report-generator/meta')->assertUnauthorized();
});

it('returns version, abilities, sources and limits', function () {
    Gate::define('report-generator.report.create', fn () => true);
    ReportGenerator::source(VisitsSource::class);

    $this->actingAs(new GenericUser(['id' => 7]))
        ->getJson('/api/report-generator/meta')
        ->assertOk()
        ->assertJsonPath('version', \Abdalla\ReportGenerator\ReportGenerator::VERSION)
        ->assertJsonPath('abilities.createReports', true)
        ->assertJsonPath('abilities.manageModel', false)
        ->assertJsonPath('sources.0.key', 'visits_summary')
        ->assertJsonPath('limits.preview_page_size', 200);
});
