<?php

namespace Abdalla\ReportGenerator\Http\Controllers\Reports;

use Abdalla\ReportGenerator\Models\Report;
use Abdalla\ReportGenerator\Models\ReportShare;
use Abdalla\ReportGenerator\Query\QueryEngine;
use Abdalla\ReportGenerator\Query\QueryException;
use Abdalla\ReportGenerator\Security\ReportAccess;
use Abdalla\ReportGenerator\Semantic\Published\SemanticModelRepository;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Validation\Rule;

/**
 * Saved reports: the library, save / update / duplicate / delete, sharing.
 */
class ReportController extends Controller
{
    public function __construct(
        private readonly ReportAccess $access,
        private readonly QueryEngine $engine,
        private readonly SemanticModelRepository $models,
    ) {
    }

    public function index(Request $request): JsonResponse
    {
        $data = $request->validate([
            'scope' => ['sometimes', Rule::in(['mine', 'shared', 'all'])],
            'search' => ['sometimes', 'nullable', 'string', 'max:200'],
            'folder' => ['sometimes', 'nullable', 'string', 'max:255'],
        ]);
        $user = $request->user();

        $reports = $this->access->visible(Report::query()->with('shares'), $user, $data['scope'] ?? 'all')
            ->when($data['search'] ?? null, fn ($q, $term) => $q->where(fn ($w) => $w
                ->where('name', 'like', '%'.addcslashes($term, '%_\\').'%')
                ->orWhere('description', 'like', '%'.addcslashes($term, '%_\\').'%')))
            ->when($data['folder'] ?? null, fn ($q, $folder) => $q->where('folder', $folder))
            ->orderByDesc('updated_at')
            ->limit(500)
            ->get();

        return new JsonResponse([
            'data' => $reports->map(fn (Report $r) => $this->summary($r, $request))->values(),
            'folders' => $reports->pluck('folder')->filter()->unique()->sort()->values(),
        ]);
    }

    public function show(Request $request, Report $report): JsonResponse
    {
        $this->authorizeView($report, $request);

        return new JsonResponse(['data' => $this->full($report, $request)]);
    }

    public function store(Request $request): JsonResponse
    {
        $data = $this->validated($request);
        $this->checkDefinition($data['definition'], $request);

        $report = Report::query()->create($data + [
            'owner_id' => $request->user()?->getAuthIdentifier(),
            'definition_version' => 1,
            'model_version' => $this->models->current($request->user())->version,
        ]);

        return new JsonResponse(['data' => $this->full($report->load('shares'), $request)], 201);
    }

    public function update(Request $request, Report $report): JsonResponse
    {
        $this->authorizeEdit($report, $request);
        $data = $this->validated($request, partial: true);

        if (isset($data['definition'])) {
            $this->checkDefinition($data['definition'], $request);
            $data['model_version'] = $this->models->current($request->user())->version;
        }
        // Only the owner decides who else sees the report.
        if (isset($data['visibility']) && ! $this->access->isOwner($report, $request->user())) {
            unset($data['visibility']);
        }

        $report->update($data);

        return new JsonResponse(['data' => $this->full($report->refresh()->load('shares'), $request)]);
    }

    public function duplicate(Request $request, Report $report): JsonResponse
    {
        $this->authorizeView($report, $request);

        $copy = $report->replicate(['uuid']);
        $copy->name = mb_substr('Copy of '.$report->name, 0, 255);
        $copy->owner_id = $request->user()?->getAuthIdentifier();
        $copy->visibility = Report::PRIVATE;
        $copy->save();

        return new JsonResponse(['data' => $this->full($copy->load('shares'), $request)], 201);
    }

    public function destroy(Request $request, Report $report): JsonResponse
    {
        abort_unless($this->access->isOwner($report, $request->user()), 403, 'Only the owner can delete this report.');
        $report->delete();

        return new JsonResponse(null, 204);
    }

    /** Replaces the report's shares. Owner only. */
    public function share(Request $request, Report $report): JsonResponse
    {
        abort_unless($this->access->isOwner($report, $request->user()), 403, 'Only the owner can share this report.');

        $data = $request->validate([
            'visibility' => ['sometimes', Rule::in([Report::PRIVATE, Report::PUBLIC])],
            'shares' => ['present', 'array', 'max:200'],
            'shares.*.type' => ['required', Rule::in(['user', 'role'])],
            'shares.*.id' => ['required', 'string', 'max:64'],
            'shares.*.permission' => ['sometimes', Rule::in(['view', 'edit'])],
        ]);

        $report->getConnection()->transaction(function () use ($report, $data) {
            if (isset($data['visibility'])) {
                $report->update(['visibility' => $data['visibility']]);
            }
            $report->shares()->delete();
            $unique = collect($data['shares'])->unique(fn ($s) => $s['type'].':'.$s['id']);
            foreach ($unique as $share) {
                ReportShare::query()->create([
                    'report_id' => $report->id,
                    'shareable_type' => $share['type'],
                    'shareable_id' => $share['id'],
                    'permission' => $share['permission'] ?? 'view',
                ]);
            }
        });

        return new JsonResponse(['data' => $this->full($report->refresh()->load('shares'), $request)]);
    }

    // ----------------------------------------------------------------------

    private function validated(Request $request, bool $partial = false): array
    {
        $required = $partial ? 'sometimes' : 'required';

        return $request->validate([
            'name' => [$required, 'string', 'max:255'],
            'description' => ['sometimes', 'nullable', 'string', 'max:2000'],
            'folder' => ['sometimes', 'nullable', 'string', 'max:255'],
            'visibility' => ['sometimes', Rule::in([Report::PRIVATE, Report::PUBLIC])],
            'definition' => [$required, 'array'],
        ]);
    }

    /**
     * A report is only saved if it compiles for the person saving it. Missing
     * values for required parameters are fine: they are asked when it runs.
     */
    private function checkDefinition(array $definition, Request $request): void
    {
        $this->engine->check($definition, $request->user());
    }

    private function authorizeView(Report $report, Request $request): void
    {
        abort_unless($this->access->canView($report, $request->user()), 403, 'You do not have access to this report.');
    }

    private function authorizeEdit(Report $report, Request $request): void
    {
        abort_unless($this->access->canEdit($report, $request->user()), 403, 'You can open this report but not change it. Save a copy instead.');
    }

    private function summary(Report $report, Request $request): array
    {
        $user = $request->user();

        return [
            'uuid' => $report->uuid,
            'name' => $report->name,
            'description' => $report->description,
            'folder' => $report->folder,
            'visibility' => $report->visibility,
            'root' => $report->definition['root'] ?? null,
            'fields_count' => count($report->definition['fields'] ?? []),
            'is_owner' => $this->access->isOwner($report, $user),
            'can_edit' => $this->access->canEdit($report, $user),
            'shared' => $report->visibility === Report::PUBLIC || $report->shares->isNotEmpty(),
            'published' => $report->permission !== null,
            'permission' => $report->permission,
            'in_menu' => (bool) $report->in_menu,
            'menu_group' => $report->menu_group,
            'can_publish' => $this->access->canPublish($user),
            'model_version' => $report->model_version,
            'updated_at' => $report->updated_at?->toIso8601String(),
        ];
    }

    private function full(Report $report, Request $request): array
    {
        $isOwner = $this->access->isOwner($report, $request->user());

        return $this->summary($report, $request) + [
            'definition' => $report->definition,
            // Who it is shared with is the owner's business.
            'shares' => $isOwner ? $report->shares->map(fn (ReportShare $s) => [
                'type' => $s->shareable_type, 'id' => $s->shareable_id, 'permission' => $s->permission,
            ])->values() : [],
            'created_at' => $report->created_at?->toIso8601String(),
        ];
    }
}
