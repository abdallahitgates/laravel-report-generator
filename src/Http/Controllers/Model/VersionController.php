<?php

namespace Abdalla\ReportGenerator\Http\Controllers\Model;

use Abdalla\ReportGenerator\Models\ModelVersion;
use Abdalla\ReportGenerator\Semantic\ModelPublisher;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;

class VersionController extends Controller
{
    public function index(): JsonResponse
    {
        $versions = ModelVersion::query()
            ->select(['id', 'version', 'notes', 'published_by', 'published_at'])
            ->orderByDesc('version')
            ->limit(50)
            ->get()
            ->map(fn (ModelVersion $v) => [
                'version' => $v->version,
                'notes' => $v->notes,
                'published_by' => $v->published_by,
                'published_at' => $v->published_at?->toIso8601String(),
            ]);

        return new JsonResponse(['data' => $versions]);
    }

    public function store(Request $request, ModelPublisher $publisher): JsonResponse
    {
        $data = $request->validate(['notes' => ['nullable', 'string', 'max:2000']]);

        $version = $publisher->publish($request->user()?->getAuthIdentifier(), $data['notes'] ?? null);

        return new JsonResponse([
            'data' => [
                'version' => $version->version,
                'published_at' => $version->published_at->toIso8601String(),
                'datasets_count' => count($version->payload['datasets']),
                'relationships_count' => count($version->payload['relationships']),
            ],
            'status' => StatusController::payload($publisher),
        ], 201);
    }
}
