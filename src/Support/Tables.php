<?php

namespace Abdalla\ReportGenerator\Support;

/**
 * Resolves the package's own table names and metadata connection, so the
 * configurable prefix and connection are applied in exactly one place.
 */
final class Tables
{
    public const SCHEMA_SNAPSHOTS = 'schema_snapshots';
    public const MODEL_VERSIONS = 'model_versions';
    public const DATASETS = 'datasets';
    public const FIELDS = 'fields';
    public const RELATIONSHIPS = 'relationships';
    public const REPORTS = 'reports';
    public const REPORT_SHARES = 'report_shares';
    public const RUNS = 'runs';
    public const QUERY_LOG = 'query_log';

    public const ALL = [
        self::SCHEMA_SNAPSHOTS,
        self::MODEL_VERSIONS,
        self::DATASETS,
        self::FIELDS,
        self::RELATIONSHIPS,
        self::REPORTS,
        self::REPORT_SHARES,
        self::RUNS,
        self::QUERY_LOG,
    ];

    public static function name(string $table): string
    {
        return self::prefix().$table;
    }

    public static function prefix(): string
    {
        return (string) config('report-generator.table_prefix', 'rg_');
    }

    public static function connection(): ?string
    {
        return config('report-generator.metadata_connection');
    }
}
