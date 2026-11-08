<?php

namespace Abdalla\ReportGenerator\Tests\Fixtures\Bench;

use Abdalla\ReportGenerator\Topics\Field;
use Abdalla\ReportGenerator\Topics\Relation;
use Abdalla\ReportGenerator\Topics\Topic;

/** Benchmark topic over the visits table (benchmarks/schema.sql). */
class VisitTopic extends Topic
{
    public string $model = VisitModel::class;

    public ?string $key = 'visits';

    public ?string $dateColumn = 'visit_date';

    public function fields(): array
    {
        return [
            Field::id('id', 'ID'),
            Field::id('user_id', 'Rep ID'),
            Field::id('doctor_id', 'Doctor ID'),
            Field::date('visit_date', 'Visit date'),
            Field::text('status', 'Status'),
            Field::integer('duration_min', 'Minutes')->totals(['sum', 'avg', 'min', 'max']),
        ];
    }

    public function relations(): array
    {
        return [
            Relation::belongsTo('rep', 'Rep', UserTopic::class, 'user_id'),
            Relation::belongsTo('doctor', 'Doctor', DoctorTopic::class, 'doctor_id'),
        ];
    }
}
