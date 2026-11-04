<?php

namespace Abdalla\ReportGenerator\Semantic\Published;

/**
 * Read-only view of one published model version: what the query engine
 * is allowed to use. Built once per version and cached.
 */
final class SemanticModel
{
    /**
     * @param  array<string, PublishedDataset>  $datasets
     * @param  list<PublishedRelationship>  $relationships
     */
    public function __construct(
        public readonly int $version,
        public readonly array $datasets,
        public readonly array $relationships,
    ) {
    }

    public static function fromPayload(array $payload): self
    {
        $datasets = [];
        foreach ($payload['datasets'] ?? [] as $dataset) {
            $datasets[$dataset['key']] = PublishedDataset::fromArray($dataset);
        }

        return new self(
            (int) ($payload['version'] ?? 0),
            $datasets,
            array_map([PublishedRelationship::class, 'fromArray'], $payload['relationships'] ?? []),
        );
    }

    public function dataset(string $key): ?PublishedDataset
    {
        return $this->datasets[$key] ?? null;
    }

    public function relationship(int $id): ?PublishedRelationship
    {
        foreach ($this->relationships as $relationship) {
            if ($relationship->id === $id) {
                return $relationship;
            }
        }

        return null;
    }
}
