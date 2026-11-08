<?php

namespace Abdalla\ReportGenerator\Http\Controllers\Reports;

use Abdalla\ReportGenerator\Models\Report;
use Abdalla\ReportGenerator\Query\QueryEngine;
use Abdalla\ReportGenerator\Security\ReportAccess;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Arr;

/**
 * Opening and running a saved report as it was saved, for everyone who may
 * open it (owner, shares, its permission), whether or not they can build
 * reports. The viewer picks the report filters (period, lines, divisions,
 * employees) and parameters; rows always follow the viewer's own access.
 */
class RunController extends Controller
{
    public function __construct(
        private readonly ReportAccess $access,
        private readonly QueryEngine $engine,
    ) {
    }

    public function show(Request $request, Report $report): JsonResponse
    {
        $this->authorizeView($report, $request);
        $definition = $report->definition;

        return new JsonResponse(['data' => [
            'uuid' => $report->uuid,
            'name' => $report->name,
            'description' => $report->description,
            'root' => $definition['root'] ?? null,
            'context' => $definition['context'] ?? (object) [],
            'parameters' => $definition['parameters'] ?? [],
            // Columns (no refs or filters: viewers need names, kinds and grouping only).
            'fields' => array_map(fn (array $f) => array_filter([
                'as' => $f['as'] ?? null,
                'label' => $f['label'] ?? null,
                'kind' => isset($f['agg']) || isset($f['formula']) ? 'value' : 'dimension',
                'grain' => $f['grain'] ?? null,
            ], fn ($v) => $v !== null), $definition['fields'] ?? []),
            'sort' => $definition['sort'] ?? [],
            'grouped' => (bool) ($definition['ui']['grouped'] ?? false),
            'rows' => $definition['ui']['rows'] ?? [],
            'subtotals' => (bool) ($definition['subtotals'] ?? false),
            'can_edit' => $this->access->canEdit($report, $request->user()),
            'updated_at' => $report->updated_at?->toIso8601String(),
        ]]);
    }

    public function rows(Request $request, Report $report): JsonResponse
    {
        $this->authorizeView($report, $request);
        $data = $request->validate([
            'parameters' => ['sometimes', 'array'],
            'context' => ['sometimes', 'array'],
            'context.period' => ['sometimes', 'nullable'],
            'context.lines' => ['sometimes', 'array', 'max:1000'],
            'context.divisions' => ['sometimes', 'array', 'max:1000'],
            'context.employees' => ['sometimes', 'array', 'max:1000'],
            'page.offset' => ['sometimes', 'integer', 'min:0'],
            'page.size' => ['sometimes', 'integer', 'min:1'],
            'groups.columns' => ['sometimes', 'array', 'max:10'],
            'groups.columns.*' => ['string'],
            'groups.keys' => ['sometimes', 'array', 'max:10'],
            'query_id' => ['sometimes', 'nullable', 'string', 'max:100'],
            'sort' => ['sometimes', 'array', 'max:5'],
            'sort.*.field' => ['required', 'string', 'max:64'],
            'sort.*.dir' => ['required', 'in:asc,desc'],
        ]);

        $definition = $report->definition;
        if (isset($data['sort'])) {
            // Sorting by the report's own columns only.
            $aliases = array_column($definition['fields'] ?? [], 'as');
            $definition['sort'] = array_values(array_filter($data['sort'], fn ($s) => in_array($s['field'], $aliases, true)));
        }
        // The viewer's report filters replace the saved ones; others stay.
        $context = array_merge($definition['context'] ?? [], Arr::only($data['context'] ?? [], ['period', 'lines', 'divisions', 'employees']));
        $definition['context'] = array_filter($context, fn ($v) => $v !== null && $v !== []);

        $groups = isset($data['groups']) ? [
            'columns' => array_values($data['groups']['columns'] ?? []),
            'keys' => array_values($data['groups']['keys'] ?? []),
        ] : null;

        return new JsonResponse($this->engine->rows(
            $definition,
            $data['parameters'] ?? [],
            $request->user(),
            (int) ($data['page']['offset'] ?? 0),
            isset($data['page']['size']) ? (int) $data['page']['size'] : null,
            $groups,
            $data['query_id'] ?? null,
        ));
    }

    /** Values for the report's own filter fields only. */
    public function values(Request $request, Report $report): JsonResponse
    {
        $this->authorizeView($report, $request);
        $data = $request->validate([
            'field' => ['required', 'string', 'max:260'],
            'search' => ['nullable', 'string', 'max:200'],
            'limit' => ['sometimes', 'integer', 'min:1', 'max:200'],
        ]);
        abort_unless(in_array($data['field'], $this->refs($report->definition), true), 422, 'This report has no such field.');

        return new JsonResponse([
            'data' => $this->engine->values($data['field'], $data['search'] ?? null, (int) ($data['limit'] ?? 50), $request->user()),
        ]);
    }

    /** @return list<string> topic.field refs the report uses */
    private function refs(array $definition): array
    {
        $refs = [];
        foreach ($definition['fields'] ?? [] as $field) {
            if (isset($field['ref'])) {
                $refs[] = $field['ref'];
            }
        }
        $walk = function ($node) use (&$walk, &$refs) {
            if (! is_array($node)) {
                return;
            }
            if (isset($node['field']) && is_string($node['field']) && str_contains($node['field'], '.')) {
                $refs[] = $node['field'];
            }
            foreach ($node as $child) {
                $walk($child);
            }
        };
        $walk($definition['filters'] ?? []);

        return array_values(array_unique($refs));
    }

    private function authorizeView(Report $report, Request $request): void
    {
        abort_unless($this->access->canView($report, $request->user()), 403, 'You do not have access to this report.');
    }
}
