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
}
