<?php

namespace Abdalla\ReportGenerator\Http\Controllers\Dashboards;

use Abdalla\ReportGenerator\Dashboards\DashboardGenerator;
use Abdalla\ReportGenerator\Dashboards\WidgetData;
use Abdalla\ReportGenerator\Enums\Ability;
use Abdalla\ReportGenerator\Models\Dashboard;
use Abdalla\ReportGenerator\Models\Report;
use Abdalla\ReportGenerator\ReportGenerator;
use Abdalla\ReportGenerator\Security\ReportAccess;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Validation\Rule;

/**
 * Dashboards: widgets over saved reports. Opening a dashboard shows its
 * layout; each widget's data needs access to its own report (the report's
 * owner, shares or permission), and rows follow the viewer's row scope.
 * Building one needs access to the reports it uses.
 */
class DashboardController extends Controller
{
    public function __construct(
        private readonly ReportAccess $access,
        private readonly ReportGenerator $generator,
        private readonly WidgetData $widgets,
    ) {
    }

    public function index(Request $request): JsonResponse
    {
        $user = $request->user();
        $query = Dashboard::query()->orderByDesc('updated_at')->limit(500);
        if (! $this->generator->allows($user, Ability::ViewAllReports)) {
            $id = $user?->getAuthIdentifier();
            $held = $this->heldPermissions($user);
            $query->where(fn ($q) => $q->where('owner_id', $id)->orWhere('visibility', Report::PUBLIC)
                ->when($held !== [], fn ($w) => $w->orWhereIn('permission', $held)));
        }

        return new JsonResponse(['data' => $query->get()->map(fn (Dashboard $d) => $this->summary($d, $request))->values()]);
    }

    public function show(Request $request, Dashboard $dashboard): JsonResponse
    {
        abort_unless($this->access->canViewDashboard($dashboard, $request->user()), 403, 'You do not have access to this dashboard.');

        return new JsonResponse(['data' => $this->full($dashboard, $request)]);
    }

    public function store(Request $request): JsonResponse
    {
        $data = $this->validated($request);
        $this->checkReports($data['layout']['widgets'], $request);

        $dashboard = Dashboard::query()->create($data + ['owner_id' => $request->user()?->getAuthIdentifier()]);

        return new JsonResponse(['data' => $this->full($dashboard, $request)], 201);
    }

    public function update(Request $request, Dashboard $dashboard): JsonResponse
    {
        abort_unless($this->access->canEditDashboard($dashboard, $request->user()), 403, 'Only the owner can change this dashboard.');
        $data = $this->validated($request, partial: true);
        if (isset($data['layout'])) {
            $this->checkReports($data['layout']['widgets'], $request);
        }
        $dashboard->update($data);

        return new JsonResponse(['data' => $this->full($dashboard->refresh(), $request)]);
    }

    public function destroy(Request $request, Dashboard $dashboard): JsonResponse
    {
        abort_unless($this->access->canEditDashboard($dashboard, $request->user()), 403, 'Only the owner can delete this dashboard.');
        $dashboard->delete();

        return new JsonResponse(null, 204);
    }

    /** A first layout for a report (not saved). */
    public function generate(Request $request, DashboardGenerator $generator): JsonResponse
    {
        $data = $request->validate(['report' => ['required', 'string', 'max:64']]);
        $report = $this->openReport($data['report'], $request);

        return new JsonResponse(['data' => $generator->generate($report, $request->user())]);
    }

    /** One widget of a saved dashboard, with the viewer's report filters. */
    public function widget(Request $request, Dashboard $dashboard, string $widget): JsonResponse
    {
        abort_unless($this->access->canViewDashboard($dashboard, $request->user()), 403, 'You do not have access to this dashboard.');
        $spec = collect($dashboard->widgets())->firstWhere('id', $widget) ?? abort(404, 'This dashboard has no such widget.');
        $report = Report::query()->where('uuid', $spec['report'] ?? null)->first() ?? abort(404, 'The report behind this widget was deleted.');
        if (! $this->access->canView($report, $request->user())) {
            return new JsonResponse(['message' => "You do not have access to {$report->name}.", 'code' => 'REPORT_FORBIDDEN', 'details' => ['report' => $report->name]], 403);
        }

        return new JsonResponse($this->widgets->compute($report, $spec, $this->choices($request, $dashboard), $request->user()));
    }

    /**
     * Pins a widget to a dashboard the user owns (from a report: "Pin to
     * dashboard"). Returns the dashboard.
     */
    public function pin(Request $request, Dashboard $dashboard): JsonResponse
    {
        abort_unless($this->access->canEditDashboard($dashboard, $request->user()), 403, 'Only the owner can change this dashboard.');
        $widget = $this->validatedWidget($request);
        $this->openReport($widget['report'], $request);

        $layout = $dashboard->layout;
        $widget['id'] = 'w'.substr(md5(uniqid('', true)), 0, 10);
        $layout['widgets'] = [...($layout['widgets'] ?? []), $widget];
        abort_if(count($layout['widgets']) > 40, 422, 'A dashboard holds up to 40 widgets.');
        $dashboard->update(['layout' => $layout]);

        return new JsonResponse(['data' => $this->full($dashboard->refresh(), $request)]);
    }

    /** A widget being edited (not saved yet). */
    public function preview(Request $request): JsonResponse
    {
        $request->validate(['widget' => ['required', 'array'], 'widget.report' => ['required', 'string', 'max:64']]);
        $widget = (array) $request->input('widget');
        $report = $this->openReport((string) $widget['report'], $request);

        return new JsonResponse($this->widgets->compute($report, $widget, $this->choices($request, null), $request->user()));
    }

    // ----------------------------------------------------------------------

    private function choices(Request $request, ?Dashboard $dashboard): array
    {
        $data = $request->validate([
            'context' => ['sometimes', 'array'],
            'context.period' => ['sometimes', 'nullable'],
            'context.lines' => ['sometimes', 'array', 'max:1000'],
            'context.divisions' => ['sometimes', 'array', 'max:1000'],
            'context.employees' => ['sometimes', 'array', 'max:1000'],
        ]);

        return ['context' => array_merge((array) ($dashboard?->layout['context'] ?? []), array_filter($data['context'] ?? [], fn ($v) => $v !== null && $v !== []))];
    }

    private function openReport(string $uuid, Request $request): Report
    {
        $report = Report::query()->where('uuid', $uuid)->first() ?? abort(404, 'There is no such report.');
        abort_unless($this->access->canView($report, $request->user()), 403, "You do not have access to {$report->name}.");

        return $report;
    }

    private function checkReports(array $widgets, Request $request): void
    {
        foreach (collect($widgets)->pluck('report')->filter()->unique() as $uuid) {
            $this->openReport((string) $uuid, $request);
        }
    }

    private function validatedWidget(Request $request): array
    {
        $data = $request->validate([
            'widget' => ['required', 'array'],
            'widget.type' => ['required', Rule::in(WidgetData::TYPES)],
            'widget.title' => ['sometimes', 'nullable', 'string', 'max:160'],
            'widget.report' => ['required', 'string', 'max:64'],
            'widget.category' => ['sometimes', 'nullable', 'string', 'max:64'],
            'widget.values' => ['sometimes', 'array', 'max:6'],
            'widget.values.*' => ['string', 'max:64'],
            'widget.top' => ['sometimes', 'integer', 'min:1', 'max:400'],
            'widget.size' => ['sometimes', Rule::in(['sm', 'md', 'lg', 'full'])],
        ]);

        return array_filter($data['widget'], fn ($v) => $v !== null);
    }

    private function validated(Request $request, bool $partial = false): array
    {
        $required = $partial ? 'sometimes' : 'required';

        return $request->validate([
            'name' => [$required, 'string', 'max:255'],
            'description' => ['sometimes', 'nullable', 'string', 'max:2000'],
            'visibility' => ['sometimes', Rule::in([Report::PRIVATE, Report::PUBLIC])],
            'layout' => [$required, 'array'],
            'layout.context' => ['sometimes', 'nullable', 'array'],
            'layout.widgets' => [$partial ? 'required_with:layout' : 'required', 'array', 'max:40'],
            'layout.widgets.*.id' => ['required', 'string', 'max:32', 'distinct'],
            'layout.widgets.*.type' => ['required', Rule::in(WidgetData::TYPES)],
            'layout.widgets.*.title' => ['sometimes', 'nullable', 'string', 'max:160'],
            'layout.widgets.*.report' => ['required', 'string', 'max:64'],
            'layout.widgets.*.category' => ['sometimes', 'nullable', 'string', 'max:64'],
            'layout.widgets.*.values' => ['sometimes', 'array', 'max:6'],
            'layout.widgets.*.values.*' => ['string', 'max:64'],
            'layout.widgets.*.top' => ['sometimes', 'integer', 'min:1', 'max:400'],
            'layout.widgets.*.size' => ['sometimes', Rule::in(['sm', 'md', 'lg', 'full'])],
        ]);
    }

    /** @return list<string> */
    private function heldPermissions($user): array
    {
        if ($user === null) {
            return [];
        }

        return Dashboard::query()->whereNotNull('permission')->distinct()->pluck('permission')
            ->filter(fn (string $p) => $this->generator->allows($user, $p))->values()->all();
    }

    private function summary(Dashboard $dashboard, Request $request): array
    {
        $user = $request->user();

        return [
            'uuid' => $dashboard->uuid,
            'name' => $dashboard->name,
            'description' => $dashboard->description,
            'visibility' => $dashboard->visibility,
            'widgets_count' => count($dashboard->widgets()),
            'is_owner' => $this->access->canEditDashboard($dashboard, $user),
            'can_edit' => $this->access->canEditDashboard($dashboard, $user),
            'can_publish' => $this->access->canPublish($user),
            'published' => $dashboard->permission !== null,
            'permission' => $dashboard->permission,
            'in_menu' => (bool) $dashboard->in_menu,
            'menu_group' => $dashboard->menu_group,
            'updated_at' => $dashboard->updated_at?->toIso8601String(),
        ];
    }

    private function full(Dashboard $dashboard, Request $request): array
    {
        $reports = Report::query()->with('shares')->whereIn('uuid', collect($dashboard->widgets())->pluck('report')->filter()->unique())->get()->keyBy('uuid');
        $user = $request->user();

        return $this->summary($dashboard, $request) + [
            'layout' => [
                'context' => (object) ($dashboard->layout['context'] ?? []),
                'widgets' => array_map(fn ($w) => $w + [
                    'report_name' => $reports[$w['report'] ?? '']?->name,
                    // Whether this viewer may see the widget's data (access to its report).
                    'can_view' => isset($reports[$w['report'] ?? '']) && $this->access->canView($reports[$w['report']], $user),
                ], $dashboard->widgets()),
            ],
            'created_at' => $dashboard->created_at?->toIso8601String(),
        ];
    }
}
