<?php

namespace Abdalla\ReportGenerator\Tests\Fixtures\Publishing;

use Illuminate\Foundation\Auth\User as Authenticatable;
use Spatie\Permission\Traits\HasRoles;

/** A host user with spatie roles, for publishing tests. */
class Member extends Authenticatable
{
    use HasRoles;

    protected $table = 'members';

    protected $guarded = [];

    protected string $guard_name = 'web';

    public $timestamps = false;
}
