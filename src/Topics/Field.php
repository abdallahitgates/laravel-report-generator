<?php

namespace Abdalla\ReportGenerator\Topics;

use Abdalla\ReportGenerator\Enums\Aggregation;
use Abdalla\ReportGenerator\Enums\DataType;
use Abdalla\ReportGenerator\Enums\FieldRole;
use Closure;
use Illuminate\Contracts\Auth\Authenticatable;

/**
 * One column of a topic as report builders see it.
 *
 *   Field::date('visit_date', 'Visit date')
 *   Field::money('total', 'Total')->totals(['sum', 'avg'])
 *   Field::enum('status', 'Status')->options(['done' => 'Done', 'missed' => 'Missed'])
 *   Field::text('ll', 'Latitude')->visibleTo('admin')
 */
final class Field
{
    public const COUNT = '__rows';

    private ?string $key = null;
    private ?string $description = null;
    private ?string $format = null;
    /** @var list<Aggregation>|null */
    private ?array $aggregations = null;
    private bool $hidden = false;
    /** @var array<string|int, string> */
    private array $options = [];
    /** @var list<string> */
    private array $roles = [];
    private ?Closure $canSee = null;

    private function __construct(
        public readonly string $column,
        public readonly string $label,
        private DataType $type,
        private FieldRole $role,
    ) {
    }

    public static function text(string $column, string $label): self
    {
        return new self($column, $label, DataType::String, FieldRole::Dimension);
    }

    public static function longText(string $column, string $label): self
    {
        return new self($column, $label, DataType::Text, FieldRole::Dimension);
    }

    /** Whole numbers that describe rather than measure (codes, levels). Add ->totals() to sum them. */
    public static function integer(string $column, string $label): self
    {
        return new self($column, $label, DataType::Integer, FieldRole::Dimension);
    }

    /** Amounts that add up (quantities, values, durations). */
    public static function number(string $column, string $label): self
    {
        return new self($column, $label, DataType::Decimal, FieldRole::Measure);
    }

    public static function money(string $column, string $label): self
    {
        return (new self($column, $label, DataType::Decimal, FieldRole::Measure))->format('#,##0.00');
    }

    public static function percent(string $column, string $label): self
    {
        return (new self($column, $label, DataType::Decimal, FieldRole::Measure))
            ->format('0.00%')
            ->totals(['avg', 'min', 'max']);
    }

    public static function date(string $column, string $label): self
    {
        return new self($column, $label, DataType::Date, FieldRole::Time);
    }

    public static function datetime(string $column, string $label): self
    {
        return new self($column, $label, DataType::DateTime, FieldRole::Time);
    }

    public static function boolean(string $column, string $label): self
    {
        return new self($column, $label, DataType::Boolean, FieldRole::Dimension);
    }

    /** @param  array<string|int, string>  $options  stored value => label */
    public static function enum(string $column, string $label, array $options = []): self
    {
        return (new self($column, $label, DataType::String, FieldRole::Dimension))->options($options);
    }

    /** A primary or foreign key: hidden from field lists, used for joins and distinct counts. */
    public static function id(string $column, string $label): self
    {
        return (new self($column, $label, DataType::Integer, FieldRole::Key))->hidden();
    }

    /** Overrides the label of the topic's built-in row count. */
    public static function count(string $label): self
    {
        return new self(self::COUNT, $label, DataType::Integer, FieldRole::Measure);
    }

    public function key(string $key): self
    {
        $this->key = $key;

        return $this;
    }

    public function description(string $description): self
    {
        $this->description = $description;

        return $this;
    }

    /** Spreadsheet-style number format: "#,##0", "#,##0.00", "0.0%". */
    public function format(string $format): self
    {
        $this->format = $format;

        return $this;
    }

    /** @param  list<string|Aggregation>  $aggregations  sum, avg, min, max, count, count_distinct */
    public function totals(array $aggregations): self
    {
        $this->aggregations = array_map(fn ($a) => $a instanceof Aggregation ? $a : Aggregation::from($a), $aggregations);
        if ($this->role === FieldRole::Dimension) {
            $this->role = FieldRole::Measure;
        }

        return $this;
    }

    /** @param  array<string|int, string>  $options */
    public function options(array $options): self
    {
        $this->options = $options;

        return $this;
    }

    /** Usable in reports but not listed in the field picker. */
    public function hidden(bool $hidden = true): self
    {
        $this->hidden = $hidden;

        return $this;
    }

    /** Only users with one of these roles see the field. */
    public function visibleTo(string ...$roles): self
    {
        $this->roles = $roles;

        return $this;
    }

    /** @param  Closure(?Authenticatable): bool  $callback */
    public function canSee(Closure $callback): self
    {
        $this->canSee = $callback;

        return $this;
    }

    public function keyName(): string
    {
        return $this->key ?? $this->column;
    }

    public function isCount(): bool
    {
        return $this->column === self::COUNT;
    }

    public function type(): DataType
    {
        return $this->type;
    }

    public function role(): FieldRole
    {
        return $this->role;
    }

    public function formatString(): ?string
    {
        return $this->format;
    }

    public function descriptionText(): ?string
    {
        return $this->description;
    }

    public function isHidden(): bool
    {
        return $this->hidden;
    }

    /** @return array<string|int, string> */
    public function optionLabels(): array
    {
        return $this->options;
    }

    /** @return list<Aggregation> */
    public function aggregations(): array
    {
        if ($this->aggregations !== null) {
            return $this->aggregations;
        }

        if ($this->role === FieldRole::Measure) {
            return Aggregation::forType($this->type);
        }

        // Earliest / latest date (a doctor's last visit) is always meaningful.
        return $this->type->isTemporal() ? [Aggregation::Min, Aggregation::Max] : [];
    }

    /** @param  Closure(?Authenticatable): list<string>  $rolesOf */
    public function visibleFor(?Authenticatable $user, Closure $rolesOf): bool
    {
        if ($this->canSee !== null && ! ($this->canSee)($user)) {
            return false;
        }

        return $this->roles === [] || array_intersect($this->roles, $rolesOf($user)) !== [];
    }
}
