<?php

namespace Abdalla\ReportGenerator\Combine;

use Abdalla\ReportGenerator\Contracts\CombinableReport;
use Closure;

/**
 * The existing reports that can be combined. Hosts register instances, or a
 * closure returning them so nothing is built until a combined report runs.
 */
final class CombineRegistry
{
    /** @var list<CombinableReport|Closure> */
    private array $pending = [];

    /** @var array<string, CombinableReport>|null */
    private ?array $reports = null;

    /** @param  iterable<CombinableReport>|Closure(): iterable<CombinableReport>|CombinableReport  $reports */
    public function register(iterable|Closure|CombinableReport $reports): void
    {
        $this->pending[] = $reports;
        $this->reports = null;
    }

    /** @return array<string, CombinableReport> by key */
    public function all(): array
    {
        if ($this->reports !== null) {
            return $this->reports;
        }

        $reports = [];
        foreach ($this->pending as $entry) {
            $list = $entry instanceof Closure ? $entry() : $entry;
            foreach ($list instanceof CombinableReport ? [$list] : $list as $report) {
                if (isset($reports[$report->key()])) {
                    throw new \LogicException("Two combinable reports use the key [{$report->key()}].");
                }
                $reports[$report->key()] = $report;
            }
        }

        return $this->reports = $reports;
    }

    public function get(string $key): ?CombinableReport
    {
        return $this->all()[$key] ?? null;
    }

    public function forget(): void
    {
        $this->pending = [];
        $this->reports = null;
    }
}
