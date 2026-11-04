<?php

namespace Abdalla\ReportGenerator\Query\Compiler;

use Abdalla\ReportGenerator\Query\Compiler\Dialects\MySqlDialect;
use Abdalla\ReportGenerator\Query\Compiler\Dialects\PostgresDialect;
use Abdalla\ReportGenerator\Query\Compiler\Dialects\SqliteDialect;
use Abdalla\ReportGenerator\Query\QueryException;
use Illuminate\Database\Connection;

final class DialectFactory
{
    public function for(Connection $connection): Dialect
    {
        return match ($connection->getDriverName()) {
            'mysql', 'mariadb' => new MySqlDialect(),
            'pgsql' => new PostgresDialect(),
            'sqlite' => new SqliteDialect(),
            default => throw new QueryException(
                'UNSUPPORTED_DATABASE',
                'Reports can run on MySQL, MariaDB, PostgreSQL and SQLite; this connection uses '.$connection->getDriverName().'.',
                status: 500,
            ),
        };
    }
}
