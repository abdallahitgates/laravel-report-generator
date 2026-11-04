<?php

namespace Abdalla\ReportGenerator\Schema;

use Abdalla\ReportGenerator\Models\Dataset;
use Abdalla\ReportGenerator\Models\Field;
use Abdalla\ReportGenerator\Models\Relationship;
use Abdalla\ReportGenerator\Schema\Relationships\InferredRelationship;
use Abdalla\ReportGenerator\Support\Tables;

/**
 * Merges a fresh scan into the draft model the admin edits.
 *
 * Rules: new tables and columns are added with the scanner's proposals;
 * technical facts (type, index, row estimate) are refreshed; anything the
 * admin decides (labels, roles, visibility, relationship status) is never
 * overwritten. Tables or columns that disappeared are flagged "missing",
 * not deleted, so saved reports can explain what broke.
 */
final class ModelSynchronizer
{
    private const INSERT_CHUNK = 500;

    /**
     * @param  list<DatasetDraft>  $drafts
     * @param  list<InferredRelationship>  $relationships  table names, not dataset keys
     * @param  array<string, string>  $datasetKeyByTable
     */
    public function sync(array $drafts, array $relationships, array $datasetKeyByTable, ScanSummary $summary): void
    {
        $connection = app('db')->connection(Tables::connection());

        $connection->transaction(function () use ($drafts, $relationships, $datasetKeyByTable, $summary) {
            $datasetIds = $this->syncDatasets($drafts, $summary);
            $this->syncFields($drafts, $datasetIds, $summary);
            $this->syncRelationships($relationships, $datasetKeyByTable, $datasetIds, $summary);
        });
    }

    /**
     * @param  list<DatasetDraft>  $drafts
     * @return array<string, int> dataset key => id
     */
    private function syncDatasets(array $drafts, ScanSummary $summary): array
    {
        $existing = Dataset::query()->whereIn('source_type', [TableSchema::TABLE, TableSchema::VIEW])->get()->keyBy('key');
        $seen = [];

        foreach ($drafts as $draft) {
            $seen[$draft->key] = true;
            $meta = [
                'soft_delete_column' => $draft->softDeleteColumn,
                'polymorphic' => $draft->polymorphic,
                'missing' => false,
            ];

            /** @var Dataset|null $dataset */
            $dataset = $existing->get($draft->key);

            if ($dataset === null) {
                Dataset::query()->create([
                    'key' => $draft->key,
                    'source_type' => $draft->sourceType,
                    'source_name' => $draft->sourceName,
                    'label' => $draft->label,
                    'description' => $draft->description,
                    'primary_key' => $draft->primaryKey,
                    'default_time_field' => $draft->defaultTimeField,
                    'is_hidden' => false,
                    'row_estimate' => $draft->rowEstimate,
                    'meta' => $meta,
                ]);
                $summary->datasetsAdded++;
                continue;
            }

            if ($dataset->isMissing()) {
                $summary->datasetsRestored++;
            }

            $dataset->fill([
                'source_type' => $draft->sourceType,
                'source_name' => $draft->sourceName,
                'primary_key' => $draft->primaryKey,
                'row_estimate' => $draft->rowEstimate,
                'meta' => array_merge($dataset->meta ?? [], $meta),
            ]);
            $dataset->default_time_field ??= $draft->defaultTimeField;

            if ($dataset->isDirty()) {
                $dataset->save();
            }
        }

        foreach ($existing as $key => $dataset) {
            if (! isset($seen[$key]) && ! $dataset->isMissing()) {
                $dataset->meta = array_merge($dataset->meta ?? [], ['missing' => true]);
                $dataset->save();
                $summary->datasetsMissing++;
            }
        }

        return Dataset::query()->pluck('id', 'key')->all();
    }

    /**
     * @param  list<DatasetDraft>  $drafts
     * @param  array<string, int>  $datasetIds
     */
    private function syncFields(array $drafts, array $datasetIds, ScanSummary $summary): void
    {
        $scannedIds = array_values(array_intersect_key($datasetIds, array_flip(array_map(fn ($d) => $d->key, $drafts))));

        $existing = [];
        foreach (Field::query()->whereIn('dataset_id', $scannedIds)->whereNotNull('column')->get() as $field) {
            $existing[$field->dataset_id][$field->column] = $field;
        }

        $now = now();
        $inserts = [];

        foreach ($drafts as $draft) {
            $datasetId = $datasetIds[$draft->key];
            $current = $existing[$datasetId] ?? [];
            $usedKeys = array_flip(array_map(fn (Field $f) => $f->key, $current));

            foreach ($draft->fields as $fieldDraft) {
                /** @var Field|null $field */
                $field = $current[$fieldDraft->column] ?? null;
                unset($current[$fieldDraft->column]);

                if ($field === null) {
                    $key = $fieldDraft->key;
                    for ($i = 2; isset($usedKeys[$key]); $i++) {
                        $key = $fieldDraft->key.'_'.$i;
                    }
                    $usedKeys[$key] = true;

                    $inserts[] = [
                        'dataset_id' => $datasetId,
                        'key' => $key,
                        'column' => $fieldDraft->column,
                        'label' => $fieldDraft->label,
                        'data_type' => $fieldDraft->type->value,
                        'role' => $fieldDraft->role->value,
                        'aggregations' => $fieldDraft->aggregations
                            ? json_encode(array_map(fn ($a) => $a->value, $fieldDraft->aggregations))
                            : null,
                        'format' => $fieldDraft->format,
                        'is_hidden' => $fieldDraft->hidden,
                        'is_sensitive' => $fieldDraft->sensitive,
                        'is_indexed' => $fieldDraft->indexed,
                        'sort_order' => $fieldDraft->sortOrder,
                        'meta' => json_encode(['hidden_reason' => $fieldDraft->hiddenReason, 'missing' => false]),
                        'created_at' => $now,
                        'updated_at' => $now,
                    ];
                    $summary->fieldsAdded++;
                    continue;
                }

                $field->fill([
                    'data_type' => $fieldDraft->type,
                    'is_indexed' => $fieldDraft->indexed,
                    'sort_order' => $fieldDraft->sortOrder,
                    'meta' => array_merge($field->meta ?? [], ['missing' => false]),
                ]);

                // A column that became sensitive (renamed, new pattern) is hidden again: safety wins over earlier edits.
                if ($fieldDraft->sensitive && ! $field->is_sensitive) {
                    $field->is_sensitive = true;
                    $field->is_hidden = true;
                }

                if ($field->isDirty()) {
                    $field->save();
                }
            }

            foreach ($current as $gone) {
                if (! $gone->isMissing()) {
                    $gone->meta = array_merge($gone->meta ?? [], ['missing' => true]);
                    $gone->save();
                    $summary->fieldsMissing++;
                }
            }
        }

        foreach (array_chunk($inserts, self::INSERT_CHUNK) as $chunk) {
            Field::query()->insert($chunk);
        }
    }

    /**
     * @param  list<InferredRelationship>  $relationships
     * @param  array<string, string>  $datasetKeyByTable
     * @param  array<string, int>  $datasetIds
     */
    private function syncRelationships(array $relationships, array $datasetKeyByTable, array $datasetIds, ScanSummary $summary): void
    {
        $existing = Relationship::query()->get()->keyBy(
            fn (Relationship $r) => "{$r->from_dataset_id}.{$r->from_column}>{$r->to_dataset_id}.{$r->to_column}"
        );

        $now = now();
        $inserts = [];

        foreach ($relationships as $edge) {
            $fromId = $datasetIds[$datasetKeyByTable[$edge->fromTable] ?? ''] ?? null;
            $toId = $datasetIds[$datasetKeyByTable[$edge->toTable] ?? ''] ?? null;
            if ($fromId === null || $toId === null) {
                continue;
            }

            $key = "{$fromId}.{$edge->fromColumn}>{$toId}.{$edge->toColumn}";
            /** @var Relationship|null $relationship */
            $relationship = $existing->get($key);

            if ($relationship === null) {
                $inserts[$key] = [
                    'from_dataset_id' => $fromId,
                    'from_column' => $edge->fromColumn,
                    'to_dataset_id' => $toId,
                    'to_column' => $edge->toColumn,
                    'cardinality' => $edge->cardinality->value,
                    'join_type' => 'left',
                    'origin' => $edge->origin,
                    'confidence' => $edge->confidence,
                    // A real foreign key is proof; every guess waits for the admin.
                    'status' => $edge->origin === InferredRelationship::ORIGIN_FOREIGN_KEY
                        ? Relationship::CONFIRMED
                        : Relationship::PENDING,
                    'created_at' => $now,
                    'updated_at' => $now,
                ];
                $summary->relationshipsAdded++;
                continue;
            }

            if ($relationship->origin !== InferredRelationship::ORIGIN_MANUAL && $edge->confidence > $relationship->confidence) {
                $relationship->origin = $edge->origin;
                $relationship->confidence = $edge->confidence;
                if ($edge->origin === InferredRelationship::ORIGIN_FOREIGN_KEY && $relationship->status === Relationship::PENDING) {
                    $relationship->status = Relationship::CONFIRMED;
                }
                $relationship->save();
                $summary->relationshipsUpgraded++;
            }
        }

        foreach (array_chunk(array_values($inserts), self::INSERT_CHUNK) as $chunk) {
            Relationship::query()->insert($chunk);
        }
    }
}
