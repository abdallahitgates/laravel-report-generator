<?php

namespace Abdalla\ReportGenerator\Enums;

enum Aggregation: string
{
    case Sum = 'sum';
    case Avg = 'avg';
    case Min = 'min';
    case Max = 'max';
    case Count = 'count';
    case CountDistinct = 'count_distinct';

    /**
     * Aggregations that can be re-aggregated from partial results
     * (matters for subtotals and, later, pre-aggregated rollups).
     */
    public function isAdditive(): bool
    {
        return in_array($this, [self::Sum, self::Min, self::Max, self::Count], true);
    }

    /** @return list<self> */
    public static function forType(DataType $type): array
    {
        if ($type->isNumeric()) {
            return self::cases();
        }

        if ($type->isTemporal()) {
            return [self::Min, self::Max, self::Count, self::CountDistinct];
        }

        return [self::Count, self::CountDistinct];
    }
}
