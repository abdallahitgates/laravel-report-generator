<?php

use Abdalla\ReportGenerator\Http\Controllers\CombineController;
use Abdalla\ReportGenerator\Http\Controllers\Dashboards\DashboardController;
use Abdalla\ReportGenerator\Http\Controllers\ExportController;
use Abdalla\ReportGenerator\Http\Controllers\MetaController;
use Abdalla\ReportGenerator\Http\Controllers\Query\QueryController;
use Abdalla\ReportGenerator\Http\Controllers\Reports\MenuController;
use Abdalla\ReportGenerator\Http\Controllers\Reports\PublishController;
use Abdalla\ReportGenerator\Http\Controllers\Reports\ReportController;
use Abdalla\ReportGenerator\Http\Controllers\Reports\RunController;
use Abdalla\ReportGenerator\Http\Controllers\Topics\TopicController;
use Illuminate\Support\Facades\Route;

// Prefix, middleware and the "report-generator." name prefix come from
// config('report-generator.route') in ReportGeneratorServiceProvider.

Route::get('meta', MetaController::class)->name('meta');

// Topics are declared in code; admins can browse them with every field and relation.
Route::get('topics', TopicController::class)->middleware('report-generator.can:manageModel')->name('topics');

// Published reports: the menu, and opening / running a saved report. Anyone
// who may open the report (owner, shares, its permission); no build ability needed.
Route::get('menu', MenuController::class)->name('menu');
Route::get('reports/{report}/view', [RunController::class, 'show'])->name('reports.view');
Route::post('reports/{report}/rows', [RunController::class, 'rows'])->name('reports.rows');
Route::post('reports/{report}/values', [RunController::class, 'values'])->name('reports.values');
Route::post('reports/{report}/summary', [RunController::class, 'summary'])->name('reports.summary');
Route::post('reports/{report}/export', [ExportController::class, 'report'])->name('reports.export');
// Choices for the report filters (lines, divisions, employees), limited to what the user may see.
Route::get('context/{kind}', [RunController::class, 'contextOptions'])->name('context.options');

// Dashboards: opening and their widgets' data (anyone who may open them).
Route::get('dashboards', [DashboardController::class, 'index'])->name('dashboards.index');
Route::get('dashboards/{dashboard}', [DashboardController::class, 'show'])->name('dashboards.show');
Route::post('dashboards/{dashboard}/widgets/{widget}', [DashboardController::class, 'widget'])->name('dashboards.widget');

// Publishing: a permission per report, given to roles, and a menu entry.
Route::middleware('report-generator.can:publishReports')->group(function () {
    Route::get('permissions/roles', [PublishController::class, 'roles'])->name('permissions.roles');
    Route::get('reports/{report}/publish', [PublishController::class, 'show'])->name('reports.publish.show');
    Route::put('reports/{report}/publish', [PublishController::class, 'update'])->name('reports.publish');
    Route::delete('reports/{report}/publish', [PublishController::class, 'destroy'])->name('reports.unpublish');
    Route::get('dashboards/{dashboard}/publish', [PublishController::class, 'showDashboard'])->name('dashboards.publish.show');
    Route::put('dashboards/{dashboard}/publish', [PublishController::class, 'updateDashboard'])->name('dashboards.publish');
    Route::delete('dashboards/{dashboard}/publish', [PublishController::class, 'destroyDashboard'])->name('dashboards.unpublish');
});

// Building and running reports.
Route::middleware('report-generator.can:createReports')->group(function () {
    Route::get('catalog', [QueryController::class, 'catalog'])->name('catalog');
    Route::post('query/validate', [QueryController::class, 'validateDefinition'])->name('query.validate');
    Route::post('query/rows', [QueryController::class, 'rows'])->name('query.rows');
    Route::post('query/values', [QueryController::class, 'values'])->name('query.values');
    Route::post('query/summary', [QueryController::class, 'summary'])->name('query.summary');

    // Building dashboards.
    Route::post('dashboards', [DashboardController::class, 'store'])->name('dashboards.store');
    Route::patch('dashboards/{dashboard}', [DashboardController::class, 'update'])->name('dashboards.update');
    Route::delete('dashboards/{dashboard}', [DashboardController::class, 'destroy'])->name('dashboards.destroy');
    Route::post('dashboards/{dashboard}/pin', [DashboardController::class, 'pin'])->name('dashboards.pin');
    Route::post('dashboards-generate', [DashboardController::class, 'generate'])->name('dashboards.generate');
    Route::post('dashboards-preview', [DashboardController::class, 'preview'])->name('dashboards.preview');

    // Combined reports: existing reports side by side per employee or division.
    Route::get('combine/reports', [CombineController::class, 'reports'])->name('combine.reports');
    Route::get('combine/reports/{report}/fields', [CombineController::class, 'fields'])->name('combine.fields');
    Route::post('query/export', [ExportController::class, 'definition'])->middleware('report-generator.can:exportReports')->name('query.export');
    Route::post('query/{queryId}/cancel', [QueryController::class, 'cancel'])->name('query.cancel');

    Route::get('reports', [ReportController::class, 'index'])->name('reports.index');
    Route::post('reports', [ReportController::class, 'store'])->name('reports.store');
    Route::get('reports/{report}', [ReportController::class, 'show'])->name('reports.show');
    Route::patch('reports/{report}', [ReportController::class, 'update'])->name('reports.update');
    Route::delete('reports/{report}', [ReportController::class, 'destroy'])->name('reports.destroy');
    Route::post('reports/{report}/duplicate', [ReportController::class, 'duplicate'])->name('reports.duplicate');
    Route::put('reports/{report}/shares', [ReportController::class, 'share'])->name('reports.share');
});
