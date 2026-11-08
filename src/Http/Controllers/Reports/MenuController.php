<?php

namespace Abdalla\ReportGenerator\Http\Controllers\Reports;

use Abdalla\ReportGenerator\Enums\Ability;
use Abdalla\ReportGenerator\Models\Report;
use Abdalla\ReportGenerator\ReportGenerator;
use Abdalla\ReportGenerator\Security\ReportAccess;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;

/**
 * Published reports listed in the menu that the current user may open,
 * for the host's sidebar.
 */
class MenuController extends Controller
{
    public function __invoke(Request $request, ReportAccess $access, ReportGenerator $generator): JsonResponse
    {
        $user = $request->user();
        $query = Report::query()->whereNotNull('permission')->where('in_menu', true);
        if (! $generator->allows($user, Ability::ViewAllReports)) {
            $query->whereIn('permission', $access->heldPermissions($user) ?: ['__none__']);
        }

        $reports = $query->orderBy('menu_group')->orderBy('name')->get(['uuid', 'name', 'description', 'menu_group']);

        $dashboards = \Abdalla\ReportGenerator\Models\Dashboard::query()->whereNotNull('permission')->where('in_menu', true);
        if (! $generator->allows($user, Ability::ViewAllReports)) {
            $held = \Abdalla\ReportGenerator\Models\Dashboard::query()->whereNotNull('permission')->distinct()->pluck('permission')
                ->filter(fn (string $p) => $user !== null && $generator->allows($user, $p))->values()->all();
            $dashboards->whereIn('permission', $held ?: ['__none__']);
        }

        $item = fn (string $type) => fn ($r) => [
            'type' => $type,
            'uuid' => $r->uuid,
            'name' => $r->name,
            'description' => $r->description,
            'group' => $r->menu_group,
        ];

        return new JsonResponse(['data' => [
            ...$dashboards->orderBy('menu_group')->orderBy('name')->get(['uuid', 'name', 'description', 'menu_group'])->map($item('dashboard')),
            ...$reports->map($item('report')),
        ]]);
    }
}
