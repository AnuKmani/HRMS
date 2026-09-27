<?php

namespace App\Http\Requests;

use App\Http\Requests\Concerns\ValidatesEmployeeRelations;
use App\Models\Employee;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

/**
 * POST /api/v1/employees
 *
 * Two absences are deliberate and worth naming:
 *
 *  - **`photo_path` is not a field here.** It is a server-side path into
 *    private storage; accepting one from a client would let anybody write an
 *    arbitrary string into a column that later becomes a file reference.
 *    Profile photo upload belongs to the documents phase, which owns the
 *    storage and the signed-URL machinery, and is not implemented yet.
 *  - **`user_id` is not a field here.** Linking a login to a person is an
 *    account action with its own permissions, not a side effect of creating
 *    a roster entry.
 *
 * `salary` appears only for a caller holding `employees.salary.view` — see
 * ValidatesEmployeeRelations.
 */
class StoreEmployeeRequest extends FormRequest
{
    use ValidatesEmployeeRelations;

    public function authorize(): bool
    {
        return $this->user()?->can('employees.create') ?? false;
    }

    /**
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        return array_merge([
            'employee_code' => ['required', 'string', 'max:30', 'alpha_dash', Rule::unique('employees', 'employee_code')],
            'first_name' => ['required', 'string', 'max:80'],
            'middle_name' => ['nullable', 'string', 'max:80'],
            'last_name' => ['required', 'string', 'max:80'],
            'email' => ['required', 'string', 'email:rfc', 'max:190', Rule::unique('employees', 'email')],
            'phone' => ['nullable', 'string', 'max:30'],

            'date_of_birth' => ['nullable', 'date', 'before:today'],
            'nationality' => ['nullable', 'string', 'max:80'],
            'address' => ['nullable', 'string', 'max:500'],
            'emergency_contact_name' => ['nullable', 'string', 'max:150'],
            'emergency_contact_phone' => ['nullable', 'string', 'max:30'],
            'emergency_contact_relation' => ['nullable', 'string', 'max:60'],

            'joining_date' => ['required', 'date'],
            'department_id' => ['nullable', 'integer', Rule::exists('departments', 'id')->whereNull('deleted_at')],
            'designation_id' => ['nullable', 'integer', Rule::exists('designations', 'id')->whereNull('deleted_at')],
            'employment_type' => ['required', Rule::in(Employee::TYPES)],
            'reporting_manager_id' => ['nullable', 'integer', Rule::exists('employees', 'id')->whereNull('deleted_at')],
            'primary_project_id' => ['nullable', 'integer', Rule::exists('projects', 'id')->whereNull('deleted_at')],
            'primary_site_id' => ['nullable', 'integer', Rule::exists('sites', 'id')->whereNull('deleted_at')],
            'employment_status' => ['required', Rule::in(Employee::STATUSES)],
        ], $this->salaryRules());
    }

    public function withValidator(Validator $validator): void
    {
        $this->checkEmployeeRelations($validator);
    }
}
