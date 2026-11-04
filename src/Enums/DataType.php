<?php

namespace Abdalla\ReportGenerator\Enums;

enum DataType: string
{
    case String = 'string';
    case Text = 'text';
    case Integer = 'integer';
    case Decimal = 'decimal';
    case Boolean = 'boolean';
    case Date = 'date';
    case DateTime = 'datetime';
    case Time = 'time';
    case Json = 'json';

    public function isNumeric(): bool
    {
        return $this === self::Integer || $this === self::Decimal;
    }

    public function isTemporal(): bool
    {
        return $this === self::Date || $this === self::DateTime;
    }
}
