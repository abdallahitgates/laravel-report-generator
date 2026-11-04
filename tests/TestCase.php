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
    }
}
