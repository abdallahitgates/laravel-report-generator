<?php

namespace Abdalla\ReportGenerator\Console;

use Abdalla\ReportGenerator\Models\Dashboard;
use Abdalla\ReportGenerator\Models\Report;
use Abdalla\ReportGenerator\Support\Tables;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Keeps the package's tables small: the query log past its retention,
 * finished runs, and reports / dashboards deleted long enough ago.
 * Schedule it daily.
 */
class PruneCommand extends Command
{
    protected $signature = 'report-generator:prune
        {--log-days= : Keep this many days of query log (default: config prune.query_log_days)}
        {--deleted-days= : Remove deleted reports and dashboards after this many days (default: config prune.deleted_days)}';

    protected $description = 'Remove old query log entries, finished runs and long-deleted reports';

    public function handle(): int
    {
        $logDays = (int) ($this->option('log-days') ?? config('report-generator.prune.query_log_days', 90));
        $deletedDays = (int) ($this->option('deleted-days') ?? config('report-generator.prune.deleted_days', 30));
        $db = DB::connection(Tables::connection());

        $log = $this->chunked($db->table(Tables::name(Tables::QUERY_LOG))->where('created_at', '<', now()->subDays($logDays)));
        $runs = $this->chunked($db->table(Tables::name(Tables::RUNS))
            ->whereIn('status', ['completed', 'failed', 'cancelled'])
            ->where('updated_at', '<', now()->subDays($logDays)));
        $reports = Report::onlyTrashed()->where('deleted_at', '<', now()->subDays($deletedDays))->get()->each->forceDelete()->count();
        $dashboards = Dashboard::onlyTrashed()->where('deleted_at', '<', now()->subDays($deletedDays))->get()->each->forceDelete()->count();

        $this->components->info("Pruned {$log} query log entries, {$runs} runs, {$reports} reports and {$dashboards} dashboards.");

        return self::SUCCESS;
    }

    /** Deletes in batches so a large log never locks the table for long. */
    private function chunked($query): int
    {
        $total = 0;
        do {
            $ids = (clone $query)->orderBy('id')->limit(5000)->pluck('id');
            if ($ids->isEmpty()) {
                break;
            }
            $total += (clone $query)->whereIn('id', $ids)->delete();
        } while ($ids->count() === 5000);

        return $total;
    }
}
