<?php

namespace Abdalla\ReportGenerator\Http\Controllers\Model;

use Abdalla\ReportGenerator\Enums\Aggregation;
use Abdalla\ReportGenerator\Enums\FieldRole;
use Abdalla\ReportGenerator\Http\Presenters\ModelPresenter;
use Abdalla\ReportGenerator\Models\Field;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class FieldController extends Controller
{
    public function update(Request $request, Field $field): JsonResponse
    {
        $allowed = array_map(fn (Aggregation $a) => $a->value, Aggregation::forType($field->data_type));

        $data = $request->validate([
            'label' => ['sometimes', 'required', 'string', 'max:255'],
            'description' => ['sometimes', 'nullable', 'string', 'max:2000'],
            'role' => ['sometimes', Rule::enum(FieldRole::class)],
            'format' => ['sometimes', 'nullable', 'string', 'max:64'],
            'is_hidden' => ['sometimes', 'boolean'],
            'aggregations' => ['sometimes', 'array'],
            'aggregations.*' => ['string', Rule::in($allowed)],
            'confirm_sensitive' => ['sometimes', 'boolean'],
        ], [
            'aggregations.*.in' => 'A '.$field->data_type->value.' field can only use: '.implode(', ', $allowed).'.',
        ]);

        $role = isset($data['role']) ? FieldRole::from($data['role']) : $field->role;

        if ($role === FieldRole::Time && ! $field->data_type->isTemporal()) {
            throw ValidationException::withMessages(['role' => 'Only date and date-time fields can be time fields.']);
        }

        if (($data['is_hidden'] ?? true) === false) {
            if (($field->meta['hidden_reason'] ?? null) === 'unsupported') {
                throw ValidationException::withMessages(['is_hidden' => 'This column\'s database type cannot be used in reports.']);
            }
            if ($field->is_sensitive && ! ($data['confirm_sensitive'] ?? false)) {
                throw ValidationException::withMessages([
                    'confirm_sensitive' => 'This column looks sensitive (passwords, tokens, secrets). Confirm to show it in reports.',
                ]);
            }
        }

        unset($data['confirm_sensitive']);

        // Becoming a measure without a choice of aggregations: offer everything the type supports.
        if ($role === FieldRole::Measure && ! isset($data['aggregations']) && empty($field->aggregations)) {
            $data['aggregations'] = $allowed;
        }
        if ($role !== FieldRole::Measure && isset($data['role'])) {
            $data['aggregations'] = null;
        }

        $field->update($data);

        return new JsonResponse(['data' => ModelPresenter::field($field->refresh())]);
    }
}
