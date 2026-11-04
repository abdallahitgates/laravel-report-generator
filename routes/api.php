<?php

use Abdalla\ReportGenerator\Http\Controllers\MetaController;
use Abdalla\ReportGenerator\Http\Controllers\Model;
use Abdalla\ReportGenerator\Http\Controllers\Query\QueryController;
use Abdalla\ReportGenerator\Http\Controllers\Reports\ReportController;
use Illuminate\Support\Facades\Route;

// Prefix, middleware and the "report-generator." name prefix come from
// config('report-generator.route') in ReportGeneratorServiceProvider.

Route::get('meta', MetaController::class)->name('meta');

// Model Studio: reviewing the scanned schema and publishing the semantic model.
Route::prefix('model')->name('model.')->middleware('report-generator.can:manageModel')->group(function () {
    Route::get('status', Model\StatusController::class)->name('status');
    Route::post('scan', Model\ScanController::class)->name('scan');

    Route::get('datasets', [Model\DatasetController::class, 'index'])->name('datasets.index');
    Route::get('datasets/{dataset}', [Model\DatasetController::class, 'show'])->name('datasets.show');
    Route::patch('datasets/{dataset}', [Model\DatasetController::class, 'update'])->name('datasets.update');

    Route::patch('fields/{field}', [Model\FieldController::class, 'update'])->name('fields.update');

    Route::get('relationships', [Model\RelationshipController::class, 'index'])->name('relationships.index');
    Route::post('relationships', [Model\RelationshipController::class, 'store'])->name('relationships.store');
    Route::post('relationships/bulk', [Model\RelationshipController::class, 'bulk'])->name('relationships.bulk');
    Route::patch('relationships/{relationship}', [Model\RelationshipController::class, 'update'])->name('relationships.update');
    Route::delete('relationships/{relationship}', [Model\RelationshipController::class, 'destroy'])->name('relationships.destroy');

    Route::get('versions', [Model\VersionController::class, 'index'])->name('versions.index');
    Route::post('publish', [Model\VersionController::class, 'store'])->name('publish');
});

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
