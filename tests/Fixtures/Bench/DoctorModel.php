<?php

namespace Abdalla\ReportGenerator\Tests\Fixtures\Bench;

use Illuminate\Database\Eloquent\Model;

class DoctorModel extends Model
{
    protected $connection = 'mysql_bench';

    protected $table = 'doctors';
}
