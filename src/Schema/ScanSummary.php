<?php

namespace Abdalla\ReportGenerator\Schema;

final class ScanSummary implements \JsonSerializable
{
    public int $datasetsAdded = 0;
    public int $datasetsMissing = 0;
    public int $datasetsRestored = 0;
    public int $fieldsAdded = 0;
    public int $fieldsMissing = 0;
    public int $relationshipsAdded = 0;
    public int $relationshipsUpgraded = 0;
    public int $durationMs = 0;
    /** @var list<string> */
    public array $warnings = [];

    public function __construct(
        public readonly int $snapshotId,
        public readonly bool $structureChanged,
        public readonly int $tables,
        public readonly int $views,
        public readonly int $relationshipsFound,
    ) {
    }

    public function jsonSerialize(): array
    {
        return [
            'snapshot_id' => $this->snapshotId,
            'structure_changed' => $this->structureChanged,
            'tables' => $this->tables,
            'views' => $this->views,
            'relationships_found' => $this->relationshipsFound,
            'datasets_added' => $this->datasetsAdded,
            'datasets_missing' => $this->datasetsMissing,
            'datasets_restored' => $this->datasetsRestored,
            'fields_added' => $this->fieldsAdded,
            'fields_missing' => $this->fieldsMissing,
            'relationships_added' => $this->relationshipsAdded,
            'relationships_upgraded' => $this->relationshipsUpgraded,
            'duration_ms' => $this->durationMs,
            'warnings' => $this->warnings,
        ];
    }
}
