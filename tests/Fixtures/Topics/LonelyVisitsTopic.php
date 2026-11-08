<?php

namespace Abdalla\ReportGenerator\Tests\Fixtures\Topics;

use Abdalla\ReportGenerator\Tests\Fixtures\Engine\DoctorsTopic;
use Abdalla\ReportGenerator\Tests\Fixtures\Engine\Visit;
use Abdalla\ReportGenerator\Topics\Field;
use Abdalla\ReportGenerator\Topics\Relation;
use Abdalla\ReportGenerator\Topics\Topic;

/** Points at a topic that is not registered with it. */
class LonelyVisitsTopic extends Topic
{
    public string $model = Visit::class;

    public ?string $key = 'visits';

    public function fields(): array
    {
        return [
            Field::id('id', 'Visit ID'),
            Field::text('status', 'Status'),
        ];
    }

    public function relations(): array
    {
        return [Relation::belongsTo('doctor', 'Doctor', DoctorsTopic::class, 'doctor_id')];
    }
}
