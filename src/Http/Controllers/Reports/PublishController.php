<?php

namespace Abdalla\ReportGenerator\Http\Controllers\Reports;

use Abdalla\ReportGenerator\Contracts\PermissionStore;
use Abdalla\ReportGenerator\Models\Dashboard;
use Abdalla\ReportGenerator\Models\Report;
use Abdalla\ReportGenerator\Security\ReportAccess;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

/**
 * Publishing a report: a permission named after it, the roles that hold it,
 * and whether it is listed in the menu. Needs the publish ability.
 */
class PublishController extends Controller
{
    public function __construct(
        private readonly ReportAccess $access,
        private readonly PermissionStore $permissions,
    ) {
    }

    /** Roles a report can be given to. */
    public function roles(): JsonResponse
    {
        return new JsonResponse(['data' => $this->permissions->roles()]);
    }

    public function show(Request $request, Report $report): JsonResponse
    {
        $this->authorizeOpen($request, $report);

        return new JsonResponse(['data' => $this->settings($report)]);
    }

    public function update(Request $request, Report $report): JsonResponse
    {
        return $this->publish($request, $report);
    }

    public function destroy(Report $report): JsonResponse
    {
        return $this->unpublish($report);
    }

    // Dashboards publish the same way.

    public function showDashboard(Request $request, Dashboard $dashboard): JsonResponse
    {
        $this->authorizeOpen($request, $dashboard);

        return new JsonResponse(['data' => $this->settings($dashboard)]);
    }

    public function updateDashboard(Request $request, Dashboard $dashboard): JsonResponse
    {
        return $this->publish($request, $dashboard);
    }

    public function destroyDashboard(Dashboard $dashboard): JsonResponse
    {
        return $this->unpublish($dashboard);
    }

    private function publish(Request $request, Report|Dashboard $report): JsonResponse
    {
        $this->authorizeOpen($request, $report);
        // One permission opens one report or dashboard.
        $other = $report instanceof Report ? new Dashboard() : new Report();
        $data = $request->validate([
            'permission' => ['required', 'string', 'max:191', 'regex:/^[a-z0-9][a-z0-9_.\-]*$/',
                Rule::unique($report->getConnectionName().'.'.$report->getTable(), 'permission')->ignore($report->getKey()),
                Rule::unique($other->getConnectionName().'.'.$other->getTable(), 'permission')],
            'roles' => ['present', 'array', 'max:200'],
            'roles.*' => ['string', 'max:191', Rule::in(array_column($this->permissions->roles(), 'name'))],
            'in_menu' => ['sometimes', 'boolean'],
            'menu_group' => ['sometimes', 'nullable', 'string', 'max:120'],
        ], [
            'permission.regex' => 'Use lowercase letters, digits, dots, dashes and underscores for the permission name.',
            'permission.unique' => 'Another report or dashboard already uses this permission.',
        ]);

        $report->getConnection()->transaction(function () use ($report, $data) {
            $this->permissions->ensure($data['permission']);
            $this->permissions->syncRoles($data['permission'], array_values(array_unique($data['roles'])));

            $report->forceFill([
                'permission' => $data['permission'],
                'in_menu' => (bool) ($data['in_menu'] ?? $report->in_menu),
                'menu_group' => array_key_exists('menu_group', $data) ? $data['menu_group'] : $report->menu_group,
                'published_at' => $report->published_at ?? now(),
            ])->save();
        });

        return new JsonResponse(['data' => $this->settings($report->refresh())]);
    }

    /**
     * Stops publishing: the report leaves the menu and the permission no
     * longer opens it. The permission itself stays in the host's system.
     */
    private function unpublish(Report|Dashboard $report): JsonResponse
    {
        $this->authorizeOpen(request(), $report);
        $report->forceFill(['permission' => null, 'in_menu' => false, 'published_at' => null])->save();

        return new JsonResponse(['data' => $this->settings($report)]);
    }

    /** Nobody publishes what they cannot open themselves. */
    private function authorizeOpen(Request $request, Report|Dashboard $report): void
    {
        $allowed = $report instanceof Report
            ? $this->access->canView($report, $request->user())
            : $this->access->canViewDashboard($report, $request->user());
        abort_unless($allowed, 403, 'You cannot publish what you cannot open.');
    }

    private function settings(Report|Dashboard $report): array
    {
        return [
            'published' => $report->permission !== null,
            'permission' => $report->permission,
            'suggested_permission' => $this->suggest($report),
            'roles' => $report->permission ? $this->permissions->rolesWith($report->permission) : [],
            'in_menu' => (bool) $report->in_menu,
            'menu_group' => $report->menu_group,
            'published_at' => $report->published_at?->toIso8601String(),
        ];
    }

    private function suggest(Report|Dashboard $report): string
    {
        $prefix = (string) config('report-generator.permissions.prefix', 'show_reports_').($report instanceof Dashboard ? 'dashboard_' : '');

        return mb_substr($prefix.(Str::slug($report->name, '_') ?: $report->uuid), 0, 191);
    }
}
