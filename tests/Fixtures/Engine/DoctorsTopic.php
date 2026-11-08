<?php

namespace Abdalla\ReportGenerator\Tests\Fixtures\Engine;

use Abdalla\ReportGenerator\Topics\Field;
use Abdalla\ReportGenerator\Topics\Relation;
use Abdalla\ReportGenerator\Topics\Topic;

class DoctorsTopic extends Topic
{
    public string $model = Doctor::class;

    public ?string $group = 'List';

    public ?string $lineColumn = 'line_id';

    public function fields(): array
    {
        return [
            Field::id('id', 'Doctor ID'),
            Field::text('name', 'Doctor'),
            Field::id('speciality_id', 'Speciality ID'),
            Field::id('line_id', 'Line ID'),
            Field::enum('class', 'Class', ['A' => 'Class A', 'B' => 'Class B']),
            Field::boolean('is_kol', 'Key opinion leader'),
        ];
    }

    public function relations(): array
    {
        return [
            Relation::belongsTo('speciality', 'Speciality', SpecialitiesTopic::class, 'speciality_id'),
            Relation::belongsTo('line', 'Line', LinesTopic::class, 'line_id'),
            Relation::hasMany('visits', 'Visits', VisitsTopic::class, 'doctor_id'),
            Relation::hasMany('sales', 'Sales', SalesTopic::class, 'doctor_id'),
        ];
    }
}
