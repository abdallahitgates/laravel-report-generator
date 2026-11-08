<?php

namespace Abdalla\ReportGenerator\Http\Controllers\Query;

use Abdalla\ReportGenerator\Query\QueryEngine;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;

/**
 * Running reports. Errors in a definition come back as 422 JSON with a
 * stable "code" and the "path" inside the definition (QueryException::render).
 */
class QueryController extends Controller
{
    public function __construct(private readonly QueryEngine $engine)
    {
    }

    public function catalog(Request $request): JsonResponse
    {
        $data = $request->validate(['root' => ['nullable', 'string', 'max:128']]);

        return new JsonResponse($this->engine->catalog($data['root'] ?? null, $request->user()));
    }

    public function validateDefinition(Request $request): JsonResponse
    {
        $data = $this->definitionInput($request);

        return new JsonResponse($this->engine->validate($data['definition'], $data['parameters'] ?? [], $request->user()));
    }

    /**
     * One page of results. For AG Grid's server-side row model, send
     * groups.columns (the row group aliases) and groups.keys (the groups
     * opened so far): the response is then that level's group rows.
     */
    public function rows(Request $request): JsonResponse
    {
        $data = $this->definitionInput($request) + $request->validate([
            'page.offset' => ['sometimes', 'integer', 'min:0'],
            'page.size' => ['sometimes', 'integer', 'min:1'],
            'groups.columns' => ['sometimes', 'array', 'max:10'],
            'groups.columns.*' => ['string'],
            'groups.keys' => ['sometimes', 'array', 'max:10'],
            'query_id' => ['sometimes', 'nullable', 'string', 'max:100'],
        ]);

        $groups = isset($data['groups']) ? [
            'columns' => array_values($data['groups']['columns'] ?? []),
            'keys' => array_values($data['groups']['keys'] ?? []),
        ] : null;

        return new JsonResponse($this->engine->rows(
            $data['definition'],
            $data['parameters'] ?? [],
            $request->user(),
            (int) ($data['page']['offset'] ?? 0),
            isset($data['page']['size']) ? (int) $data['page']['size'] : null,
            $groups,
            $data['query_id'] ?? null,
        ));
    }

    /** Count and grand totals of the whole report. */
    public function summary(Request $request): JsonResponse
    {
        $data = $this->definitionInput($request);

        return new JsonResponse($this->engine->summary($data['definition'], $data['parameters'] ?? [], $request->user()));
    }

    public function values(Request $request): JsonResponse
    {
        $data = $request->validate([
            'field' => ['required', 'string', 'max:260'],
            'search' => ['nullable', 'string', 'max:200'],
            'limit' => ['sometimes', 'integer', 'min:1', 'max:200'],
        ]);

        return new JsonResponse([
            'data' => $this->engine->values($data['field'], $data['search'] ?? null, (int) ($data['limit'] ?? 50), $request->user()),
        ]);
    }

    public function cancel(Request $request, string $queryId): JsonResponse
    {
        return new JsonResponse(['cancelled' => $this->engine->cancel($queryId, $request->user())]);
    }

    private function definitionInput(Request $request): array
    {
        return $request->validate([
            'definition' => ['required', 'array'],
            'parameters' => ['sometimes', 'array'],
        ]);
    }
}
