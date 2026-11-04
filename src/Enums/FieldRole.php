<?php

namespace Abdalla\ReportGenerator\Enums;

enum FieldRole: string
{
    /** Something you group or filter by (name, status, category). */
    case Dimension = 'dimension';
    /** Something you aggregate (amount, quantity). */
    case Measure = 'measure';
    /** A date/datetime dimension that supports time grains. */
    case Time = 'time';
    /** Primary or foreign key: used for joins and counting, hidden by default. */
    case Key = 'key';
}
