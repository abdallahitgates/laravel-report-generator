<?php

use Abdalla\ReportGenerator\Facades\ReportGenerator;
use Abdalla\ReportGenerator\Sources\SourceRegistry;
use Abdalla\ReportGenerator\Tests\Fixtures\VisitsSource;

it('resolves registered sources lazily by key', function () {
    ReportGenerator::source(VisitsSource::class);

    $registry = app(SourceRegistry::class);

    expect($registry->has('visits_summary'))->toBeTrue()
        ->and($registry->get('visits_summary'))->toBeInstanceOf(VisitsSource::class)
        ->and(array_keys($registry->all()))->toBe(['visits_summary']);
});

it('rejects duplicate keys', function () {
    ReportGenerator::source(new VisitsSource('dup'));
    ReportGenerator::source(new VisitsSource('dup'));

    app(SourceRegistry::class)->all();
})->throws(LogicException::class, 'registered twice');

it('rejects invalid keys', function () {
    ReportGenerator::source(new VisitsSource('Bad-Key'));

    app(SourceRegistry::class)->all();
})->throws(InvalidArgumentException::class);

it('rejects classes that are not report sources', function () {
    ReportGenerator::source(\stdClass::class);
})->throws(InvalidArgumentException::class);

it('throws for unknown keys', function () {
    app(SourceRegistry::class)->get('missing');
})->throws(OutOfBoundsException::class);
