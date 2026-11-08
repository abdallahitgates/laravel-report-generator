<?php

namespace Abdalla\ReportGenerator\Tests\Fixtures\Topics;

use Abdalla\ReportGenerator\Tests\Fixtures\Engine\Doctor;
use Abdalla\ReportGenerator\Topics\Field;
use Abdalla\ReportGenerator\Topics\Topic;

class KolDoctorsTopic extends Topic
{
    public string $model = Doctor::class;

    public function conditions(): array
    {
        return ['is_kol' => true];
    }

    public function fields(): array
    {
        return [Field::id('id', 'ID'), Field::text('name', 'Doctor')];
    }
}
