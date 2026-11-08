<?php

namespace Abdalla\ReportGenerator\Tests\Fixtures\Bench;

use Abdalla\ReportGenerator\Topics\Field;
use Abdalla\ReportGenerator\Topics\Relation;
use Abdalla\ReportGenerator\Topics\Topic;

/** Benchmark topic over the specialities table (benchmarks/schema.sql). */
class SpecialityTopic extends Topic
{
    public string $model = SpecialityModel::class;

    public ?string $key = 'specialities';

    public function fields(): array
    {
        return [
            Field::id('id', 'ID'),
            Field::text('name', 'Speciality'),
        ];
    }
}
