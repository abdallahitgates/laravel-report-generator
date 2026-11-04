<?php

namespace Abdalla\ReportGenerator\Schema;

use Abdalla\ReportGenerator\Models\SchemaSnapshotRecord;
use Abdalla\ReportGenerator\Schema\Inspectors\InspectorFactory;
use Abdalla\ReportGenerator\Schema\Relationships\EloquentRelationshipScanner;
use Abdalla\ReportGenerator\Schema\Relationships\RelationshipInferrer;
use Abdalla\ReportGenerator\Support\Tables;
use Illuminate\Database\DatabaseManager;
use Illuminate\Support\Str;

/**
 * Scan pipeline: inspect the data connection, drop excluded tables,
 * classify columns, infer relationships, store the snapshot and merge the
 * result into the draft model.
 */
final class SchemaScanner
{
    public function __construct(
        private readonly DatabaseManager $db,
        private readonly InspectorFactory $inspectors,
        private readonly RelationshipInferrer $inferrer,
        private readonly EloquentRelationshipScanner $eloquent,
        private readonly ModelSynchronizer $synchronizer,
    ) {
    }

    public function scan(?string $connectionName = null, ?bool $withEloquent = null, ?int $userId = null): ScanSummary
    {
        $started = microtime(true);
        $connection = $this->db->connection($connectionName ?? config('report-generator.data_connection'));

        $snapshot = $this->inspectors->for($connection)->inspect($connection)
            ->filter(fn (TableSchema $table) => ! $this->excluded($table->name));

        $warnings = [];
        $eloquentEdges = [];
        if ($withEloquent ?? config('report-generator.schema.eloquent.enabled', true)) {
            $eloquentEdges = $this->eloquent->scan((array) config('report-generator.schema.eloquent.paths', []));
            $warnings = $this->eloquent->warnings();
        }

        $relationships = $this->inferrer->infer($snapshot, $eloquentEdges);

        $classifier = new FieldClassifier((array) config('report-generator.schema.sensitive_columns', []));
        $drafts = [];
        $datasetKeyByTable = [];
        foreach ($snapshot->tables as $table) {
            $key = self::datasetKey($table->name);
            $datasetKeyByTable[$table->name] = $key;
            $sourceName = $snapshot->schema && $snapshot->schema !== 'public'
                ? $snapshot->schema.'.'.$table->name
                : $table->name;
            $drafts[] = $classifier->dataset($table, $key, $sourceName);
        }

        [$record, $changed] = $this->storeSnapshot($snapshot, $userId);

        $views = count(array_filter($snapshot->tables, fn (TableSchema $t) => $t->isView()));
        $summary = new ScanSummary(
            snapshotId: $record->id,
            structureChanged: $changed,
            tables: count($snapshot->tables) - $views,
            views: $views,
            relationshipsFound: count($relationships),
        );
        $summary->warnings = $warnings;

        $this->synchronizer->sync($drafts, $relationships, $datasetKeyByTable, $summary);
        $summary->durationMs = (int) round((microtime(true) - $started) * 1000);

        return $summary;
    }

    public static function datasetKey(string $table): string
    {
        $key = strtolower(preg_replace('/[^a-z0-9_]+/i', '_', $table));

        return preg_match('/^[a-z_]/', $key) ? $key : 't_'.$key;
    }

    private function excluded(string $table): bool
    {
        if (str_starts_with($table, Tables::prefix())) {
            return true;
        }

        foreach ((array) config('report-generator.schema.excluded_tables', []) as $pattern) {
            if (Str::is(strtolower($pattern), strtolower($table))) {
                return true;
            }
        }

        return false;
    }

    /** @return array{0: SchemaSnapshotRecord, 1: bool} the record and whether the structure changed */
    private function storeSnapshot(SchemaSnapshot $snapshot, ?int $userId): array
    {
        $hash = $snapshot->hash();
        $latest = SchemaSnapshotRecord::query()->orderByDesc('id')->first();

        if ($latest !== null && $latest->hash === $hash) {
            // Same structure: refresh row estimates in place rather than piling up identical snapshots.
            $latest->update(['payload' => $snapshot->toArray()]);

            return [$latest, false];
        }

        $record = SchemaSnapshotRecord::query()->create([
            'connection' => $snapshot->connection,
            'driver' => $snapshot->driver,
            'hash' => $hash,
            'payload' => $snapshot->toArray(),
            'tables_count' => count($snapshot->tables),
            'scanned_by' => $userId,
        ]);

        return [$record, true];
    }
}
