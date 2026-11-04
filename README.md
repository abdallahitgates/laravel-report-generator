# abdalla/laravel-report-generator

A semantic-model report engine for Laravel. Point it at any database, let it
scan the schema, curate the result once, and users build reports (joins,
filters, grouping, formulas, exports) without writing SQL. Built for large
datasets: work is pushed down to the database, previews are paged on the
server, and exports stream with constant memory.

The UI ships separately as [`@abdalla/report-generator-vue`](../report-generator-vue).

> Status: **M1 (schema scan + semantic model)**. Scanning, relationship
> inference, the Model Studio API and publishing are in place. The query
> engine (M2), the builder (M3), exports (M4) and report sources (M5) follow.

## Requirements

PHP 8.1+, Laravel 10 (10.48+), 11 or 12. Data sources: MySQL 5.7+/8, MariaDB
and PostgreSQL (bulk inspectors, a handful of catalog queries for the whole
schema); other Laravel drivers work through a slower generic inspector.

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
| `RG_PGSQL_SCHEMA` | search_path | PostgreSQL schema to scan. |

Without a read replica, create a second connection to the same database with
a user that only has `SELECT`, and set `RG_DATA_CONNECTION` to it:

```sql
CREATE USER 'reports_ro'@'%' IDENTIFIED BY '...';
GRANT SELECT ON procrm.* TO 'reports_ro'@'%';
```

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

## The semantic model

```bash
php artisan report-generator:scan       # read the database into the draft model
php artisan report-generator:publish    # freeze the reviewed draft as a new version
```

A scan reads every table, view, column, index and foreign key, then:

- **Classifies columns**: numbers like `amount` or `quantity` become measures,
  dates become time fields, `*_id` columns become keys (hidden, still used for
  joins). Labels come from column comments, else from the name
  (`doctor_id` → "Doctor").
- **Protects data**: columns matching `schema.sensitive_columns` are hidden
  and need an explicit confirmation to show; unsupported types (binary,
  arrays, geometry) cannot be shown; `deleted_at` is recorded so deleted rows
  are filtered out automatically.
- **Finds relationships** from three sources: foreign keys (confirmed
  automatically), Eloquent `belongsTo` / `hasOne` / `hasMany` (pending, 90%),
  and `*_id` naming conventions (pending, 50–70%). Polymorphic pairs are skipped.
- **Merges, never overwrites**: rescans add new tables and columns and refresh
  types and row estimates, but keep every label, role, visibility and review
  decision. Dropped tables and columns are flagged *missing*, not deleted.

Users only ever see a **published version**: visible datasets and fields, and
confirmed relationships. Measured on ProCRM's schema (461 tables, 7 views,
4,645 columns, MySQL 8): first scan 1.8 s, rescan 0.8 s.

## API

All paths are under the configured prefix (default `api/report-generator`).

| Method | Path | Ability | Purpose |
|---|---|---|---|
| GET | `/meta` | any user | Version, the current user's abilities, registered sources, limits |
| GET | `/model/status` | manage model | Latest scan, structure changes, current version, unpublished changes |
| POST | `/model/scan` | manage model | Scan the data connection (one at a time) |
| GET | `/model/datasets` | manage model | Datasets with field and review counts |
| GET / PATCH | `/model/datasets/{id}` | manage model | Dataset with fields and relationships / rename, hide, default date |
| PATCH | `/model/fields/{id}` | manage model | Label, role, format, aggregations, visibility |
| GET / POST | `/model/relationships` | manage model | List (filter by `status`) / add by hand |
| PATCH / DELETE | `/model/relationships/{id}` | manage model | Confirm, reject, change cardinality / delete a manual one |
| POST | `/model/relationships/bulk` | manage model | Confirm or reject many |
| GET | `/model/versions` | manage model | Published versions |
| POST | `/model/publish` | manage model | Publish the draft |

## Development

```bash
composer install
vendor/bin/pest
```

The inspector and scan tests always run on SQLite. Point them at real
databases to cover MySQL and PostgreSQL too:

```bash
RG_TEST_MYSQL_HOST=127.0.0.1 RG_TEST_MYSQL_PORT=33061 RG_TEST_MYSQL_PASSWORD=bench \
RG_TEST_PGSQL_HOST=127.0.0.1 RG_TEST_PGSQL_PORT=54329 RG_TEST_PGSQL_PASSWORD=test \
vendor/bin/pest
```

(each needs an empty `rg_test` database). CI runs the suite on PHP 8.1 +
Laravel 10, PHP 8.2 + Laravel 11 and PHP 8.3 + Laravel 12, each against
SQLite, MySQL 8 and PostgreSQL 16. A synthetic large dataset for performance
work lives in [`benchmarks/`](benchmarks).
