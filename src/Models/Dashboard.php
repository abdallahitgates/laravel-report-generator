<?php

namespace Abdalla\ReportGenerator\Models;

use Abdalla\ReportGenerator\Support\Tables;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Str;

/**
 * A saved dashboard: widgets over saved reports and the default report
 * filters (period, lines, ...) they share.
 *
 * @property int $id
 * @property string $uuid
 * @property string $name
 * @property ?string $description
 * @property ?int $owner_id
 * @property string $visibility  private | public
 * @property array{context?: array, widgets: list<array>} $layout
 * @property ?string $permission
 * @property bool $in_menu
 * @property ?string $menu_group
 * @property ?\Illuminate\Support\Carbon $published_at
 */
class Dashboard extends RgModel
{
    use SoftDeletes;

    protected const TABLE = Tables::DASHBOARDS;

    protected $attributes = [
        'visibility' => Report::PRIVATE,
        'in_menu' => false,
    ];

    protected $casts = [
        'layout' => 'array',
        'owner_id' => 'integer',
        'in_menu' => 'boolean',
        'published_at' => 'datetime',
    ];

    protected static function booted(): void
    {
        static::creating(function (Dashboard $dashboard) {
            $dashboard->uuid ??= (string) Str::uuid();
        });
    }

    public function getRouteKeyName(): string
    {
        return 'uuid';
    }

    /** @return list<array> */
    public function widgets(): array
    {
        return array_values($this->layout['widgets'] ?? []);
    }
}
