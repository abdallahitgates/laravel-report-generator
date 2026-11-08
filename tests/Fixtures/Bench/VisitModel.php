<?php

namespace Abdalla\ReportGenerator\Tests\Fixtures\Bench;

use Illuminate\Database\Eloquent\Model;

class VisitModel extends Model
{
    protected $connection = 'mysql_bench';

    protected $table = 'visits';
}
