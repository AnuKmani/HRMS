<?php

namespace App\Http\Requests;

use App\Models\Department;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * POST /api/v1/departments
 *
 * `code` is the identity of the row as far as clients are concerned, so it is
 * unique across soft-deleted rows too — matching the database's own unique
 * index. Reusing the code of a department someone archived last month is a
 * support problem, not a convenience.
 */
class StoreDepartmentRequest extends FormRequest
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
        return [
            'name' => ['required', 'string', 'max:120'],
            'code' => ['required', 'string', 'max:30', 'alpha_dash', Rule::unique('departments', 'code')],
            'description' => ['nullable', 'string', 'max:500'],
            'status' => ['required', Rule::in(Department::STATUSES)],
        ];
    }
}
