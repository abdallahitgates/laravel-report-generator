<?php

namespace Abdalla\ReportGenerator\Models;

use Abdalla\ReportGenerator\Schema\SchemaSnapshot;
use Abdalla\ReportGenerator\Support\Tables;

/**
 * @property int $id
 * @property string $hash
 * @property array $payload
 * @property int $tables_count
 */
class SchemaSnapshotRecord extends RgModel
{
    protected const TABLE = Tables::SCHEMA_SNAPSHOTS;

    protected $casts = [
        'payload' => 'array',
        'tables_count' => 'integer',
    ];

    public function snapshot(): SchemaSnapshot
    {
        return SchemaSnapshot::fromArray($this->payload);
    }
}
