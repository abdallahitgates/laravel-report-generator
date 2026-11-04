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
    | Execution limits
    |--------------------------------------------------------------------------
    */

    'limits' => [
        'preview_page_size' => 200,
        'preview_timeout_ms' => 15_000,
        'export_timeout_ms' => 900_000,
        'max_concurrent_per_user' => 2,
        'max_concurrent_global' => 10,
        // EXPLAIN row estimate above which a query must run as a background job.
        'async_above_est_rows' => 2_000_000,
    ],

    'cache' => [
        'store' => env('RG_CACHE_STORE'),
        'ttl' => 600,
    ],

    'exports' => [
        'disk' => env('RG_EXPORT_DISK', 'local'),
        'directory' => 'report-generator/exports',
        'queue' => env('RG_EXPORT_QUEUE', 'default'),
        'chunk' => 5_000,
        'keep_hours' => 72,
    ],

    /*
    |--------------------------------------------------------------------------
    | Schema scanning
    |--------------------------------------------------------------------------
    |
    | Tables matching `excluded_tables` are never offered as datasets.
    | Columns matching `sensitive_columns` are hidden and flagged; only an
    | admin can expose them explicitly. Patterns use Str::is() wildcards and
    | are matched case-insensitively. The package's own tables are always
    | excluded.
    |
    */

    'schema' => [
        'excluded_tables' => [
            'migrations', 'failed_jobs', 'jobs', 'job_batches', 'cache', 'cache_locks',
            'sessions', 'password_resets', 'password_reset_tokens', 'personal_access_tokens',
            'oauth_*', 'telescope_*', 'horizon_*', 'pulse_*',
        ],
        'sensitive_columns' => [
            '*password*', '*token*', '*secret*', 'remember_*', '*api_key*', '*private_key*',
            'two_factor_*',
        ],
        // PostgreSQL schema to scan. Null = first entry of the connection's
        // search_path, else "public".
        'pgsql_schema' => env('RG_PGSQL_SCHEMA'),

        // Read belongsTo / hasOne / hasMany definitions from Eloquent models
        // to find relationships the database has no foreign keys for.
        // Paths are relative to base_path(); the value says whether to recurse.
        'eloquent' => [
            'enabled' => true,
            'paths' => [
                'app/Models' => true,
                'app' => false,
            ],
        ],
    ],

];
