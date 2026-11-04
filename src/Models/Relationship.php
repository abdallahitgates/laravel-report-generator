<?php

namespace Abdalla\ReportGenerator\Models;

use Abdalla\ReportGenerator\Enums\Cardinality;
use Abdalla\ReportGenerator\Support\Tables;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property int $id
 * @property int $from_dataset_id
 * @property string $from_column
 * @property int $to_dataset_id
 * @property string $to_column
 * @property Cardinality $cardinality
 * @property string $join_type
 * @property string $origin
 * @property float $confidence
 * @property string $status
 * @property ?string $label
 */
class Relationship extends RgModel
{
    public const PENDING = 'pending';
    public const CONFIRMED = 'confirmed';
    public const REJECTED = 'rejected';

    protected const TABLE = Tables::RELATIONSHIPS;

    protected $casts = [
        'cardinality' => Cardinality::class,
        'confidence' => 'float',
    ];

    public function fromDataset(): BelongsTo
    {
        return $this->belongsTo(Dataset::class, 'from_dataset_id');
    }

    public function toDataset(): BelongsTo
    {
        return $this->belongsTo(Dataset::class, 'to_dataset_id');
    }
}
