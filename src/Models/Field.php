<?php

namespace Abdalla\ReportGenerator\Models;

use Abdalla\ReportGenerator\Enums\DataType;
use Abdalla\ReportGenerator\Enums\FieldRole;
use Abdalla\ReportGenerator\Support\Tables;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property int $id
 * @property int $dataset_id
 * @property string $key
 * @property ?string $column
 * @property string $label
 * @property DataType $data_type
 * @property FieldRole $role
 * @property ?array $aggregations
 * @property ?string $format
 * @property bool $is_hidden
 * @property bool $is_sensitive
 * @property bool $is_indexed
 * @property array $meta
 */
class Field extends RgModel
{
    protected const TABLE = Tables::FIELDS;

    protected $casts = [
        'data_type' => DataType::class,
        'role' => FieldRole::class,
        'aggregations' => 'array',
        'is_hidden' => 'boolean',
        'is_sensitive' => 'boolean',
        'is_indexed' => 'boolean',
        'sort_order' => 'integer',
        'meta' => 'array',
    ];

    protected $attributes = [
        'meta' => '{}',
    ];

    public function dataset(): BelongsTo
    {
        return $this->belongsTo(Dataset::class, 'dataset_id');
    }

    public function isMissing(): bool
    {
        return (bool) ($this->meta['missing'] ?? false);
    }
}
