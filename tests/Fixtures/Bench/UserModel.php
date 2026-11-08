<?php

namespace Abdalla\ReportGenerator\Tests\Fixtures\Bench;

use Illuminate\Database\Eloquent\Model;

class UserModel extends Model
{
    protected $connection = 'mysql_bench';

    protected $table = 'users';
}
