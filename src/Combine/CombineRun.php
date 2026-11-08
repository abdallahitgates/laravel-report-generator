<?php

namespace Abdalla\ReportGenerator\Combine;

use Illuminate\Contracts\Auth\Authenticatable;

/**
 * What a combined report asks every report for: one level (employee or
 * division), one period, the lines (never empty: the user's lines when the
 * viewer picked none), and optional divisions / employees.
 */
final class CombineRun
{
    /**
     * @param  list<int>  $lines
     * @param  list<int>  $divisions
     * @param  list<int>  $employees
     */
    public function __construct(
        public readonly string $level,
        public readonly string $from,
        public readonly string $to,
        public readonly array $lines,
        public readonly array $divisions,
        public readonly array $employees,
        public readonly ?Authenticatable $user,
    ) {
    }

    public function byEmployee(): bool
    {
        return $this->level === 'employee';
    }

    /** Y-m-d of the period start / end. */
    public function fromDate(): string
    {
        return substr($this->from, 0, 10);
    }

    public function toDate(): string
    {
        return substr($this->to, 0, 10);
    }

    public function key(): string
    {
        return implode('|', [$this->level, $this->from, $this->to, implode(',', $this->lines), implode(',', $this->divisions), implode(',', $this->employees), $this->user?->getAuthIdentifier()]);
    }
}
