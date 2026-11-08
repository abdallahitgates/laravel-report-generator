<?php

namespace Abdalla\ReportGenerator\Tests\Fixtures\Engine;

use Abdalla\ReportGenerator\Topics\Field;
use Abdalla\ReportGenerator\Topics\Relation;
use Abdalla\ReportGenerator\Topics\Topic;

class SpecialitiesTopic extends Topic
{
    public string $model = Speciality::class;

    public ?string $group = 'List';

    public function fields(): array
    {
        return [
            Field::id('id', 'Speciality ID'),
            Field::text('name', 'Speciality'),
        ];
    }

    public function relations(): array
    {
        return [Relation::hasMany('doctors', 'Doctors', DoctorsTopic::class, 'speciality_id')];
    }
}
