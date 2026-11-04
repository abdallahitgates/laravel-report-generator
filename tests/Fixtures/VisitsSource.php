<?php

namespace Abdalla\ReportGenerator\Tests\Fixtures;

use Illuminate\Contracts\Auth\Authenticatable;
use Abdalla\ReportGenerator\Contracts\ReportSource;
use Abdalla\ReportGenerator\Definitions\FieldDefinition;
use Abdalla\ReportGenerator\Definitions\ParameterDefinition;
use Abdalla\ReportGenerator\Enums\DataType;

class VisitsSource implements ReportSource
{
    public function __construct(private string $key = 'visits_summary')
    {
    }

    public function key(): string
    {
        return $this->key;
    }

    public function label(): string
    {
        return 'Visits summary';
    }

    public function fields(): array
    {
        return [
            FieldDefinition::dimension('employee', 'Employee'),
            FieldDefinition::dimension('visit_date', 'Visit date', DataType::Date),
            FieldDefinition::measure('visits', 'Visits', DataType::Integer),
        ];
    }

    public function parameters(): array
    {
        return [new ParameterDefinition('period', 'Period', 'date_range', required: true)];
    }

    public function rows(array $parameters, ?Authenticatable $user): iterable
    {
        yield ['employee' => 'A', 'visit_date' => '2026-01-01', 'visits' => 3];
    }
}
