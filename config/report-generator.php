<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Connections
    |--------------------------------------------------------------------------
    |
    | `data_connection` is the database reports read from. In production use
    | a read replica if you have one; otherwise define a second connection to
    | the same database with a read-only user (GRANT SELECT only), so a report
    | can never write, and point this at it. `metadata_connection` holds the package's
    | own rg_* tables (saved reports, runs, semantic model). Null means the
    | application's default connection.
    |
    */

    'data_connection' => env('RG_DATA_CONNECTION'),

    'metadata_connection' => env('RG_META_CONNECTION'),

    'table_prefix' => env('RG_TABLE_PREFIX', 'rg_'),

    'run_migrations' => true,

    /*
    |--------------------------------------------------------------------------
    | HTTP API
    |--------------------------------------------------------------------------
    |
    | The Vue package talks to these routes. `auth` uses the application's
    | default guard; use e.g. "auth:api" or "auth:sanctum" to pick another.
    |
    */

    'route' => [
        'enabled' => true,
        'prefix' => env('RG_ROUTE_PREFIX', 'api/report-generator'),
        'middleware' => array_values(array_filter([
            'api',
            env('RG_AUTH_MIDDLEWARE', 'auth'),
        ])),
    ],

    /*
    |--------------------------------------------------------------------------
    | Authorization
    |--------------------------------------------------------------------------
    |
    | Abilities are checked through Laravel's Gate (so Spatie permissions or
    | any Gate-based system works). When the host app defines no Gate for an
    | ability and registers no custom authorizer, this value is the answer.
    |
    */

    'authorization' => [
        'default' => (bool) env('RG_AUTHORIZE_BY_DEFAULT', false),
    ],

    /*
    |--------------------------------------------------------------------------
    | Row security
    |--------------------------------------------------------------------------
    |
    | Deny by default: a user without the report-generator.report.view-all
    | ability can only query datasets that have a row policy
    | (ReportGenerator::policy) or are listed as unrestricted here or with
    | ReportGenerator::unrestricted() (lookup tables such as products).
    | A forgotten policy therefore hides data instead of leaking it.
    |
    */

    'security' => [
        'require_row_policies' => true,
        'unrestricted_datasets' => [],
    ],

    /*
    |--------------------------------------------------------------------------
    | Execution limits
    |--------------------------------------------------------------------------
    */

    'limits' => [
        'preview_page_size' => 200,
        'max_page_size' => 5_000,
        'preview_timeout_ms' => 15_000,
        'export_timeout_ms' => 900_000,
        'max_concurrent_per_user' => 2,
        'max_concurrent_global' => 10,
        // Run EXPLAIN before a preview to report how many rows it will read.
        'explain' => true,
        // Refuse previews whose EXPLAIN estimate reads more rows than this (null = never refuse).
        'refuse_above_est_rows' => null,
        // EXPLAIN row estimate above which a query must run as a background job (exports, M4).
        'async_above_est_rows' => 2_000_000,
    ],

    'cache' => [
        // Any Laravel cache store; it also holds the concurrency locks, so it must support locks.
        'store' => env('RG_CACHE_STORE'),
        'ttl' => 600,
    ],

    'query_log' => true,

    'exports' => [
        'disk' => env('RG_EXPORT_DISK', 'local'),
        'directory' => 'report-generator/exports',
        'queue' => env('RG_EXPORT_QUEUE', 'default'),
        'chunk' => 5_000,
        'keep_hours' => 72,
    ],

    /*
    |--------------------------------------------------------------------------
    | Topics
    |--------------------------------------------------------------------------
    |
    | Topic classes are discovered in these folders (path => namespace) and
    | can also be registered with ReportGenerator::topics([...]). The
    | builder offers relations up to `max_depth` hops from a report's topic.
    |
    */

    'topics' => [
        'paths' => [
            'app/Reporting/Topics' => 'App\\Reporting\\Topics',
        ],
        'max_depth' => 3,
    ],

    /*
    |--------------------------------------------------------------------------
    | make-topic
    |--------------------------------------------------------------------------
    |
    | Used by `php artisan report-generator:make-topic` when it drafts a topic
    | from a model: columns matching these patterns are left out.
    |
    */

    'schema' => [
        'sensitive_columns' => [
            '*password*', '*token*', '*secret*', 'remember_*', '*api_key*', '*private_key*',
            'two_factor_*',
        ],
        'pgsql_schema' => env('RG_PGSQL_SCHEMA'),
    ],

];
