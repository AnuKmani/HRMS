<?php

namespace App\Http\Requests;

use App\Models\Department;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * PUT /api/v1/departments/{department}
 *
 * Every field `sometimes`: a PUT from a form carries everything, but a PUT
 * from an integration that only wants to deactivate a row should not be made
 * to re-send its description. Omitted fields keep the value they have.
 */
class UpdateDepartmentRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('departments.manage') ?? false;
    }

    /**
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        $id = $this->route('department')?->id ?? $this->route('department');

        return [
            'name' => ['sometimes', 'string', 'max:120'],
            'code' => [
                'sometimes', 'string', 'max:30', 'alpha_dash',
                Rule::unique('departments', 'code')->ignore($id),
            ],
            'description' => ['nullable', 'string', 'max:500'],
            'status' => ['sometimes', Rule::in(Department::STATUSES)],
        ];
    }
}
