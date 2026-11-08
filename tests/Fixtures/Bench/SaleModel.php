<?php

namespace Abdalla\ReportGenerator\Tests\Fixtures\Bench;

use Illuminate\Database\Eloquent\Model;

class SaleModel extends Model
{
    protected $connection = 'mysql_bench';

    protected $table = 'sales';
}
