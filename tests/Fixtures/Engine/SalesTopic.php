<?php

namespace Abdalla\ReportGenerator\Tests\Fixtures\Engine;

use Abdalla\ReportGenerator\Topics\Field;
use Abdalla\ReportGenerator\Topics\Relation;
use Abdalla\ReportGenerator\Topics\Topic;

class SalesTopic extends Topic
{
    public string $model = Sale::class;

    public ?string $group = 'Sales';

    public ?string $dateColumn = 'sale_date';

    public function fields(): array
    {
        return [
            Field::count('Number of sales'),
            Field::id('id', 'Sale ID'),
            Field::id('doctor_id', 'Doctor ID'),
            Field::date('sale_date', 'Sale date'),
            Field::money('value', 'Value'),
        ];
    }

    public function relations(): array
    {
        return [Relation::belongsTo('doctor', 'Doctor', DoctorsTopic::class, 'doctor_id')];
    }
}
