<?php

namespace Abdalla\ReportGenerator\Tests;

use Orchestra\Testbench\TestCase as Orchestra;
use Abdalla\ReportGenerator\ReportGeneratorServiceProvider;

abstract class TestCase extends Orchestra
{
    protected function getPackageProviders($app): array
    {
        return [ReportGeneratorServiceProvider::class];
    }

    protected function getPackageAliases($app): array
    {
        return ['ReportGenerator' => \Abdalla\ReportGenerator\Facades\ReportGenerator::class];
    }

    protected function defineEnvironment($app): void
    {
        $app['config']->set('database.default', 'testing');
        $app['config']->set('database.connections.testing', [
            'driver' => 'sqlite',
            'database' => ':memory:',
            'prefix' => '',
            'foreign_key_constraints' => true,
        ]);

        // Optional real databases for driver-specific tests (see README / CI).
        if (env('RG_TEST_MYSQL_HOST')) {
            $app['config']->set('database.connections.mysql_test', [
                'driver' => 'mysql',
                'host' => env('RG_TEST_MYSQL_HOST'),
                'port' => env('RG_TEST_MYSQL_PORT', 3306),
                'database' => env('RG_TEST_MYSQL_DATABASE', 'rg_test'),
                'username' => env('RG_TEST_MYSQL_USERNAME', 'root'),
                'password' => env('RG_TEST_MYSQL_PASSWORD', ''),
                'charset' => 'utf8mb4',
                'collation' => 'utf8mb4_unicode_ci',
                'prefix' => '',
            ]);
        }

        if (env('RG_BENCH_MYSQL_HOST')) {
            $app['config']->set('database.connections.mysql_bench', [
                'driver' => 'mysql',
                'host' => env('RG_BENCH_MYSQL_HOST'),
                'port' => env('RG_BENCH_MYSQL_PORT', 33061),
                'database' => env('RG_BENCH_MYSQL_DATABASE', 'rg_bench'),
                'username' => env('RG_BENCH_MYSQL_USERNAME', 'root'),
                'password' => env('RG_BENCH_MYSQL_PASSWORD', 'bench'),
                'charset' => 'utf8mb4',
                'collation' => 'utf8mb4_unicode_ci',
                'prefix' => '',
            ]);
        }

        if (env('RG_TEST_PGSQL_HOST')) {
            $app['config']->set('database.connections.pgsql_test', [
                'driver' => 'pgsql',
                'host' => env('RG_TEST_PGSQL_HOST'),
                'port' => env('RG_TEST_PGSQL_PORT', 5432),
                'database' => env('RG_TEST_PGSQL_DATABASE', 'rg_test'),
                'username' => env('RG_TEST_PGSQL_USERNAME', 'postgres'),
                'password' => env('RG_TEST_PGSQL_PASSWORD', ''),
                'charset' => 'utf8',
                'prefix' => '',
                'search_path' => 'public',
            ]);
        }
    }
}
