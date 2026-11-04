<?php

namespace Abdalla\ReportGenerator\Console;

use Abdalla\ReportGenerator\Schema\SchemaScanner;
use Illuminate\Console\Command;

class ScanCommand extends Command
{
    protected $signature = 'report-generator:scan
        {--connection= : Database connection to scan (default: report-generator.data_connection)}
        {--no-eloquent : Skip reading relationships from Eloquent models}';

    protected $description = 'Scan the database and merge tables, columns and relationships into the draft model';

    public function handle(SchemaScanner $scanner): int
    {
        $summary = $scanner->scan(
            connectionName: $this->option('connection') ?: null,
            withEloquent: $this->option('no-eloquent') ? false : null,
        );

        $this->components->info(sprintf(
            'Scanned %d tables and %d views in %s ms%s.',
            $summary->tables, $summary->views, number_format($summary->durationMs),
            $summary->structureChanged ? '' : ' (structure unchanged since the last scan)'
        ));

        $this->table(['', 'Added', 'Missing / upgraded'], [
            ['Datasets', $summary->datasetsAdded, $summary->datasetsMissing],
            ['Fields', $summary->fieldsAdded, $summary->fieldsMissing],
            ['Relationships', $summary->relationshipsAdded, $summary->relationshipsUpgraded],
        ]);

        foreach ($summary->warnings as $warning) {
            $this->components->warn($warning);
        }

        $this->components->bulletList([
            'Review the result in Model Studio, then publish it (or run report-generator:publish).',
        ]);

        return self::SUCCESS;
    }
}
