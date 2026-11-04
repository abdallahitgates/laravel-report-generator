<?php

namespace Abdalla\ReportGenerator\Models;

use Abdalla\ReportGenerator\Support\Tables;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * @property int $id
 * @property string $key
 * @property string $source_type
 * @property string $source_name
 * @property string $label
 * @property ?string $description
 * @property ?string $primary_key
 * @property ?string $default_time_field
 * @property bool $is_hidden
 * @property ?int $row_estimate
 * @property array $meta
 */
class Dataset extends RgModel
{
    protected const TABLE = Tables::DATASETS;

    protected $casts = [
        'is_hidden' => 'boolean',
        'row_estimate' => 'integer',
        'meta' => 'array',
    ];

    protected $attributes = [
        'meta' => '{}',
    ];

    public function fields(): HasMany
    {
        return $this->hasMany(Field::class, 'dataset_id')->orderBy('sort_order');
    }

    public function outgoing(): HasMany
    {
        return $this->hasMany(Relationship::class, 'from_dataset_id');
    }

    public function incoming(): HasMany
    {
        return $this->hasMany(Relationship::class, 'to_dataset_id');
    }

    public function isMissing(): bool
    {
        return (bool) ($this->meta['missing'] ?? false);
    }
}
