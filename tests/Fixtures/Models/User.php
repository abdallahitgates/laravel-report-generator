<?php

namespace Abdalla\ReportGenerator\Tests\Fixtures\Models;

use Illuminate\Database\Eloquent\Model;

class User extends Model
{
    protected $table = 'users';

    // No return type on purpose: legacy models often have none.
    public function visits()
    {
        return $this->hasMany(Visit::class);
    }

    public function profile()
    {
        return $this->hasOne(UserProfile::class);
    }
}
