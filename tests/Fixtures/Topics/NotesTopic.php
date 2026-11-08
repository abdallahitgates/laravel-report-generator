<?php

namespace Abdalla\ReportGenerator\Tests\Fixtures\Topics;

use Abdalla\ReportGenerator\Tests\Fixtures\Engine\Visit;
use Abdalla\ReportGenerator\Topics\Field;
use Abdalla\ReportGenerator\Topics\Topic;

/** A field only supervisors may see. */
class NotesTopic extends Topic
{
    public string $model = Visit::class;

    public function fields(): array
    {
        return [
            Field::text('status', 'Status'),
            Field::text('notes', 'Private notes')->visibleTo('supervisor'),
        ];
    }
}
