<?php

namespace Abdalla\ReportGenerator\Tests\Fixtures\Topics;

use Abdalla\ReportGenerator\Tests\Fixtures\Engine\Visit;
use Abdalla\ReportGenerator\Topics\Field;
use Abdalla\ReportGenerator\Topics\Topic;

class UnratedVisitsTopic extends Topic
{
    public string $model = Visit::class;

    public function conditions(): array
    {
        return ['status' => null];
    }

    public function fields(): array
    {
        return [Field::id('id', 'ID'), Field::text('status', 'Status')];
    }
}
