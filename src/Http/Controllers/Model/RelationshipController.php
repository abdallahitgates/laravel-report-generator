<?php

namespace Abdalla\ReportGenerator\Http\Controllers\Model;

use Abdalla\ReportGenerator\Enums\Cardinality;
use Abdalla\ReportGenerator\Http\Presenters\ModelPresenter;
use Abdalla\ReportGenerator\Models\Dataset;
use Abdalla\ReportGenerator\Models\Relationship;
use Abdalla\ReportGenerator\Schema\Relationships\InferredRelationship;
use Abdalla\ReportGenerator\Support\Tables;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class RelationshipController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $request->validate(['status' => ['nullable', Rule::in([Relationship::PENDING, Relationship::CONFIRMED, Relationship::REJECTED])]]);

        $relationships = Relationship::query()
            ->with(['fromDataset:id,key,label', 'toDataset:id,key,label'])
            ->when($request->query('status'), fn ($q, $status) => $q->where('status', $status))
            ->orderByDesc('confidence')
            ->orderBy('id')
            ->get();

        return new JsonResponse(['data' => $relationships->map(fn ($r) => ModelPresenter::relationship($r))->values()]);
    }

    public function store(Request $request): JsonResponse
    {
        $datasets = Tables::name(Tables::DATASETS);
        $connection = Tables::connection();
        $exists = $connection ? "{$connection}.{$datasets}" : $datasets;

        $data = $request->validate([
            'from_dataset_id' => ['required', 'integer', "exists:{$exists},id"],
            'from_column' => ['required', 'string', 'max:128'],
            'to_dataset_id' => ['required', 'integer', "exists:{$exists},id"],
            'to_column' => ['required', 'string', 'max:128'],
            'cardinality' => ['required', Rule::enum(Cardinality::class)],
            'join_type' => ['sometimes', Rule::in(['left', 'inner'])],
            'label' => ['sometimes', 'nullable', 'string', 'max:255'],
        ]);

        foreach (['from', 'to'] as $side) {
            $dataset = Dataset::query()->find($data["{$side}_dataset_id"]);
            if (! $dataset->fields()->where('column', $data["{$side}_column"])->exists()) {
                throw ValidationException::withMessages([
                    "{$side}_column" => "{$dataset->label} has no column named {$data["{$side}_column"]}.",
                ]);
            }
        }

        $duplicate = Relationship::query()->where([
            'from_dataset_id' => $data['from_dataset_id'], 'from_column' => $data['from_column'],
            'to_dataset_id' => $data['to_dataset_id'], 'to_column' => $data['to_column'],
        ])->exists();
        if ($duplicate) {
            throw ValidationException::withMessages(['from_column' => 'This relationship already exists.']);
        }

        $relationship = Relationship::query()->create($data + [
            'join_type' => 'left',
            'origin' => InferredRelationship::ORIGIN_MANUAL,
            'confidence' => 1,
            'status' => Relationship::CONFIRMED,
        ]);

        return new JsonResponse(['data' => ModelPresenter::relationship($relationship->load(['fromDataset', 'toDataset']))], 201);
    }

    public function update(Request $request, Relationship $relationship): JsonResponse
    {
        $data = $request->validate([
            'status' => ['sometimes', Rule::in([Relationship::PENDING, Relationship::CONFIRMED, Relationship::REJECTED])],
            'cardinality' => ['sometimes', Rule::enum(Cardinality::class)],
            'join_type' => ['sometimes', Rule::in(['left', 'inner'])],
            'label' => ['sometimes', 'nullable', 'string', 'max:255'],
        ]);

        $relationship->update($data);

        return new JsonResponse(['data' => ModelPresenter::relationship($relationship->load(['fromDataset', 'toDataset']))]);
    }

    public function bulk(Request $request): JsonResponse
    {
        $data = $request->validate([
            'ids' => ['required', 'array', 'max:1000'],
            'ids.*' => ['integer'],
            'status' => ['required', Rule::in([Relationship::CONFIRMED, Relationship::REJECTED])],
        ]);

        $updated = Relationship::query()->whereIn('id', $data['ids'])->update(['status' => $data['status'], 'updated_at' => now()]);

        return new JsonResponse(['updated' => $updated]);
    }

    public function destroy(Relationship $relationship): JsonResponse
    {
        if ($relationship->origin !== InferredRelationship::ORIGIN_MANUAL) {
            throw ValidationException::withMessages([
                'relationship' => 'Found relationships come back on the next scan. Reject it instead of deleting it.',
            ]);
        }

        $relationship->delete();

        return new JsonResponse(null, 204);
    }
}
