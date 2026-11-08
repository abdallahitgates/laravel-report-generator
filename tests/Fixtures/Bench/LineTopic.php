<?php

namespace Abdalla\ReportGenerator\Tests\Fixtures\Bench;

use Abdalla\ReportGenerator\Topics\Field;
use Abdalla\ReportGenerator\Topics\Relation;
use Abdalla\ReportGenerator\Topics\Topic;

/** Benchmark topic over the lines table (benchmarks/schema.sql). */
class LineTopic extends Topic
{
    public string $model = LineModel::class;

    public ?string $key = 'lines';

    public function fields(): array
    {
        return [
            Field::id('id', 'ID'),
            Field::text('name', 'Line'),
        ];
    }
}
