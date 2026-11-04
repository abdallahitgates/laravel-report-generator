<?php

namespace Abdalla\ReportGenerator\Models;

use Abdalla\ReportGenerator\Support\Tables;
use Illuminate\Database\Eloquent\Model;

/**
 * Base for the package's own tables: applies the configured prefix and
 * metadata connection.
 */
abstract class RgModel extends Model
{
    /** Unprefixed table name, one of the Tables::* constants. */
    protected const TABLE = '';

    protected $guarded = ['id'];

    public function getTable(): string
    {
        return Tables::name(static::TABLE);
    }

    public function getConnectionName(): ?string
    {
        return Tables::connection() ?? parent::getConnectionName();
    }
}
