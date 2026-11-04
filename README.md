# abdalla/laravel-report-generator

A semantic-model report engine for Laravel. Point it at any database, let it
scan the schema, curate the result once, and users build reports (joins,
filters, grouping, formulas, exports) without writing SQL. Built for large
datasets: work is pushed down to the database, previews are paged on the
server, and exports stream with constant memory.

The UI ships separately as [`@abdalla/report-generator-vue`](https://github.com/abdallahitgates/report-generator-vue).

> Version **0.1.0**, milestone M3 (report builder). Scanning, Model Studio, the query engine,
> saved reports and the builder UI are in place. Exports (M4) and report
> sources (M5) follow.

## Requirements

PHP 8.1+, Laravel 10 (10.48+), 11 or 12. Data sources: MySQL 5.7+/8, MariaDB
and PostgreSQL (bulk inspectors, a handful of catalog queries for the whole
schema); other Laravel drivers work through a slower generic inspector.

## Install

The package is installed from its GitHub repository. Add the repository to
your project's `composer.json` once:

```json
"repositories": [
    { "type": "vcs", "url": "https://github.com/abdallahitgates/laravel-report-generator" }
]
```

then:

```bash
composer require abdalla/laravel-report-generator:^0.1
php artisan report-generator:install --migrate
```

While the repository is private, give Composer a read-only GitHub token once
per machine: `composer config --global github-oauth.github.com <token>`
(on servers and CI, use the `COMPOSER_AUTH` environment variable instead).

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

## Running reports

A report is a JSON definition. Fields are `dataset.field` refs from the
published model; the engine works out the joins.

```json
{
  "root": "visits",
  "parameters": [{"key": "period", "type": "date_range", "default": "this_quarter"}],
  "fields": [
    {"ref": "lines.name", "as": "line"},
    {"ref": "visits.visit_date", "grain": "month", "as": "month"},
    {"ref": "visits.__rows", "agg": "count", "as": "visits"},
    {"ref": "doctors.__rows", "agg": "count_distinct", "as": "doctors"},
    {"formula": "ROUND(visits / doctors, 2)", "as": "per_doctor"}
  ],
  "filters": {"and": [
    {"field": "visits.visit_date", "op": "between", "value": "{{period}}"},
    {"field": "visits", "op": ">", "value": 10}
  ]},
  "sort": [{"field": "visits", "dir": "desc"}],
  "subtotals": false
}
```

- **Fields**: plain (`ref`), date-bucketed (`grain`: day, week, month,
  quarter, year, hour, month_of_year, day_of_week, hour_of_day), aggregated
  (`agg`: sum, avg, min, max, count, count_distinct) or a `formula` over other
  fields. Every dataset has a virtual `__rows` field to count its rows.
- **Filters**: `=`, `!=` (keeps empty values), `>`, `>=`, `<`, `<=`, `in`,
  `not_in`, `between` (also date presets: today, yesterday, last_7_days,
  last_30_days, this/last_week, this/last_month, this/last_quarter,
  this/last_year), `contains`, `not_contains`, `starts_with`, `ends_with`,
  `is_null`, `not_null`; nested `and` / `or` up to 5 levels. A filter on a
  report field (`"field": "visits"`) filters totals (HAVING).
- **Formulas**: `+ - * / %`, comparisons, `AND OR NOT`, and `IF`, `NULLIF`,
  `COALESCE`, `ROUND`, `ABS`, `GREATEST`, `LEAST`, `LOWER`, `UPPER`, `CONCAT`,
  `SAFE_DIVIDE`. Division never truncates and never fails on zero (it gives
  an empty value). Formulas are parsed, never evaluated: only numbers and
  plain text literals reach SQL.

### How the engine keeps totals right

- **Join paths**: the shortest path from the root wins; ties go to the path
  that does not multiply rows, then to the one sharing joins already in the
  report. A real tie is an `AMBIGUOUS_PATH` error listing the options; pass
  one as `"path": [relationship ids]` (negative ids walk a relationship
  backwards).
- **Double counting**: when a one-to-many join would repeat the rows a sum or
  count adds up (doctors → visits and doctors → sales in one report), each
  branch is aggregated in its own SELECT and the results are combined
  (`UNION ALL` + group). Counts of `__rows` switch to distinct counts
  automatically. Combinations with no correct answer (sales summed per visit
  status) are refused with `FAN_TRAP`.
- **Filters across one-to-many joins** (doctors with a missed visit) compile
  to `EXISTS (...)`, so filtering never multiplies rows.
- **Soft deletes**: deleted root rows are excluded; deleted lookup rows drop
  out of the join without hiding the rows that point at them.

### Performance

- Everything is computed in the database: grouping, totals, subtotals
  (`ROLLUP` on MySQL/PostgreSQL), formulas.
- Results are paged (`page.size`, default 200, max 5,000) and `has_more` is
  found by reading one extra row, never with `COUNT(*)`.
- Drill-down (`groups.columns` / `groups.keys`, the shape of AG Grid's
  server-side row model) loads one level at a time. Opening a month group
  filters the raw date column by range, so indexes are used.
- Each statement runs under a timeout (MySQL `MAX_EXECUTION_TIME` hint,
  PostgreSQL `SET LOCAL statement_timeout`), a per-user and global
  concurrency limit, and can be cancelled from another request
  (`query_id` + `POST /query/{id}/cancel`, which runs `KILL QUERY` /
  `pg_cancel_backend`).
- Identical statements (same SQL and bindings, so the same row policies) are
  served from the cache for `cache.ttl` seconds. `EXPLAIN` estimates how many
  rows a report reads; `limits.refuse_above_est_rows` can refuse heavy
  previews.
- Every run is recorded in `rg_query_log` (duration, rows, filtered fields)
  for the index advisor.

### Row security

```php
// Rows a user may see. Applied to every query that touches the dataset,
// through joins and inside EXISTS filters.
ReportGenerator::policy('visits', fn (Builder $q, PolicyContext $ctx) =>
    $q->whereIn($ctx->column('user_id'), $ctx->user->visibleUserIds()));

// Lookup tables anyone who builds reports may read in full.
ReportGenerator::unrestricted(['products', 'lines', 'specialities']);
```

Deny by default: without the `report-generator.report.view-all` ability a
user can only query datasets that have a policy or are unrestricted
(`security.require_row_policies`).

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
| GET | `/catalog` | create reports | Datasets the user may start a report from |
| GET | `/catalog?root=visits` | create reports | Datasets reachable from the root, with fields and join path options |
| POST | `/query/validate` | create reports | Check a definition: errors, columns, mode, estimated rows |
| POST | `/query/rows` | create reports | One page of results (`definition`, `parameters`, `page`, `groups`, `query_id`) |
| POST | `/query/values` | create reports | Distinct values of a field for filter pickers (`field`, `search`) |
| POST | `/query/{id}/cancel` | create reports | Stop your own running query |

| GET | `/reports` | create reports | Library: `scope` (all, mine, shared), `search`, `folder` |
| POST | `/reports` | create reports | Save a report (`name`, `description`, `folder`, `visibility`, `definition`) |
| GET / PATCH / DELETE | `/reports/{uuid}` | owner, or shared | Open / change (owner or edit share) / delete (owner) |
| POST | `/reports/{uuid}/duplicate` | anyone who can open it | Private copy for the caller |
| PUT | `/reports/{uuid}/shares` | owner | Replace shares: `[{type: user\|role, id, permission: view\|edit}]` and `visibility` |

A report is saved only if it compiles for the person saving it. Whoever
opens a shared report sees only the rows their own policies allow. Roles for
sharing come from `getRoleNames()` (spatie/laravel-permission) or
`ReportGenerator::rolesUsing(fn ($user) => [...])`.

Errors in a definition return 422 with a stable `code` (`UNKNOWN_FIELD`,
`AMBIGUOUS_PATH`, `FAN_TRAP`, `TIMEOUT`, ...), a `path` into the definition
(`fields.2.ref`) and, where useful, `details` (path options, ...).

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

## Releasing

```bash
git tag v0.2.0
git push origin main --tags
```

Projects pick up the new version with `composer update abdalla/laravel-report-generator`
(within their constraint, e.g. `^0.1` stays on 0.1.x; change it to `^0.2` to move).
