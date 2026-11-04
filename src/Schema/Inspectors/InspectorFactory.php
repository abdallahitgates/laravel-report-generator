<?php

namespace Abdalla\ReportGenerator\Schema\Inspectors;

use Illuminate\Database\Connection;

final class InspectorFactory
{
    public function for(Connection $connection): SchemaInspector
    {
        return match ($connection->getDriverName()) {
            'mysql', 'mariadb' => new MySqlInspector(),
            'pgsql' => new PostgresInspector(config('report-generator.schema.pgsql_schema')),
            default => new GenericInspector(),
        };
    }
}
