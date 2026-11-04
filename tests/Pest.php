<?php

use Abdalla\ReportGenerator\Tests\TestCase;

uses(TestCase::class)->in('Feature', 'Unit', 'Benchmark');

/**
 * Data connections the driver-specific tests run against. SQLite always;
 * MySQL and PostgreSQL when RG_TEST_MYSQL_HOST / RG_TEST_PGSQL_HOST are set.
 */
function dataConnections(): array
{
    $connections = ['sqlite' => 'testing'];

    if (getenv('RG_TEST_MYSQL_HOST')) {
        $connections['mysql'] = 'mysql_test';
    }
    if (getenv('RG_TEST_PGSQL_HOST')) {
        $connections['pgsql'] = 'pgsql_test';
    }

    return $connections;
}
