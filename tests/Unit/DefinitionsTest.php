<?php

use Abdalla\ReportGenerator\Definitions\FieldDefinition;
use Abdalla\ReportGenerator\Definitions\ParameterDefinition;
use Abdalla\ReportGenerator\Enums\Aggregation;
use Abdalla\ReportGenerator\Enums\Cardinality;
use Abdalla\ReportGenerator\Enums\DataType;
use Abdalla\ReportGenerator\Enums\FieldRole;

it('gives numeric measures every aggregation and text measures only counts', function () {
    expect(FieldDefinition::measure('amount', 'Amount')->aggregations)->toBe(Aggregation::cases())
        ->and((new FieldDefinition('code', 'Code', DataType::String, FieldRole::Measure))->aggregations)
        ->toBe([Aggregation::Count, Aggregation::CountDistinct]);
});

it('treats date dimensions as time fields', function () {
    expect(FieldDefinition::dimension('visit_date', 'Date', DataType::Date)->role)->toBe(FieldRole::Time)
        ->and(FieldDefinition::dimension('name', 'Name')->aggregations)->toBe([]);
});

it('serializes to the shape the Vue package expects', function () {
    expect(json_decode(json_encode(FieldDefinition::measure('amount', 'Amount', DataType::Decimal, '0,0.00')), true))
        ->toBe([
            'key' => 'amount', 'label' => 'Amount', 'type' => 'decimal', 'role' => 'measure', 'format' => '0,0.00',
            'aggregations' => ['sum', 'avg', 'min', 'max', 'count', 'count_distinct'], 'description' => null,
        ]);
});

it('rejects unsafe field keys', function () {
    FieldDefinition::dimension('name; drop table', 'Bad');
})->throws(InvalidArgumentException::class);

it('rejects unknown parameter types', function () {
    new ParameterDefinition('x', 'X', 'sql');
})->throws(InvalidArgumentException::class);

it('knows which joins fan out', function () {
    expect(Cardinality::OneToMany->fansOut())->toBeTrue()
        ->and(Cardinality::ManyToOne->fansOut())->toBeFalse()
        ->and(Cardinality::OneToMany->inverse())->toBe(Cardinality::ManyToOne)
        ->and(Aggregation::CountDistinct->isAdditive())->toBeFalse();
});
