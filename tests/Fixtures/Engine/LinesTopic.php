<?php

namespace Abdalla\ReportGenerator\Tests\Fixtures\Engine;

use Abdalla\ReportGenerator\Topics\Field;
use Abdalla\ReportGenerator\Topics\Relation;
use Abdalla\ReportGenerator\Topics\Topic;

class LinesTopic extends Topic
{
    public string $model = Line::class;

    public ?string $group = 'Structure';

    public ?string $lineColumn = 'id';

    public function fields(): array
    {
        return [
            Field::count('Number of lines'),
            Field::id('id', 'Line ID'),
            Field::text('name', 'Line'),
        ];
    }

    public function relations(): array
    {
        return [
            Relation::hasMany('users', 'Employees', UsersTopic::class, 'line_id'),
            Relation::hasMany('doctors', 'Doctors', DoctorsTopic::class, 'line_id'),
        ];
    }
}
