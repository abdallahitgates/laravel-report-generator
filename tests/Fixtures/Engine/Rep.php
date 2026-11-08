<?php

namespace Abdalla\ReportGenerator\Tests\Fixtures\Engine;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class Rep extends Model
{
    use SoftDeletes;
    protected $table = 'users';
}
