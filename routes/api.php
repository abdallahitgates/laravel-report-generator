<?php

use Abdalla\ReportGenerator\Http\Controllers\MetaController;
use Abdalla\ReportGenerator\Http\Controllers\Model;
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
