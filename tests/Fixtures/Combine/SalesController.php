<?php

namespace Abdalla\ReportGenerator\Tests\Fixtures\Combine;

use Illuminate\Http\Request;

/** A per-line report ({line} in the route), with achievement as "45.5%" strings. */
class SalesController
{
    public static array $lines = [];

    public static bool $fail = false;

    public function filter(Request $request, string $line)
    {
        self::$lines[] = $line;
        if (self::$fail) {
            throw new \RuntimeException('database is down');
        }

        $rows = $line === 'L1'
            ? [
                ['id' => 1, 'employee' => 'Ali', 'product' => 'A', 'value' => '1,000.25', 'achievement' => '70%'],
                ['id' => 1, 'employee' => 'Ali', 'product' => 'B', 'value' => '500.25', 'achievement' => '80%'],
                ['id' => 4, 'employee' => 'Sara', 'product' => 'A', 'value' => 300, 'achievement' => '30.5%'],
            ]
            : [['id' => 3, 'employee' => 'Omar', 'value' => 0, 'achievement' => '0%']];

        return ['data' => $rows];
    }
}
