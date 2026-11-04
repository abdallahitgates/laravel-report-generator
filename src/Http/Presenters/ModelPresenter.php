<?php

namespace Abdalla\ReportGenerator\Http\Presenters;

use Abdalla\ReportGenerator\Models\Dataset;
use Abdalla\ReportGenerator\Models\Field;
use Abdalla\ReportGenerator\Models\Relationship;

final class ModelPresenter
{
    public static function datasetSummary(Dataset $dataset): array
    {
        return [
            'id' => $dataset->id,
            'key' => $dataset->key,
            'label' => $dataset->label,
            'source_type' => $dataset->source_type,
            'source_name' => $dataset->source_name,
            'row_estimate' => $dataset->row_estimate,
            'is_hidden' => $dataset->is_hidden,
            'missing' => $dataset->isMissing(),
            'fields_count' => (int) ($dataset->fields_count ?? 0),
            'visible_fields_count' => (int) ($dataset->visible_fields_count ?? 0),
            'pending_relationships_count' => (int) ($dataset->pending_outgoing_count ?? 0) + (int) ($dataset->pending_incoming_count ?? 0),
        ];
    }

    public static function dataset(Dataset $dataset): array
    {
        return [
            'id' => $dataset->id,
            'key' => $dataset->key,
            'label' => $dataset->label,
            'description' => $dataset->description,
            'source_type' => $dataset->source_type,
            'source_name' => $dataset->source_name,
            'primary_key' => $dataset->primary_key,
            'default_time_field' => $dataset->default_time_field,
            'row_estimate' => $dataset->row_estimate,
            'is_hidden' => $dataset->is_hidden,
            'missing' => $dataset->isMissing(),
            'soft_delete_column' => $dataset->meta['soft_delete_column'] ?? null,
            'polymorphic' => $dataset->meta['polymorphic'] ?? [],
        ];
    }

    public static function field(Field $field): array
    {
        return [
            'id' => $field->id,
            'dataset_id' => $field->dataset_id,
            'key' => $field->key,
            'column' => $field->column,
            'label' => $field->label,
            'description' => $field->description,
            'type' => $field->data_type->value,
            'role' => $field->role->value,
            'aggregations' => $field->aggregations ?? [],
            'format' => $field->format,
            'is_hidden' => $field->is_hidden,
            'is_sensitive' => $field->is_sensitive,
            'is_indexed' => $field->is_indexed,
            'hidden_reason' => $field->meta['hidden_reason'] ?? null,
            'missing' => $field->isMissing(),
        ];
    }

    public static function relationship(Relationship $relationship): array
    {
        $side = fn (?Dataset $d) => $d ? ['id' => $d->id, 'key' => $d->key, 'label' => $d->label] : null;

        return [
            'id' => $relationship->id,
            'from' => $side($relationship->fromDataset),
            'from_column' => $relationship->from_column,
            'to' => $side($relationship->toDataset),
            'to_column' => $relationship->to_column,
            'cardinality' => $relationship->cardinality->value,
            'join_type' => $relationship->join_type,
            'origin' => $relationship->origin,
            'confidence' => $relationship->confidence,
            'status' => $relationship->status,
            'label' => $relationship->label,
        ];
    }
}
