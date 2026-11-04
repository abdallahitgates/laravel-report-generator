<?php

namespace Abdalla\ReportGenerator\Http\Controllers\Model;

use Abdalla\ReportGenerator\Schema\SchemaScanner;
use Abdalla\ReportGenerator\Semantic\ModelPublisher;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\Cache;

class ScanController extends Controller
{
    public function __invoke(Request $request, SchemaScanner $scanner, ModelPublisher $publisher): JsonResponse
    {
        // One scan at a time; a second click while one runs gets a clear answer.
        $lock = Cache::lock('report-generator:scan', 600);
        abort_unless($lock->get(), 409, 'A scan is already running. Try again when it finishes.');

        try {
            $summary = $scanner->scan(userId: $request->user()?->getAuthIdentifier());
        } finally {
            $lock->release();
        }

        return new JsonResponse([
            'summary' => $summary,
            'status' => StatusController::payload($publisher),
        ]);
    }
}
