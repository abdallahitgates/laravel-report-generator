<?php

namespace Abdalla\ReportGenerator;

use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;
use Abdalla\ReportGenerator\Console\InstallCommand;
use Abdalla\ReportGenerator\Security\PolicyRegistry;
use Abdalla\ReportGenerator\Sources\SourceRegistry;

class ReportGeneratorServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__.'/../config/report-generator.php', 'report-generator');

        $this->app->singleton(PolicyRegistry::class);
        $this->app->singleton(SourceRegistry::class);
        $this->app->singleton(ReportGenerator::class);
    }

    public function boot(): void
    {
        $this->registerRoutes();

        if (config('report-generator.run_migrations', true)) {
            $this->loadMigrationsFrom(__DIR__.'/../database/migrations');
        }

        if ($this->app->runningInConsole()) {
            $this->publishes([
                __DIR__.'/../config/report-generator.php' => config_path('report-generator.php'),
            ], 'report-generator-config');

            $this->publishes([
                __DIR__.'/../database/migrations' => database_path('migrations'),
            ], 'report-generator-migrations');

            $this->commands([
                InstallCommand::class,
            ]);
        }
    }

    private function registerRoutes(): void
    {
        if (! config('report-generator.route.enabled', true) || $this->app->routesAreCached()) {
            return;
        }

        Route::group([
            'prefix' => config('report-generator.route.prefix', 'api/report-generator'),
            'middleware' => config('report-generator.route.middleware', ['api', 'auth']),
            'as' => 'report-generator.',
        ], function () {
            $this->loadRoutesFrom(__DIR__.'/../routes/api.php');
        });
    }
}
