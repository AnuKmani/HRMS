<?php

namespace App\Http\Requests;

use App\Models\Designation;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * PUT /api/v1/designations/{designation}
 *
 * `department_id` accepts an explicit null so a company-wide designation can
 * be detached from a team; `nullable` alone would not distinguish "clear it"
 * from "leave it alone", which is why the field is also `sometimes`.
 */
class UpdateDesignationRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('designations.manage') ?? false;
    }

    /**
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        $id = $this->route('designation')?->id ?? $this->route('designation');

        return [
            'department_id' => ['sometimes', 'nullable', 'integer', Rule::exists('departments', 'id')->whereNull('deleted_at')],
            'name' => ['sometimes', 'string', 'max:120'],
            'code' => [
                'sometimes', 'string', 'max:30', 'alpha_dash',
                Rule::unique('designations', 'code')->ignore($id),
            ],
            'description' => ['nullable', 'string', 'max:500'],
            'status' => ['sometimes', Rule::in(Designation::STATUSES)],
        ];
    }
}
