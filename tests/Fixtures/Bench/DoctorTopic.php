<?php

namespace Abdalla\ReportGenerator\Tests\Fixtures\Bench;

use Abdalla\ReportGenerator\Topics\Field;
use Abdalla\ReportGenerator\Topics\Relation;
use Abdalla\ReportGenerator\Topics\Topic;

/** Benchmark topic over the doctors table (benchmarks/schema.sql). */
class DoctorTopic extends Topic
{
    public string $model = DoctorModel::class;

    public ?string $key = 'doctors';

    public function fields(): array
    {
        return [
            Field::id('id', 'ID'),
            Field::text('name', 'Doctor'),
            Field::text('class', 'Class'),
            Field::id('speciality_id', 'Speciality ID'),
            Field::id('brick_id', 'Brick ID'),
        ];
    }

    public function relations(): array
    {
        return [
            Relation::belongsTo('speciality', 'Speciality', SpecialityTopic::class, 'speciality_id'),
            Relation::belongsTo('brick', 'Brick', BrickTopic::class, 'brick_id'),
            Relation::hasMany('visits', 'Visits', VisitTopic::class, 'doctor_id'),
        ];
    }
}
