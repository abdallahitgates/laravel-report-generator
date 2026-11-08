<?php

namespace Abdalla\ReportGenerator\Topics;

use Abdalla\ReportGenerator\Enums\Cardinality;
use Abdalla\ReportGenerator\Semantic\Published\PublishedDataset;
use Abdalla\ReportGenerator\Semantic\Published\PublishedField;
use Abdalla\ReportGenerator\Semantic\Published\PublishedRelationship;
use Abdalla\ReportGenerator\Semantic\Published\SemanticModel;
use Closure;
use Illuminate\Contracts\Auth\Authenticatable;

/**
 * Topics (code) -> SemanticModel (what the query engine reads).
 *
 * Relationships are stored once, from the "many" side to the "one" side,
 * whichever topic declared them, with an id derived from the columns, so
 * the id in a saved report's join path stays the same across deploys.
 */
final class TopicModelBuilder
{
    /** @param  Closure(?Authenticatable): list<string>  $rolesOf */
    public function build(TopicRegistry $registry, ?Authenticatable $user, Closure $rolesOf): SemanticModel
    {
        $topics = $registry->all();
        $keyOf = fn (string $class) => $registry->byClass($class)->key();

        $datasets = [];
        foreach ($topics as $key => $topic) {
            $fields = [];
            $countLabel = $topic->countLabel();
            foreach ($topic->fields() as $field) {
                if ($field->isCount()) {
                    $countLabel = $field->label;
                    continue;
                }
                if (! $field->visibleFor($user, $rolesOf)) {
                    continue;
                }
                $fields[$field->keyName()] = new PublishedField(
                    key: $field->keyName(),
                    column: $field->column,
                    label: $field->label,
                    type: $field->type(),
                    role: $field->role(),
                    aggregations: $field->aggregations(),
                    format: $field->formatString(),
                    indexed: false,
                    hidden: $field->isHidden(),
                    description: $field->descriptionText(),
                    options: $field->optionLabels(),
                );
            }

            $datasets[$key] = new PublishedDataset(
                key: $key,
                label: $topic->label(),
                sourceName: $topic->table(),
                fields: $fields,
                primaryKey: [$topic->primaryKey()],
                defaultTimeField: $topic->dateColumn,
                softDeleteColumn: $topic->softDeleteColumn(),
                rowEstimate: null,
                group: $topic->group(),
                description: $topic->description,
                countLabel: $countLabel,
                employeeColumn: $topic->employeeColumn,
                divisionColumn: $topic->divisionColumn,
                lineColumn: $topic->lineColumn,
                dateColumn: $topic->dateColumn,
                public: $topic->public,
                permission: $topic->permission,
                validFromColumn: $topic->validFromColumn,
                validToColumn: $topic->validToColumn,
                conditions: $topic->conditions(),
                lookup: $topic->lookup,
                scopeColumns: $topic->scopeColumns,
            );
        }

        $relationships = [];
        foreach ($topics as $key => $topic) {
            foreach ($topic->relations() as $relation) {
                $edge = self::edge($topic, $relation, $registry->byClass($relation->topic));
                $relationships[$edge->id] ??= $edge;
            }
        }
        ksort($relationships);

        return new SemanticModel(self::version($topics), $datasets, array_values($relationships));
    }

    /**
     * The stored edge for a declared relation, and the signed step id that
     * walks it from the declaring topic (negative = against the edge).
     *
     * @return array{0: PublishedRelationship, 1: int}
     */
    public static function step(Topic $from, Relation $relation, Topic $to): array
    {
        $edge = self::edge($from, $relation, $to);

        return [$edge, $relation->type === Relation::BELONGS_TO ? $edge->id : -$edge->id];
    }

    public static function edge(Topic $from, Relation $relation, Topic $to): PublishedRelationship
    {
        if ($relation->type === Relation::BELONGS_TO) {
            [$many, $manyColumn, $one, $oneColumn] = [$from, $relation->foreignKey, $to, $relation->otherKey ?? $to->primaryKey()];
            $cardinality = Cardinality::ManyToOne;
        } else {
            [$many, $manyColumn, $one, $oneColumn] = [$to, $relation->foreignKey, $from, $relation->otherKey ?? $from->primaryKey()];
            $cardinality = $relation->type === Relation::HAS_ONE ? Cardinality::OneToOne : Cardinality::ManyToOne;
        }

        return new PublishedRelationship(
            id: self::id($many->key(), $manyColumn, $one->key(), $oneColumn),
            from: $many->key(),
            fromColumn: $manyColumn,
            to: $one->key(),
            toColumn: $oneColumn,
            cardinality: $cardinality,
            joinType: 'left',
            label: $relation->label,
        );
    }

    public static function id(string $from, string $fromColumn, string $to, string $toColumn): int
    {
        return crc32("{$from}.{$fromColumn}>{$to}.{$toColumn}") & 0x7FFFFFFF ?: 1;
    }

    /** @param  array<string, Topic>  $topics */
    private static function version(array $topics): int
    {
        $shape = [];
        foreach ($topics as $key => $topic) {
            $shape[$key] = [
                $topic->table(),
                $topic->conditions(),
                array_map(fn (Field $f) => [$f->keyName(), $f->column, $f->type()->value], $topic->fields()),
                array_map(fn (Relation $r) => [$r->name, $r->topic, $r->foreignKey, $r->otherKey], $topic->relations()),
            ];
        }

        return crc32(serialize($shape)) & 0x7FFFFFFF;
    }
}
