<?php

use Illuminate\Support\Facades\Route;
use Abdalla\ReportGenerator\Http\Controllers\MetaController;

// Prefix, middleware and the "report-generator." name prefix come from
// config('report-generator.route') in ReportGeneratorServiceProvider.

Route::get('meta', MetaController::class)->name('meta');
