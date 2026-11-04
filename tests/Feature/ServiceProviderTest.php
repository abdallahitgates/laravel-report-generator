<?php

use Illuminate\Support\Facades\Route;
use Abdalla\ReportGenerator\Facades\ReportGenerator as ReportGeneratorFacade;
use Abdalla\ReportGenerator\ReportGenerator;

it('merges the package config', function () {
    expect(config('report-generator.table_prefix'))->toBe('rg_')
        ->and(config('report-generator.route.prefix'))->toBe('api/report-generator')
        ->and(config('report-generator.route.middleware'))->toBe(['api', 'auth']);
});

it('binds the manager as a singleton behind the facade', function () {
    expect(app(ReportGenerator::class))->toBe(app(ReportGenerator::class))
        ->and(ReportGeneratorFacade::getFacadeRoot())->toBe(app(ReportGenerator::class));
});

it('registers the api routes with the configured prefix and middleware', function () {
    $route = Route::getRoutes()->getByName('report-generator.meta');

    expect($route)->not->toBeNull()
        ->and($route->uri())->toBe('api/report-generator/meta')
        ->and($route->gatherMiddleware())->toContain('api', 'auth');
});
