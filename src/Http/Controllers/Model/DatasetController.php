<?php

namespace Abdalla\ReportGenerator\Http\Controllers\Model;

use Abdalla\ReportGenerator\Enums\FieldRole;
use Abdalla\ReportGenerator\Http\Presenters\ModelPresenter;
use Abdalla\ReportGenerator\Models\Dataset;
use Abdalla\ReportGenerator\Models\Relationship;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Validation\Rule;

class DatasetController extends Controller
{
    public function index(): JsonResponse
    {
        $pending = fn ($q) => $q->where('status', Relationship::PENDING);

        $datasets = Dataset::query()
            ->withCount([
                'fields',
                'fields as visible_fields_count' => fn ($q) => $q->where('is_hidden', false),
                'outgoing as pending_outgoing_count' => $pending,
                'incoming as pending_incoming_count' => $pending,
            ])
            ->orderBy('label')
            ->get();

        return new JsonResponse([
            'data' => $datasets->map(fn (Dataset $d) => ModelPresenter::datasetSummary($d))->values(),
        ]);
    }

    public function show(Dataset $dataset): JsonResponse
    {
        $relationships = Relationship::query()
            ->with(['fromDataset:id,key,label', 'toDataset:id,key,label'])
            ->where(fn ($q) => $q->where('from_dataset_id', $dataset->id)->orWhere('to_dataset_id', $dataset->id))
            ->orderByRaw("case status when 'pending' then 0 when 'confirmed' then 1 else 2 end")
            ->orderByDesc('confidence')
            ->get();

        return new JsonResponse([
            'data' => ModelPresenter::dataset($dataset) + [
                'fields' => $dataset->fields->map(fn ($f) => ModelPresenter::field($f))->values(),
                'relationships' => $relationships->map(fn ($r) => ModelPresenter::relationship($r))->values(),
            ],
        ]);
    }

    public function update(Request $request, Dataset $dataset): JsonResponse
    {
        $timeFields = $dataset->fields()->where('role', FieldRole::Time->value)->pluck('key')->all();

        $data = $request->validate([
            'label' => ['sometimes', 'required', 'string', 'max:255'],
            'description' => ['sometimes', 'nullable', 'string', 'max:2000'],
            'is_hidden' => ['sometimes', 'boolean'],
            'default_time_field' => ['sometimes', 'nullable', 'string', Rule::in($timeFields)],
        ], [
            'default_time_field.in' => 'The default date must be one of this dataset\'s date fields.',
        ]);

        $dataset->update($data);

        return new JsonResponse(['data' => ModelPresenter::dataset($dataset->refresh())]);
    }
}
