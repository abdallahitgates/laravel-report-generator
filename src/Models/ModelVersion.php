<?php

namespace Abdalla\ReportGenerator\Models;

use Abdalla\ReportGenerator\Support\Tables;

/**
 * An immutable, published semantic model. The query engine only ever reads
 * the latest version; reports remember which version they were built on.
 *
 * @property int $id
 * @property int $version
 * @property array $payload
 * @property ?string $notes
 */
class ModelVersion extends RgModel
{
    protected const TABLE = Tables::MODEL_VERSIONS;

    protected $casts = [
        'payload' => 'array',
        'version' => 'integer',
        'published_at' => 'datetime',
    ];

    public static function current(): ?self
    {
        return static::query()->orderByDesc('version')->first();
    }
}
