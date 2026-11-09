<?php

namespace Abdalla\ReportGenerator\Topics;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Str;

/**
 * Something reports can be built on (actual visits, sales, doctors, ...),
 * declared in the host application's code: its fields with their labels,
 * how it connects to other topics, and which of its columns hold the
 * employee, division, line and date every report is filtered by.
 *
 *   class ActualVisitsTopic extends Topic
 *   {
 *       public string $model = ActualVisit::class;
 *       public ?string $employeeColumn = 'user_id';
 *       public ?string $divisionColumn = 'div_id';
 *
 *       public function fields(): array { return [Field::date('visit_date', 'Visit date'), ...]; }
 *       public function relations(): array { return [Relation::belongsTo('employee', 'Employee', UsersTopic::class, 'user_id'), ...]; }
 *   }
 */
abstract class Topic
{
    /** @var class-string<Model> Eloquent model: gives the table, primary key and soft-delete column. */
    public string $model;

    /** Stable identifier used in saved reports. Default: snake_case class name without "Topic". */
    public ?string $key = null;

    public ?string $label = null;

    /** Tab the topic is listed under, e.g. "Structure" for lines, divisions and their links. */
    public ?string $group = null;

    public ?string $description = null;

    /** Label of the built-in row count, e.g. "Number of visits". */
    public ?string $countLabel = null;

    /** Columns every report on this topic is filtered and secured by. */
    public ?string $employeeColumn = null;

    public ?string $divisionColumn = null;

    public ?string $lineColumn = null;

    /**
     * Further columns the row scope may restrict, by name, for host rules
     * beyond employee and division: ['product' => 'product_id'].
     *
     * @var array<string, string>
     */
    public array $scopeColumns = [];

    public ?string $dateColumn = null;

    /**
     * For rows valid over a span (structure assignments with from_date /
     * to_date): the report period keeps rows valid at any time in it,
     * i.e. from <= period end and (to is empty or to >= period start).
     * Used instead of $dateColumn when set.
     */
    public ?string $validFromColumn = null;

    public ?string $validToColumn = null;

    /** Lookup data anyone building reports may read in full (specialities, products). */
    public bool $public = false;

    /**
     * Readable through a relation from a topic the user may read, but not
     * as a report's main topic: details of rows the user already sees
     * (the distributor sale behind a division's sales line).
     */
    public bool $lookup = false;

    /**
     * Offered as a report's main topic in the builder's start picker. Set
     * false on link tables (user <-> role, line <-> product): they stay
     * reachable through relations, and reports already built on them
     * still open.
     */
    public bool $startable = true;

    /** Gate ability a user needs to report on this topic (admins with view-all always can). */
    public ?string $permission = null;

    private ?Model $instance = null;

    /** @return list<Field> */
    abstract public function fields(): array;

    /**
     * Fixed conditions every row of the topic meets, applied wherever the
     * topic appears. Lets one table back several topics, e.g. approvals of
     * plan visits vs vacations in a polymorphic table:
     *   return ['visitable_type' => PlanVisit::class];
     *
     * @return array<string, scalar|list<scalar>|null>  column => value, list of values, or null for IS NULL
     */
    public function conditions(): array
    {
        return [];
    }

    /** @return list<Relation> */
    public function relations(): array
    {
        return [];
    }

    final public function key(): string
    {
        if ($this->key !== null) {
            return $this->key;
        }

        $base = class_basename(static::class);
        $base = str_ends_with($base, 'Topic') && $base !== 'Topic' ? substr($base, 0, -5) : $base;

        return Str::snake($base);
    }

    public function label(): string
    {
        return $this->label ?? Str::headline($this->key());
    }

    public function group(): string
    {
        return $this->group ?? $this->label();
    }

    public function countLabel(): string
    {
        return $this->countLabel ?? 'Number of '.Str::lower($this->label());
    }

    public function model(): Model
    {
        if (! isset($this->model) || ! is_subclass_of($this->model, Model::class)) {
            throw new InvalidTopicException(static::class.' must set $model to an Eloquent model class.');
        }

        return $this->instance ??= new ($this->model)();
    }

    public function table(): string
    {
        return $this->model()->getTable();
    }

    public function primaryKey(): string
    {
        return $this->model()->getKeyName();
    }

    public function softDeleteColumn(): ?string
    {
        $model = $this->model();

        return in_array(SoftDeletes::class, class_uses_recursive($model), true) ? $model->getDeletedAtColumn() : null;
    }
}
