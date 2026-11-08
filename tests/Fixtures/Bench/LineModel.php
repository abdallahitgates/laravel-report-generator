<?php

namespace Abdalla\ReportGenerator\Tests\Fixtures\Bench;

use Illuminate\Database\Eloquent\Model;

class LineModel extends Model
{
    protected $connection = 'mysql_bench';

    protected $table = 'lines';
}
