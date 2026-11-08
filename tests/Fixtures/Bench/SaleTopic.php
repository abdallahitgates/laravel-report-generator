<?php

namespace Abdalla\ReportGenerator\Tests\Fixtures\Bench;

use Abdalla\ReportGenerator\Topics\Field;
use Abdalla\ReportGenerator\Topics\Relation;
use Abdalla\ReportGenerator\Topics\Topic;

/** Benchmark topic over the sales table (benchmarks/schema.sql). */
class SaleTopic extends Topic
{
    public string $model = SaleModel::class;

    public ?string $key = 'sales';

    public ?string $dateColumn = 'sale_date';

    public function fields(): array
    {
        return [
            Field::id('id', 'ID'),
            Field::id('product_id', 'Product ID'),
            Field::id('brick_id', 'Brick ID'),
            Field::date('sale_date', 'Sale date'),
            Field::number('quantity', 'Quantity'),
            Field::money('value', 'Value'),
        ];
    }

    public function relations(): array
    {
        return [
            Relation::belongsTo('product', 'Product', ProductTopic::class, 'product_id'),
            Relation::belongsTo('brick', 'Brick', BrickTopic::class, 'brick_id'),
        ];
    }
}
