<?php

namespace Abdalla\ReportGenerator\Tests\Fixtures\Bench;

use Illuminate\Database\Eloquent\Model;

class ProductModel extends Model
{
    protected $connection = 'mysql_bench';

    protected $table = 'products';
}
