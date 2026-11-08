# abdalla/laravel-report-generator

A report engine for Laravel. Describe what people may report on as **topics**
(PHP classes: fields with labels, relations to other topics, and the
employee / division / line / date columns every report is filtered by), and
users build reports (joins, filters, grouping, formulas, exports) without
writing SQL. Built for large
datasets: work is pushed down to the database, previews are paged on the
server, and exports stream with constant memory.

The UI ships separately as [`@abdalla/report-generator-vue`](https://github.com/abdallahitgates/report-generator-vue).

> Version **0.5.0** (v2 phase P3). Topics defined in code replace the
> database scan and Model Studio of 0.1. The query engine, saved reports and
> the builder UI are unchanged.

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
| `RG_PGSQL_SCHEMA` | search_path | PostgreSQL schema `make-topic` reads. |

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

// 2. Which employees / divisions each user may see (see "Row security").
ReportGenerator::scopeUsing(\App\Reporting\StructureScope::class);

// 3. Existing code-computed reports as datasets (implement ReportSource).
ReportGenerator::source(\App\Reports\SalesIncentiveSource::class);
```

Abilities: `report-generator.model.manage`, `report-generator.report.create`,
`report-generator.report.export`, `report-generator.report.view-all`,
`report-generator.report.publish`.

## Report filters

Every report can be limited by period, lines, divisions and employees on
the main topic's columns (`context` in the definition, or what the viewer
picks). Tell the package which topic lists each, for the pickers:

```php
// config/report-generator.php
'context' => [
    'lines' => ['topic' => 'line', 'label' => 'name'],
    'divisions' => ['topic' => 'line_division', 'label' => 'name'],
    'employees' => ['topic' => 'user', 'label' => 'name'],
],
```

The choices follow the user's row scope, the chosen lines and the period.

## Publishing reports

A saved report can be published: it gets a permission named after it
(suggested `show_reports_<report name>`), the chosen roles get that
permission, and it can be listed in the menu. Everyone holding the
permission can open and run it (`/reports/{uuid}/view`, `/rows`, `/values`)
without being able to build reports, always with their own row scope; they
pick the period, lines, divisions and employees, and the report's own
parameters.

Permissions live in spatie/laravel-permission (5 or 6) out of the box, on
`permissions.guard` (default: the default auth guard). Other systems
implement `Contracts\PermissionStore` and set `permissions.store`.

## Topics

Topics live in `app/Reporting/Topics` (`topics.paths` in the config) and are
discovered automatically; `ReportGenerator::topics([...])` registers others.
Each topic is listed under a tab (`$group`) and starts its own reports.

```php
namespace App\Reporting\Topics;

use App\ActualVisit;
use Abdalla\ReportGenerator\Topics\{Field, Relation, Topic};

class ActualVisitsTopic extends Topic
{
    public string $model = ActualVisit::class;   // table, key, soft deletes
    public ?string $group = 'Visits';

    // The fixed filters every report has (Period, Line, Division, Employee),
    // and the columns the row scope restricts.
    public ?string $employeeColumn = 'user_id';
    public ?string $divisionColumn = 'div_id';
    public ?string $lineColumn = 'line_id';
    public ?string $dateColumn = 'visit_date';

    public function fields(): array
    {
        return [
            Field::count('Number of visits'),
            Field::date('visit_date', 'Visit date'),
            Field::enum('status', 'Status', ['done' => 'Done', 'missed' => 'Missed']),
            Field::money('amount', 'Amount'),                  // sum, avg, min, max
            Field::integer('duration', 'Duration')->totals(['sum', 'avg']),
            Field::text('ll', 'Latitude')->visibleTo('admin'), // per role
            Field::id('account_id', 'Account ID'),             // hidden, for joins
        ];
    }

    public function relations(): array
    {
        return [
            Relation::belongsTo('employee', 'Employee', UsersTopic::class, 'user_id'),
            Relation::belongsTo('account', 'Account', AccountsTopic::class, 'account_id'),
            Relation::hasMany('products', 'Products', ActualVisitProductsTopic::class, 'visit_id'),
        ];
    }
}
```

Draft one from a model, then edit labels and remove what reports should not
offer:

```bash
php artisan report-generator:make-topic ActualVisits --model="App\ActualVisit" --group=Visits
```

It reads the table once: columns become fields with types and labels
(sensitive columns such as passwords are left out), `belongsTo` / `hasOne` /
`hasMany` methods become relations (commented out until the target topic
exists), and `user_id`, `div_id` / `line_division_id`, `line_id` and the main
date are suggested as the filter columns.

- **Keys**: a topic's key is its class name in snake case without `Topic`
  (`ActualVisitsTopic` → `actual_visits`); set `$key` to keep saved reports
  working after a rename.
- **Relations** are declared from either side, and stored once. Builders walk
  them as a tree up to `topics.max_depth` levels from the report's topic.
- **Validity spans**: for rows valid from one date to another (structure
  assignments), set `$validFromColumn = 'from_date'` and
  `$validToColumn = 'to_date'` instead of `$dateColumn`: the report period
  then keeps rows valid at any time in it (an empty "to" means still valid).
  `make-topic` sets them when a table has both columns.
- **Fixed conditions**: `conditions()` returns `column => value` pairs every
  row of the topic meets (a list means IN, `null` means IS NULL), applied
  wherever the topic appears, like soft deletes. One polymorphic table can
  then back several topics:

  ```php
  class PlanVisitApprovalsTopic extends Topic
  {
      public string $model = PlanVisitDetails::class;

      public function conditions(): array
      {
          return ['visitable_type' => PlanVisit::class];
      }
  }
  ```
- **Lookups** anyone may read in full: `public bool $public = true`.
- **Joined-only topics**: `public bool $lookup = true` lets users read a
  topic through a relation from a topic they may read (the distributor sale
  behind a division's sales line) but not start a report from it.
- **Topic permission**: `public ?string $permission = 'reports.sales'` (a
  Gate ability) hides a topic from users without it.

## Running reports

A report is a JSON definition. Fields are `topic.field` refs; the engine
works out the joins. `context` holds the fixed report filters: `period` (a
preset such as `this_month`, `[from, to]` or a parameter) on the topic's
`$dateColumn`, and `lines` / `divisions` / `employees` ids on its line,
division and employee columns.

```json
{
  "root": "visits",
  "parameters": [{"key": "period", "type": "date_range", "default": "this_quarter"}],
  "context": {"period": "{{period}}", "lines": [1], "divisions": [], "employees": []},
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
- `summary` counts the whole result and works out grand totals in one
  query over it: sums and counts add up, min / max of min / max; averages,
  distinct counts and formulas get no total rather than a wrong one.
- Exports stream: rows are read unbuffered (MySQL) or through a server-side
  cursor (PostgreSQL) and written to the response as CSV (with a UTF-8 BOM
  for Excel) or XLSX (openspout, needs ext-zip). On the benchmark, all
  5,000,000 visits go to CSV in about 12 s with peak memory up 6 MB.
- Every run is recorded in `rg_query_log` (duration, rows, filtered fields)
  for the index advisor.

### Row security

The row scope decides which employees and divisions a user sees. It runs on
every topic that declares an employee or division column, wherever the
topic appears (the main topic, joins, `EXISTS` filters), and gets the report's
period and lines, because structure assignments change over time:

```php
use Abdalla\ReportGenerator\Contracts\RowScope;
use Abdalla\ReportGenerator\Security\ScopeContext;
use Illuminate\Database\Query\Builder;

class StructureScope implements RowScope
{
    public function apply(Builder $query, ScopeContext $ctx): void
    {
        $users = $ctx->user->visibleUserIds($ctx->lines, $ctx->from, $ctx->to);

        if ($column = $ctx->employeeColumn()) {   // qualified: alias.user_id
            $query->whereIn($column, $users);
        } elseif ($column = $ctx->divisionColumn()) {
            $query->whereIn($column, $ctx->user->visibleDivisionIds($ctx->lines, $ctx->from, $ctx->to));
        }
    }
}
```

Topics can expose further columns to the scope by name, for rules beyond
employee and division, e.g. products a user is limited to:

```php
public array $scopeColumns = ['product' => 'product_id'];

// in the scope
if ($column = $ctx->scopeColumn('product')) {
    $query->whereIn($column, $allowedProductIds);
}
```

Users with `report-generator.report.view-all` (admins) skip the scope. Other
users can only query topics that are scoped (with a scope registered),
public, covered by a `ReportGenerator::policy()` or listed in
`ReportGenerator::unrestricted([...])`. Fields marked `visibleTo()` /
`canSee()` do not exist for anyone else.

## API

All paths are under the configured prefix (default `api/report-generator`).

| Method | Path | Ability | Purpose |
|---|---|---|---|
| GET | `/meta` | any user | Version, the current user's abilities, registered sources, limits |
| GET | `/topics` | manage model | Every topic by tab, with table, filter columns, fields and relations |
| GET | `/catalog` | create reports | Topics the user may start a report from, by tab |
| GET | `/catalog?root=visits` | create reports | The topic's relation tree (fields and join path per node), plus a flat list |
| POST | `/query/validate` | create reports | Check a definition: errors, columns, mode, estimated rows |
| POST | `/query/rows` | create reports | One page of results (`definition`, `parameters`, `page`, `groups`, `query_id`) |
| POST | `/query/summary` | create reports | Row count and grand totals of a definition |
| POST | `/query/export` | create + export | A definition as `csv` or `xlsx`, streamed |
| POST | `/query/values` | create reports | Distinct values of a field for filter pickers (`field`, `search`) |
| POST | `/query/{id}/cancel` | create reports | Stop your own running query |

| GET | `/menu` | any user | Published reports in the menu the user may open, with their menu group |
| GET | `/reports/{uuid}/view` | may open it | Name, saved report filters and parameters |
| POST | `/reports/{uuid}/rows` | may open it | Run it: `context` (period, lines, divisions, employees), `parameters`, `page`, `groups` |
| POST | `/reports/{uuid}/values` | may open it | Filter values for the report's own fields |
| POST | `/reports/{uuid}/summary` | may open it | Row count and grand totals of the whole report (same body as `/rows`) |
| POST | `/reports/{uuid}/export` | may open it + export | The whole report as `format` `csv` or `xlsx`, streamed |
| GET | `/context/{lines\|divisions\|employees}` | any user | Choices for the report filters the user may see (`search`, `lines[]`, `period`) |
| GET | `/permissions/roles` | publish | Roles a report can be given to |
| GET / PUT / DELETE | `/reports/{uuid}/publish` | publish | Publishing settings (with a suggested permission) / publish: `permission`, `roles`, `in_menu`, `menu_group` / unpublish |
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

The tests always run on SQLite. Point them at real
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
git tag v0.3.0
git push origin main --tags
```

Projects pick up the new version with `composer update abdalla/laravel-report-generator`
(within their constraint, e.g. `^0.1` stays on 0.1.x; change it to `^0.2` to move).

### Upgrading from 0.1

Run the migrations: they drop `rg_datasets`, `rg_fields`, `rg_relationships`,
`rg_model_versions` and `rg_schema_snapshots`. Create topics with the keys the
0.1 datasets had (table names) so saved reports keep working; join paths saved
with 0.1 relationship ids must be picked again.
