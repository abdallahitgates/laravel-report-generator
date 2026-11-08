<?php

namespace Abdalla\ReportGenerator\Query\Definition;

/**
 * The filters every report has (Period, Line, Division, Employee), applied
 * to the report's main topic through the columns it declares, and handed
 * to the row scope so structure lookups use the same period and lines.
 */
final class ReportContext
{
    /**
     * @param  list<int|string>  $lines
     * @param  list<int|string>  $divisions
     * @param  list<int|string>  $employees
     */
    public function __construct(
        public readonly ?string $from = null,
        public readonly ?string $to = null,
        public readonly array $lines = [],
        public readonly array $divisions = [],
        public readonly array $employees = [],
    ) {
    }

    public function isEmpty(): bool
    {
        return $this->from === null && $this->lines === [] && $this->divisions === [] && $this->employees === [];
    }
}
