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

    /** Lets a test expose speciality_id to the row scope. */
    public static bool $scopeBySpeciality = false;

    /** Lets a test add "employees who visited" (through visits) as one step. */
    public static ?string $throughVia = null;

    /** Lets a test keep doctors out of the start picker. */
    public static bool $startableFlag = true;

    public function __construct()
    {
        $this->scopeColumns = static::$scopeBySpeciality ? ['speciality' => 'speciality_id'] : [];
        $this->startable = static::$startableFlag;
    }

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
            ...(static::$throughVia ? [Relation::through('visitors', 'Employees who visited', static::$throughVia, 'employee')] : []),
        ];
    }
}
