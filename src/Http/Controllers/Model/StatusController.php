<?php

namespace Abdalla\ReportGenerator\Http\Controllers\Model;

use Abdalla\ReportGenerator\Models\Dataset;
use Abdalla\ReportGenerator\Models\ModelVersion;
use Abdalla\ReportGenerator\Models\Relationship;
use Abdalla\ReportGenerator\Models\SchemaSnapshotRecord;
use Abdalla\ReportGenerator\Schema\SnapshotDiff;
use Abdalla\ReportGenerator\Semantic\ModelPublisher;
use Illuminate\Http\JsonResponse;
use Illuminate\Routing\Controller;

class StatusController extends Controller
{
    public function __invoke(ModelPublisher $publisher): JsonResponse
    {
        return new JsonResponse(self::payload($publisher));
    }

    public static function payload(ModelPublisher $publisher): array
    {
        $snapshots = SchemaSnapshotRecord::query()
            ->select(['id', 'connection', 'driver', 'tables_count', 'created_at', 'updated_at'])
            ->orderByDesc('id')->limit(2)->get();
        $latest = $snapshots->first();

        $changes = null;
        if ($snapshots->count() === 2) {
            $changes = SnapshotDiff::between(
                SchemaSnapshotRecord::query()->find($snapshots[1]->id)->snapshot(),
                SchemaSnapshotRecord::query()->find($latest->id)->snapshot(),
            );
        }

        $version = ModelVersion::current();
        $datasets = Dataset::query()->get(['id', 'is_hidden', 'meta']);

        return [
            'scanned' => $latest !== null,
            'latest_scan' => $latest ? [
                'id' => $latest->id,
                'connection' => $latest->connection,
                'driver' => $latest->driver,
                'tables_count' => $latest->tables_count,
                // updated_at moves on every scan; created_at only when the structure changed.
                'scanned_at' => $latest->updated_at?->toIso8601String(),
            ] : null,
            'last_structure_changes' => $changes,
            'current_version' => $version ? [
                'version' => $version->version,
                'published_at' => $version->published_at?->toIso8601String(),
                'notes' => $version->notes,
                'datasets_count' => count($version->payload['datasets'] ?? []),
            ] : null,
            'unpublished_changes' => $publisher->hasUnpublishedChanges(),
            'counts' => [
                'datasets' => $datasets->count(),
                'visible_datasets' => $datasets->where('is_hidden', false)->reject(fn ($d) => $d->isMissing())->count(),
                'missing_datasets' => $datasets->filter(fn ($d) => $d->isMissing())->count(),
                'pending_relationships' => Relationship::query()->where('status', Relationship::PENDING)->count(),
            ],
        ];
    }
}
