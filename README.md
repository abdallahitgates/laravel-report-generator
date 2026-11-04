# abdalla/laravel-report-generator

A semantic-model report engine for Laravel. Point it at any database, let it
scan the schema, curate the result once, and users build reports (joins,
filters, grouping, formulas, exports) without writing SQL. Built for large
datasets: work is pushed down to the database, previews are paged on the
server, and exports stream with constant memory.

The UI ships separately as [`@abdalla/report-generator-vue`](../report-generator-vue).

> Status: **M0 (foundation)**. Config, tables, registries, authorization and
> the bootstrap endpoint are in place. Schema scanning (M1), the query engine
> (M2), the builder (M3), exports (M4) and report sources (M5) follow.

## Requirements

PHP 8.1+, Laravel 10, 11 or 12. MySQL 8 is the first supported data source.

## Install

```bash
composer require abdalla/laravel-report-generator
php artisan report-generator:install --migrate
```

In a monorepo, use a path repository (see `laravel/composer.json` in ProCRM).

## Configure

`config/report-generator.php` (published by the install command). The common
settings have env variables:

| Env | Default | Meaning |
|---|---|---|
| `RG_DATA_CONNECTION` | default connection | Connection reports read from. Use a read replica or a read-only user in production. |
| `RG_META_CONNECTION` | default connection | Where the package's `rg_*` tables live. |
| `RG_TABLE_PREFIX` | `rg_` | Prefix for the package's tables. |
| `RG_ROUTE_PREFIX` | `api/report-generator` | API prefix. |
| `RG_AUTH_MIDDLEWARE` | `auth` | Auth middleware for the API, e.g. `auth:api`, `auth:sanctum`. |
| `RG_AUTHORIZE_BY_DEFAULT` | `false` | Answer for abilities the host has not defined a Gate for. |

## Hook it into your app

```php
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\Gate;
use Abdalla\ReportGenerator\Facades\ReportGenerator;
use Abdalla\ReportGenerator\Security\PolicyContext;

// AppServiceProvider::boot()

// 1. Who may do what (any Gate-based system works, including spatie/laravel-permission).
Gate::define('report-generator.model.manage', fn ($user) => $user->is_admin);
Gate::define('report-generator.report.create', fn ($user) => true);
Gate::define('report-generator.report.export', fn ($user) => true);

// 2. Row-level security. Runs whenever the dataset is in a query, including
//    through a join. Always qualify columns with $ctx->column(): the engine
//    aliases tables in generated SQL.
ReportGenerator::policy('visits', fn (Builder $q, PolicyContext $ctx) =>
    $q->whereIn($ctx->column('user_id'), $ctx->user->visibleUserIds()));

// 3. Existing code-computed reports as datasets (implement ReportSource).
ReportGenerator::source(\App\Reports\SalesIncentiveSource::class);
```

Abilities: `report-generator.model.manage`, `report-generator.report.create`,
`report-generator.report.export`, `report-generator.report.view-all`.

## API

| Method | Path | Purpose |
|---|---|---|
| GET | `/meta` | Version, the current user's abilities, registered sources, limits |

## Development

```bash
composer install
vendor/bin/pest
```

CI runs the suite on PHP 8.1 + Laravel 10, PHP 8.2 + Laravel 11 and
PHP 8.3 + Laravel 12. A synthetic large dataset for performance work lives in
[`benchmarks/`](benchmarks).
