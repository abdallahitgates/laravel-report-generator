<?php

namespace Abdalla\ReportGenerator\Semantic\Published;

final class PublishedDataset
{
    /**
     * @param  array<string, PublishedField>  $fields  by key
     * @param  list<string>  $primaryKey
     */
    public function __construct(
        public readonly string $key,
        public readonly string $label,
        public readonly string $sourceName,
        public readonly array $fields,
        public readonly array $primaryKey,
        public readonly ?string $defaultTimeField,
        public readonly ?string $softDeleteColumn,
        public readonly ?int $rowEstimate,
    ) {
    }

    public static function fromArray(array $data): self
    {
        $fields = [];
        foreach ($data['fields'] ?? [] as $field) {
            $fields[$field['key']] = PublishedField::fromArray($field);
        }

        return new self(
            $data['key'],
            $data['label'],
            $data['source_name'],
            $fields,
            $data['primary_key'] ?? [],
            $data['default_time_field'] ?? null,
            $data['soft_delete_column'] ?? null,
            $data['row_estimate'] ?? null,
        );
    }

    public function field(string $key): ?PublishedField
    {
        return $this->fields[$key] ?? null;
    }
}
