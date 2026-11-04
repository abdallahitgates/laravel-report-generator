<?php

namespace Abdalla\ReportGenerator\Query\Definition;

use Carbon\CarbonImmutable;

/**
 * Relative date ranges, resolved when the report runs so a saved
 * "this quarter" report stays current.
 */
final class DatePresets
{
    public const NAMES = [
        'today', 'yesterday', 'last_7_days', 'last_30_days', 'this_week', 'last_week',
        'this_month', 'last_month', 'this_quarter', 'last_quarter', 'this_year', 'last_year',
    ];

    /** @return array{0: string, 1: string}|null  inclusive [from, to] as Y-m-d H:i:s */
    public static function resolve(string $name, ?CarbonImmutable $now = null): ?array
    {
        $now ??= CarbonImmutable::instance(now());
        $today = $now->startOfDay();

        $range = match ($name) {
            'today' => [$today, $today->endOfDay()],
            'yesterday' => [$today->subDay(), $today->subDay()->endOfDay()],
            'last_7_days' => [$today->subDays(6), $today->endOfDay()],
            'last_30_days' => [$today->subDays(29), $today->endOfDay()],
            'this_week' => [$now->startOfWeek(), $now->endOfWeek()],
            'last_week' => [$now->subWeek()->startOfWeek(), $now->subWeek()->endOfWeek()],
            'this_month' => [$now->startOfMonth(), $now->endOfMonth()],
            'last_month' => [$now->startOfMonth()->subMonth(), $now->startOfMonth()->subMonth()->endOfMonth()],
            'this_quarter' => [$now->startOfQuarter(), $now->endOfQuarter()],
            'last_quarter' => [$now->startOfQuarter()->subQuarter(), $now->startOfQuarter()->subQuarter()->endOfQuarter()],
            'this_year' => [$now->startOfYear(), $now->endOfYear()],
            'last_year' => [$now->subYear()->startOfYear(), $now->subYear()->endOfYear()],
            default => null,
        };

        return $range ? [$range[0]->format('Y-m-d H:i:s'), $range[1]->format('Y-m-d H:i:s')] : null;
    }
}
