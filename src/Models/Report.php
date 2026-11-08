<?php

namespace Abdalla\ReportGenerator\Models;

use Abdalla\ReportGenerator\Support\Tables;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Str;

/**
 * A saved report: its definition plus who owns it and who else may open it.
 *
 * @property int $id
 * @property string $uuid
 * @property string $name
 * @property ?string $description
 * @property ?string $folder
 * @property ?int $owner_id
 * @property string $visibility  private | public
 * @property array $definition
 * @property ?int $model_version
 * @property ?string $permission  set when published: who holds it may open the report
 * @property bool $in_menu
 * @property ?string $menu_group
 * @property ?\Illuminate\Support\Carbon $published_at
 */
class Report extends RgModel
{
    use SoftDeletes;

    public const PRIVATE = 'private';
    public const PUBLIC = 'public';

    protected const TABLE = Tables::REPORTS;

    protected $attributes = [
        'visibility' => self::PRIVATE,
        'in_menu' => false,
    ];

    protected $casts = [
        'definition' => 'array',
        'definition_version' => 'integer',
        'model_version' => 'integer',
        'owner_id' => 'integer',
        'in_menu' => 'boolean',
        'published_at' => 'datetime',
    ];

    protected static function booted(): void
    {
        static::creating(function (Report $report) {
            $report->uuid ??= (string) Str::uuid();
        });
    }

    public function getRouteKeyName(): string
    {
        return 'uuid';
    }

    public function shares(): HasMany
    {
        return $this->hasMany(ReportShare::class, 'report_id');
    }
}
