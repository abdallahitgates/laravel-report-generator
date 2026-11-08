<?php

namespace Abdalla\ReportGenerator\Tests\Fixtures\Engine;

use Abdalla\ReportGenerator\Topics\Field;
use Abdalla\ReportGenerator\Topics\Relation;
use Abdalla\ReportGenerator\Topics\Topic;

class VisitsTopic extends Topic
{
    public string $model = Visit::class;

    public ?string $group = 'Visits';

    public ?string $employeeColumn = 'user_id';

    public ?string $dateColumn = 'visit_date';

    public function fields(): array
    {
        return [
            Field::count('Number of visits'),
            Field::id('id', 'Visit ID'),
            Field::id('user_id', 'Employee ID'),
            Field::id('doctor_id', 'Doctor ID'),
            Field::date('visit_date', 'Visit date'),
            Field::enum('status', 'Status', ['done' => 'Done', 'missed' => 'Missed']),
            Field::integer('duration_min', 'Duration (minutes)')->totals(['sum', 'avg', 'min', 'max']),
            Field::money('amount', 'Amount'),
        ];
    }

    public function relations(): array
    {
        return [
            Relation::belongsTo('employee', 'Employee', UsersTopic::class, 'user_id'),
            Relation::belongsTo('doctor', 'Doctor', DoctorsTopic::class, 'doctor_id'),
        ];
    }
}
