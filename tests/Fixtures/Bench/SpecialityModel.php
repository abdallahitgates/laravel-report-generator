<?php

namespace Abdalla\ReportGenerator\Tests\Fixtures\Bench;

use Illuminate\Database\Eloquent\Model;

class SpecialityModel extends Model
{
    protected $connection = 'mysql_bench';

    protected $table = 'specialities';
}
