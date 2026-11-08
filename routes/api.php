<?php

use Abdalla\ReportGenerator\Http\Controllers\MetaController;
use Abdalla\ReportGenerator\Http\Controllers\Query\QueryController;
use Abdalla\ReportGenerator\Http\Controllers\Reports\ReportController;
use Abdalla\ReportGenerator\Http\Controllers\Topics\TopicController;
use Illuminate\Support\Facades\Route;

// Prefix, middleware and the "report-generator." name prefix come from
// config('report-generator.route') in ReportGeneratorServiceProvider.

Route::get('meta', MetaController::class)->name('meta');

// Topics are declared in code; admins can browse them with every field and relation.
Route::get('topics', TopicController::class)->middleware('report-generator.can:manageModel')->name('topics');

// Building and running reports.
Route::middleware('report-generator.can:createReports')->group(function () {
    Route::get('catalog', [QueryController::class, 'catalog'])->name('catalog');
    Route::post('query/validate', [QueryController::class, 'validateDefinition'])->name('query.validate');
    Route::post('query/rows', [QueryController::class, 'rows'])->name('query.rows');
    Route::post('query/values', [QueryController::class, 'values'])->name('query.values');
    Route::post('query/{queryId}/cancel', [QueryController::class, 'cancel'])->name('query.cancel');

    Route::get('reports', [ReportController::class, 'index'])->name('reports.index');
    Route::post('reports', [ReportController::class, 'store'])->name('reports.store');
    Route::get('reports/{report}', [ReportController::class, 'show'])->name('reports.show');
    Route::patch('reports/{report}', [ReportController::class, 'update'])->name('reports.update');
    Route::delete('reports/{report}', [ReportController::class, 'destroy'])->name('reports.destroy');
    Route::post('reports/{report}/duplicate', [ReportController::class, 'duplicate'])->name('reports.duplicate');
    Route::put('reports/{report}/shares', [ReportController::class, 'share'])->name('reports.share');
});
