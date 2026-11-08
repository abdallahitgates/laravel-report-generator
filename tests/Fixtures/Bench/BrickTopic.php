<?php

namespace Abdalla\ReportGenerator\Tests\Fixtures\Bench;

use Abdalla\ReportGenerator\Topics\Field;
use Abdalla\ReportGenerator\Topics\Relation;
use Abdalla\ReportGenerator\Topics\Topic;

/** Benchmark topic over the bricks table (benchmarks/schema.sql). */
class BrickTopic extends Topic
{
    public string $model = BrickModel::class;

    public ?string $key = 'bricks';

    public function fields(): array
    {
        return [
            Field::id('id', 'ID'),
            Field::text('name', 'Brick'),
            Field::text('region', 'Region'),
        ];
    }

    public function relations(): array
    {
        return [
            Relation::hasMany('doctors', 'Doctors', DoctorTopic::class, 'brick_id'),
            Relation::hasMany('sales', 'Sales', SaleTopic::class, 'brick_id'),
        ];
    }
}
