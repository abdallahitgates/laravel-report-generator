# Changelog

## 0.9.0

- `Relation::through('accounts', 'Accounts', 'accountLinks', 'account')`:
  one relation over a link table, shown as one step in the builder (its
  join path is the two plain relations, so reports match either way).
- `Topic::$startable = false` keeps link tables out of the start picker.
- A topic reachable by the same join path under two names is listed once,
  under the shorter name.
- Topics refuse a relation name declared twice.

## 0.8.0

Hardening before ProCRM's first release.

- The concurrency limiter waits up to `limits.queue_wait_ms` (15 s) for a
  free slot instead of refusing at once; dashboards ask for every widget
  together.
- Streamed exports hold a slot while the file is written; combined reports
  while their reports run (and reuse a result another request built
  meanwhile).
- CSV exports prefix text Excel would run as a formula (`=`, `+`, `-`, `@`).
- Publishing requires the publisher to be able to open what they publish.
- `report-generator:prune`: query log and finished runs past
  `prune.query_log_days`, reports and dashboards deleted more than
  `prune.deleted_days` ago. Schedule it daily.

## 0.7.0 — Dashboards

- Dashboards of widgets over saved reports: KPI, bar, column, line, area,
  pie, donut, share bar, table. A widget shows data only to people who may
  open its report. Generated from a report, published like reports,
  `POST /dashboards/{uuid}/pin` to add a widget.
- Result columns carry their date grain.

## 0.6.0 — Combined reports

- `Combine\ExistingReport` adapts host report controllers; combined
  reports put their columns side by side per employee or division, with
  formulas across them (`type: combined` definitions).

## 0.5.0 — Spreadsheet grid and exports

- `summary` (row count and grand totals in one query), streamed CSV / XLSX
  exports in constant memory, report filter choices (`context/{kind}`).

## 0.4.0 — Publishing

- A permission per report (spatie/laravel-permission 5/6 or a custom
  `PermissionStore`), roles, menu entries, running saved reports without the
  build ability, named scope columns.

## 0.3.0

- Topic `conditions()`, `$lookup` (joined-only) topics.

## 0.2.0 — Topics in code

- Topics declared as PHP classes replace the database scan and Model
  Studio; `make-topic`, row scope, report context, validity spans.

## 0.1.0

- Query engine, saved reports, builder API.
