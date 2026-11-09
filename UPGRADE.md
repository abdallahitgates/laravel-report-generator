# Upgrading

Run `php artisan migrate` after every upgrade: each version's tables come
with it.

## To 0.8

- Schedule `report-generator:prune` daily.
- Optional: `limits.queue_wait_ms` (default 15 s) if your users open large
  dashboards on a busy server.

## To 0.7

- Migrations add `rg_dashboards`.
- Hosts with their own UI: widget data returns 403 `REPORT_FORBIDDEN` for
  viewers who cannot open the widget's report; `can_view` in the dashboard
  payload says so up front.

## To 0.6 / 0.5 / 0.4

- 0.4 adds publishing columns to `rg_reports`. With spatie/laravel-permission
  nothing else is needed; otherwise bind `Contracts\PermissionStore`.
- 0.5 needs `openspout/openspout` and `ext-zip` for Excel exports (CSV works
  without).
- Abilities the package does not own (a report's permission) are now
  answered by the Gate, so permission packages' Gate hooks apply.

## From 0.1 to 0.2

The scanned model is gone: the migration drops `rg_datasets`, `rg_fields`,
`rg_relationships`, `rg_model_versions` and `rg_schema_snapshots`. Create
topics with the keys the 0.1 datasets had (table names) so saved reports
keep working; join paths saved with 0.1 relationship ids must be picked
again.
