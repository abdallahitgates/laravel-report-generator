<?php

namespace Abdalla\ReportGenerator\Enums;

/**
 * Gate abilities the package checks. Hosts grant them through Gate::define()
 * (or a permission package that hooks the Gate, e.g. spatie/laravel-permission).
 */
enum Ability: string
{
    case ManageModel = 'report-generator.model.manage';
    case CreateReports = 'report-generator.report.create';
    case ExportReports = 'report-generator.report.export';
    case ViewAllReports = 'report-generator.report.view-all';

    /** Short camelCase name used in API payloads, e.g. "manageModel". */
    public function key(): string
    {
        return lcfirst($this->name);
    }

    public static function fromKey(string $key): self
    {
        foreach (self::cases() as $case) {
            if ($case->key() === $key || $case->value === $key) {
                return $case;
            }
        }

        throw new \InvalidArgumentException("Unknown report generator ability [{$key}].");
    }
}
