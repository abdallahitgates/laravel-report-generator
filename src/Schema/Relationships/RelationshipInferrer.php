<?php

namespace Abdalla\ReportGenerator\Schema\Relationships;

use Abdalla\ReportGenerator\Enums\Cardinality;
use Abdalla\ReportGenerator\Schema\SchemaSnapshot;
use Abdalla\ReportGenerator\Schema\TableSchema;
use Abdalla\ReportGenerator\Schema\TypeMapper;
use Illuminate\Support\Str;

/**
 * Finds joins from three sources, strongest first:
 *   foreign key constraints (1.0), Eloquent relations (0.9, passed in),
 *   and *_id naming conventions (0.7, or lower for weaker guesses).
 * When several sources find the same link, the most confident one wins.
 */
final class RelationshipInferrer
{
    /**
     * @param  list<InferredRelationship>  $eloquent
     * @return list<InferredRelationship>
     */
    public function infer(SchemaSnapshot $snapshot, array $eloquent = []): array
    {
        $edges = [];
        $add = function (InferredRelationship $edge) use (&$edges, $snapshot) {
            if (! $this->valid($edge, $snapshot)) {
                return;
            }
            $existing = $edges[$edge->edgeKey()] ?? null;
            if ($existing === null || $edge->confidence > $existing->confidence) {
                $edges[$edge->edgeKey()] = $edge;
            }
        };

        foreach ($snapshot->tables as $table) {
            foreach ($table->foreignKeys as $fk) {
                // Composite foreign keys are rare in reporting schemas; the admin can add them by hand.
                if (count($fk->columns) !== 1) {
                    continue;
                }
                $add(new InferredRelationship(
                    $table->name, $fk->columns[0], $fk->foreignTable, $fk->foreignColumns[0],
                    $this->cardinality($table, $fk->columns[0]), InferredRelationship::ORIGIN_FOREIGN_KEY, 1.0,
                ));
            }
        }

        foreach ($eloquent as $edge) {
            $add($edge);
        }

        // A column whose target is already known from a foreign key or a model
        // gets no naming guesses: they would only add noise to the review queue.
        $known = [];
        foreach ($edges as $edge) {
            $known[$edge->fromTable.'.'.$edge->fromColumn] = true;
        }

        foreach ($snapshot->tables as $table) {
            foreach ($this->conventionEdges($table, $snapshot) as $edge) {
                if (! isset($known[$edge->fromTable.'.'.$edge->fromColumn])) {
                    $add($edge);
                }
            }
        }

        ksort($edges);

        return array_values($edges);
    }

    /** @return iterable<InferredRelationship> */
    private function conventionEdges(TableSchema $table, SchemaSnapshot $snapshot): iterable
    {
        foreach ($table->columns as $name => $column) {
            if (! preg_match('/^(.+)_id$/i', $name, $m) || $table->primaryKey() === [$name]) {
                continue;
            }

            $base = strtolower($m[1]);

            // Polymorphic pairs (commentable_type + commentable_id) point at many tables.
            if ($table->hasColumn($m[1].'_type')) {
                continue;
            }

            // parent_id is almost always a self-reference (tree of lines, divisions, ...).
            if ($base === 'parent') {
                if ($target = $this->singleKey($table)) {
                    yield new InferredRelationship($table->name, $name, $table->name, $target,
                        Cardinality::ManyToOne, InferredRelationship::ORIGIN_CONVENTION, 0.6);
                }
                continue;
            }

            // Strongest guess first; a weaker guess never replaces a stronger one for the same table.
            $candidates = [];
            foreach ([
                [Str::plural($base), 0.7],
                [$base, 0.7],
                // from_user_id -> users: plausible but weaker.
                [Str::plural(Str::afterLast($base, '_')), 0.5],
            ] as [$candidate, $confidence]) {
                $candidates[$candidate] ??= $confidence;
            }

            foreach ($candidates as $candidate => $confidence) {
                $target = $snapshot->table($candidate);
                if ($target === null || ! ($key = $this->singleKey($target))) {
                    continue;
                }

                yield new InferredRelationship($table->name, $name, $target->name, $key,
                    $this->cardinality($table, $name), InferredRelationship::ORIGIN_CONVENTION, $confidence);
                break;
            }
        }
    }

    /** The target's single-column primary key, or "id" for tables (and views) without a declared one. */
    private function singleKey(TableSchema $table): ?string
    {
        $pk = $table->primaryKey();
        if (count($pk) === 1) {
            return $pk[0];
        }

        return $pk === [] && $table->hasColumn('id') ? 'id' : null;
    }

    private function cardinality(TableSchema $table, string $column): Cardinality
    {
        return $table->isUnique($column) ? Cardinality::OneToOne : Cardinality::ManyToOne;
    }

    private function valid(InferredRelationship $edge, SchemaSnapshot $snapshot): bool
    {
        $from = $snapshot->table($edge->fromTable)?->column($edge->fromColumn);
        $to = $snapshot->table($edge->toTable)?->column($edge->toColumn);

        if ($from === null || $to === null) {
            return false;
        }

        // A row joining itself on the same column is meaningless.
        if ($edge->fromTable === $edge->toTable && $edge->fromColumn === $edge->toColumn) {
            return false;
        }

        return TypeMapper::joinable(TypeMapper::map($from), TypeMapper::map($to));
    }
}
