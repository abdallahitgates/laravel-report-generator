<?php

namespace Abdalla\ReportGenerator\Tests\Fixtures\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Visit extends Model
{
    protected $table = 'visits';

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /** Not a relation: must never be called by the scanner. */
    public function explode(): void
    {
        throw new \RuntimeException('The scanner must not call arbitrary model methods.');
    }
}
