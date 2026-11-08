<?php

namespace Abdalla\ReportGenerator\Tests\Fixtures\Bench;

use Abdalla\ReportGenerator\Topics\Field;
use Abdalla\ReportGenerator\Topics\Relation;
use Abdalla\ReportGenerator\Topics\Topic;

/** Benchmark topic over the users table (benchmarks/schema.sql). */
class UserTopic extends Topic
{
    public string $model = UserModel::class;

    public ?string $key = 'users';

    public function fields(): array
    {
        return [
            Field::id('id', 'ID'),
            Field::text('name', 'Rep'),
            Field::id('line_id', 'Line ID'),
        ];
    }

    public function relations(): array
    {
        return [
            Relation::belongsTo('line', 'Line', LineTopic::class, 'line_id'),
        ];
    }
}
