<?php

namespace Abdalla\ReportGenerator\Http\Controllers;

use Abdalla\ReportGenerator\Contracts\CombinableReport;
use Abdalla\ReportGenerator\Query\QueryEngine;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Validation\Rule;

/**
 * What can go into a combined report: the existing reports the user may
 * run, and the columns each offers. Running, saving and publishing go
 * through the usual query and report endpoints (definition.type "combined").
 */
class CombineController extends Controller
{
    public function __construct(private readonly QueryEngine $engine)
    {
    }

    public function reports(Request $request): JsonResponse
    {
        return new JsonResponse(['data' => array_map(fn (CombinableReport $r) => [
            'key' => $r->key(),
            'label' => $r->label(),
            'description' => $r->description(),
            'group' => $r->group(),
            'levels' => $r->levels(),
        ], $this->engine->combiner()->available($request->user()))]);
    }

    public function fields(Request $request, string $report): JsonResponse
    {
        $data = $request->validate([
            'level' => ['required', Rule::in(['employee', 'division'])],
            'period' => ['sometimes', 'nullable'],
            'lines' => ['sometimes', 'array', 'max:200'],
        ]);
        $context = array_filter(['period' => $data['period'] ?? null, 'lines' => $data['lines'] ?? []]);

        return new JsonResponse(['data' => $this->engine->combiner()->fieldsOf($report, $context, $data['level'], $request->user())]);
    }
}
