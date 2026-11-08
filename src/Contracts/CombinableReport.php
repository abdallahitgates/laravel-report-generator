<?php

namespace Abdalla\ReportGenerator\Contracts;

use Abdalla\ReportGenerator\Combine\CombineRun;

/**
 * An existing report whose rows can be combined with others on the
 * employee or the division they belong to. ExistingReport adapts a host
 * controller with a few lines; implement this for anything else.
 */
interface CombinableReport
{
    /** Stable identifier used in saved combined reports. */
    public function key(): string;

    public function label(): string;

    public function description(): ?string;

    /** Tab it is listed under. */
    public function group(): ?string;

    /** @return list<'employee'|'division'> levels its rows can be produced at */
    public function levels(): array;

    /** Gate ability needed to use it (the report's own permission), or null. */
    public function permission(): ?string;

    /**
     * Columns it offers, when known in advance:
     * [key => ['label' => ..., 'type' => 'integer'|'decimal'|'percent'|'string'|'date', 'total' => 'sum'|null]].
     * Null: discovered from the rows of a run.
     *
     * @return array<string, array<string, mixed>>|null
     */
    public function fields(): ?array;

    /**
     * Rows for the run, each with 'id' (the employee or division id) and
     * optional 'line' / 'division' / 'employee' names, plus its values.
     *
     * @return iterable<array<string, mixed>>
     */
    public function rows(CombineRun $run): iterable;
}
