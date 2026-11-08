<?php

namespace Abdalla\ReportGenerator\Tests\Fixtures\Combine;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

/** Shaped like ProCRM's report controllers: a filter object in, `data` rows with an id out. */
class VisitStatsController
{
    public static array $seen = [];

    public function filter(Request $request)
    {
        $visit = $request->visitFilter;
        self::$seen[] = ['payload' => $visit, 'helper' => request('visitFilter.filter'), 'user' => Auth::id()];

        $rows = $visit['filter'] == 2
            ? [
                ['id' => 1, 'line' => 'North', 'division' => 'Cairo', 'employee' => 'Ali', 'emp_code' => '0012', 'total_actual' => 12, 'avg_visits' => 4, 'color' => '#fff'],
                ['id' => 2, 'line' => 'North', 'division' => 'Giza', 'employee' => 'Mona', 'total_actual' => 5, 'avg_visits' => 2.5, 'color' => '#000'],
                ['id' => 3, 'line' => 'South', 'division' => 'Aswan', 'employee' => 'Omar', 'total_actual' => '7', 'avg_visits' => 3.5, 'color' => '#111'],
            ]
            : [['id' => 10, 'line' => 'North', 'division' => 'Cairo', 'employee' => 'Ali', 'total_actual' => 17, 'avg_visits' => 3, 'color' => '']];

        return response()->json(['data' => $rows, 'fields' => array_keys($rows[0])]);
    }
}
