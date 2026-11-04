<?php

namespace Abdalla\ReportGenerator\Semantic\Published;

use Abdalla\ReportGenerator\Models\ModelVersion;
use Abdalla\ReportGenerator\Query\QueryException;

/**
 * Hands the engine the current published model. The payload of a version
 * never changes, so it is parsed once per process per version; only the
 * cheap "which version is current" lookup hits the database.
 */
final class SemanticModelRepository
{
    /** @var array<int, SemanticModel> */
    private array $parsed = [];

    public function current(): SemanticModel
    {
        $version = (int) ModelVersion::query()->max('version');
        if ($version === 0) {
            throw new QueryException('MODEL_NOT_PUBLISHED', 'No data model has been published yet. An admin needs to scan the database and publish it in Model Studio.');
        }

        return $this->parsed[$version] ??= SemanticModel::fromPayload(
            ModelVersion::query()->where('version', $version)->value('payload') ?? [],
        );
    }

    public function forget(): void
    {
        $this->parsed = [];
    }
}
