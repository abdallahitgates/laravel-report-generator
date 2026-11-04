<?php

namespace Abdalla\ReportGenerator\Console;

use Illuminate\Console\Command;

class InstallCommand extends Command
{
    protected $signature = 'report-generator:install
        {--migrate : Run the package migrations after publishing}
        {--force : Overwrite an already published config file}';

    protected $description = 'Publish the Report Generator config and prepare its tables';

    public function handle(): int
    {
        $this->components->info('Installing Report Generator');

        $this->components->task('Publishing config/report-generator.php', fn () => $this->callSilently('vendor:publish', array_filter([
            '--tag' => 'report-generator-config',
            '--force' => $this->option('force') ?: null,
        ])) === self::SUCCESS);

        if ($this->option('migrate')) {
            $this->call('migrate', ['--force' => true]);
        }

        $this->newLine();
        $this->components->bulletList([
            'Set RG_DATA_CONNECTION to a read replica or read-only connection (recommended for production).',
            'Set RG_AUTH_MIDDLEWARE if your API uses another guard, e.g. auth:api or auth:sanctum.',
            'Grant abilities with Gate::define(\'report-generator.model.manage\', ...) and friends.',
            'Register row policies and report sources with the ReportGenerator facade in a service provider.',
            $this->option('migrate') ? 'Tables are ready.' : 'Run php artisan migrate to create the rg_* tables.',
        ]);

        return self::SUCCESS;
    }
}
