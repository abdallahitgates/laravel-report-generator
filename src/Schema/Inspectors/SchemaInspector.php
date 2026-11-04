<?php

namespace Abdalla\ReportGenerator\Schema\Inspectors;

use Abdalla\ReportGenerator\Schema\SchemaSnapshot;
use Illuminate\Database\Connection;

interface SchemaInspector
{
    public function inspect(Connection $connection): SchemaSnapshot;
}
