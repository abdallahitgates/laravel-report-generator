<?php

namespace Abdalla\ReportGenerator\Semantic;

use Abdalla\ReportGenerator\Models\Dataset;
use Abdalla\ReportGenerator\Models\Field;
use Abdalla\ReportGenerator\Models\ModelVersion;
use Abdalla\ReportGenerator\Models\Relationship;
use Abdalla\ReportGenerator\Models\SchemaSnapshotRecord;
use Abdalla\ReportGenerator\Support\Tables;
use Illuminate\Validation\ValidationException;

/**
 * Freezes the draft into an immutable model version. Only what users may
 * see goes in: visible, present datasets and fields, and confirmed
 * relationships between them. Join columns travel with the relationships,
 * so hidden key fields still work for joins.
 */
final class ModelPublisher
{
    public function publish(?int $userId = null, ?string $notes = null): ModelVersion
    {
        $payload = $this->draftPayload();

        if ($payload['datasets'] === []) {
            throw ValidationException::withMessages([
                'model' => 'Nothing to publish: every dataset is hidden or missing. Show at least one dataset first.',
            ]);
        }

        return app('db')->connection(Tables::connection())->transaction(function () use ($payload, $userId, $notes) {
            $version = (int) ModelVersion::query()->lockForUpdate()->max('version') + 1;
            $payload['version'] = $version;

            return ModelVersion::query()->create([
                'version' => $version,
                'snapshot_id' => SchemaSnapshotRecord::query()->max('id'),
                'payload' => $payload,
                'notes' => $notes,
                'published_by' => $userId,
                'published_at' => now(),
            ]);
        });
    }

    /** True when publishing now would produce something different from the current version. */
    public function hasUnpublishedChanges(): bool
    {
        $current = ModelVersion::current();
        if ($current === null) {
            return Dataset::query()->exists();
        }

        $published = $current->payload;
        unset($published['version']);

        return $this->fingerprint($published) !== $this->fingerprint($this->draftPayload());
    }

    /** @return array{datasets: list<array>, relationships: list<array>} */
    public function draftPayload(): array
    {
        $datasets = Dataset::query()
            ->where('is_hidden', false)
            ->orderBy('key')
            ->get()
            ->reject(fn (Dataset $d) => $d->isMissing())
            ->keyBy('id');

        $fields = Field::query()
            ->whereIn('dataset_id', $datasets->keys())
            ->where('is_hidden', false)
            ->orderBy('dataset_id')->orderBy('sort_order')
            ->get()
            ->reject(fn (Field $f) => $f->isMissing())
            ->groupBy('dataset_id');

        $payloadDatasets = [];
        foreach ($datasets as $id => $dataset) {
            $datasetFields = $fields->get($id, collect());
            if ($datasetFields->isEmpty()) {
                continue;
            }

            $payloadDatasets[] = [
                'key' => $dataset->key,
                'label' => $dataset->label,
                'description' => $dataset->description,
                'source_type' => $dataset->source_type,
                'source_name' => $dataset->source_name,
                'primary_key' => $dataset->primary_key ? explode(',', $dataset->primary_key) : [],
                'default_time_field' => $dataset->default_time_field,
                'soft_delete_column' => $dataset->meta['soft_delete_column'] ?? null,
                'row_estimate' => $dataset->row_estimate,
                'fields' => $datasetFields->map(fn (Field $f) => [
                    'key' => $f->key,
                    'column' => $f->column,
                    'expression' => $f->expression,
                    'label' => $f->label,
                    'description' => $f->description,
                    'type' => $f->data_type->value,
                    'role' => $f->role->value,
                    'aggregations' => $f->aggregations ?? [],
                    'format' => $f->format,
                    'indexed' => $f->is_indexed,
                ])->values()->all(),
            ];
        }

        $published = array_flip(array_column($payloadDatasets, 'key'));
        $keyById = $datasets->map(fn (Dataset $d) => $d->key);

        $relationships = Relationship::query()
            ->where('status', Relationship::CONFIRMED)
            ->orderBy('id')
            ->get()
            ->filter(fn (Relationship $r) => isset($published[$keyById[$r->from_dataset_id] ?? ''], $published[$keyById[$r->to_dataset_id] ?? '']))
            ->map(fn (Relationship $r) => [
                'id' => $r->id,
                'from' => $keyById[$r->from_dataset_id],
                'from_column' => $r->from_column,
                'to' => $keyById[$r->to_dataset_id],
                'to_column' => $r->to_column,
                'cardinality' => $r->cardinality->value,
                'join_type' => $r->join_type,
                'label' => $r->label,
            ])
            ->values()
            ->all();

        return ['datasets' => $payloadDatasets, 'relationships' => $relationships];
    }

    private function fingerprint(array $payload): string
    {
        return hash('sha256', json_encode($payload));
    }
}
