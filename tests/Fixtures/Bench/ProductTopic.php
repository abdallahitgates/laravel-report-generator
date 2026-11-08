<?php

namespace Abdalla\ReportGenerator\Tests\Fixtures\Bench;

use Abdalla\ReportGenerator\Topics\Field;
use Abdalla\ReportGenerator\Topics\Relation;
use Abdalla\ReportGenerator\Topics\Topic;

/** Benchmark topic over the products table (benchmarks/schema.sql). */
class ProductTopic extends Topic
{
    public string $model = ProductModel::class;

    public ?string $key = 'products';

    public function fields(): array
    {
        return [
            Field::id('id', 'ID'),
            Field::text('name', 'Product'),
            Field::money('unit_price', 'Unit price'),
            Field::id('line_id', 'Line ID'),
        ];
    }

    public function relations(): array
    {
        return [
            Relation::belongsTo('line', 'Line', LineTopic::class, 'line_id'),
        ];
    }
}
