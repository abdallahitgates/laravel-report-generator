<?php

use Illuminate\Auth\GenericUser;
use Illuminate\Database\Query\Builder;
use Abdalla\ReportGenerator\Facades\ReportGenerator;
use Abdalla\ReportGenerator\Security\PolicyContext;
use Abdalla\ReportGenerator\Security\PolicyRegistry;
use Abdalla\ReportGenerator\Tests\Fixtures\OwnRowsPolicy;

function policyQuery(): Builder
{
    return app('db')->connection()->query()->from('visits as t0');
}

it('applies closure policies against the engine alias', function () {
    ReportGenerator::policy('visits', fn (Builder $q, PolicyContext $ctx) => $q->whereIn($ctx->column('line_id'), [1, 2]));

    $query = policyQuery();
    app(PolicyRegistry::class)->apply($query, new PolicyContext('visits', 't0', null));

    expect($query->toSql())->toBe('select * from "visits" as "t0" where ("t0"."line_id" in (?, ?))')
        ->and($query->getBindings())->toBe([1, 2]);
});

it('isolates each policy so an OR cannot widen another policy', function () {
    ReportGenerator::policy('visits', fn (Builder $q, PolicyContext $ctx) => $q->where($ctx->column('a'), 1)->orWhere($ctx->column('b'), 2));
    ReportGenerator::policy('visits', OwnRowsPolicy::class);

    $query = policyQuery()->where('t0.status', 'done');
    app(PolicyRegistry::class)->apply($query, new PolicyContext('visits', 't0', new GenericUser(['id' => 9])));

    expect($query->toSql())->toBe(
        'select * from "visits" as "t0" where "t0"."status" = ? and ("t0"."a" = ? or "t0"."b" = ?) and ("t0"."user_id" = ?)'
    )->and($query->getBindings())->toBe(['done', 1, 2, 9]);
});

it('leaves datasets without policies untouched', function () {
    $query = policyQuery();
    app(PolicyRegistry::class)->apply($query, new PolicyContext('doctors', 't1', null));

    expect($query->wheres)->toBe([])
        ->and(app(PolicyRegistry::class)->has('doctors'))->toBeFalse();
});

it('rejects classes that are not row policies', function () {
    ReportGenerator::policy('visits', \stdClass::class);
})->throws(InvalidArgumentException::class);
