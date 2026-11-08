<?php

namespace Abdalla\ReportGenerator\Tests\Fixtures\Topics;

use Abdalla\ReportGenerator\Tests\Fixtures\Engine\Visit;
use Abdalla\ReportGenerator\Topics\Field;
use Abdalla\ReportGenerator\Topics\Relation;
use Abdalla\ReportGenerator\Topics\Topic;

/** Visits with an outcome (done or missed), joined to key opinion leaders only. */
class OutcomeVisitsTopic extends Topic
{
    public string $model = Visit::class;

    public function conditions(): array
    {
        return ['status' => ['done', 'missed']];
    }

    public function fields(): array
    {
        return [Field::id('id', 'ID'), Field::text('status', 'Status')];
    }

    public function relations(): array
    {
        return [Relation::belongsTo('kol', 'Key opinion leader', KolDoctorsTopic::class, 'doctor_id')];
    }
}
