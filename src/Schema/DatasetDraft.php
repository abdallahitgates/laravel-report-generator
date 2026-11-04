<?php

namespace Abdalla\ReportGenerator\Schema;

/** What the scanner proposes for one table or view. */
final class DatasetDraft
{
    /**
     * @param  list<FieldDraft>  $fields
     * @param  list<string>  $polymorphic  base names of *_type / *_id pairs
     */
    public function __construct(
        public readonly string $key,
        public readonly string $sourceType,
        public readonly string $sourceName,
        public readonly string $label,
        public readonly ?string $description,
        public readonly ?string $primaryKey,
        public readonly ?string $defaultTimeField,
        public readonly ?int $rowEstimate,
        public readonly array $fields,
        public readonly ?string $softDeleteColumn = null,
        public readonly array $polymorphic = [],
    ) {
    }
}
