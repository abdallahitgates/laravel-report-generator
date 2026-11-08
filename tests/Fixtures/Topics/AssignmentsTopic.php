<?php

namespace Abdalla\ReportGenerator\Tests\Fixtures\Topics;

use Abdalla\ReportGenerator\Topics\Field;
use Abdalla\ReportGenerator\Topics\Topic;

/** Rows valid from one date to another (open-ended when to_date is empty). */
class AssignmentsTopic extends Topic
{
    public string $model = Assignment::class;

    public ?string $employeeColumn = 'user_id';

    public ?string $validFromColumn = 'from_date';

    public ?string $validToColumn = 'to_date';

    public function fields(): array
    {
        return [
            Field::id('id', 'ID'),
            Field::id('user_id', 'Employee ID'),
            Field::date('from_date', 'From'),
            Field::datetime('to_date', 'To'),
        ];
    }
}
