<?php

namespace Abdalla\ReportGenerator\Tests\Fixtures\Engine;

use Abdalla\ReportGenerator\Topics\Field;
use Abdalla\ReportGenerator\Topics\Relation;
use Abdalla\ReportGenerator\Topics\Topic;

class UsersTopic extends Topic
{
    public string $model = Rep::class;

    public ?string $label = 'Employees';

    public ?string $employeeColumn = 'id';

    public ?string $lineColumn = 'line_id';

    public function fields(): array
    {
        return [
            Field::count('Number of employees'),
            Field::id('id', 'Employee ID'),
            Field::text('name', 'Employee'),
            Field::id('line_id', 'Line ID'),
        ];
    }

    public function relations(): array
    {
        return [
            Relation::belongsTo('line', 'Line', LinesTopic::class, 'line_id'),
            Relation::hasMany('visits', 'Visits', VisitsTopic::class, 'user_id'),
        ];
    }
}
