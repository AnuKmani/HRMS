<?php

namespace App\Http\Requests;

use App\Http\Requests\Concerns\ValidatesEmployeeRelations;
use App\Models\Employee;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

/**
 * PUT /api/v1/employees/{employee}
 *
 * `sometimes` throughout — a PATCH-shaped PUT from an integration that only
 * wants to deactivate someone should not have to replay their emergency
 * contact. Explicit nulls still clear: `nullable` distinguishes "set this to
 * nothing" from "leave it alone", which is exactly the difference between
 * removing a reporting manager and never having had one.
 *
 * `employee_code` and `email` stay unique against everyone else, including
 * soft-deleted rows (the database's own indexes do the same).
 */
class UpdateEmployeeRequest extends FormRequest
{
    use ValidatesEmployeeRelations;

    public function authorize(): bool
    {
        return $this->user()?->can('employees.update') ?? false;
    }

    /**
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        /** @var Employee $employee */
        $employee = $this->route('employee');

        return array_merge([
            'employee_code' => [
                'sometimes', 'string', 'max:30', 'alpha_dash',
                Rule::unique('employees', 'employee_code')->ignore($employee->id),
            ],
            'first_name' => ['sometimes', 'string', 'max:80'],
            'middle_name' => ['nullable', 'string', 'max:80'],
            'last_name' => ['sometimes', 'string', 'max:80'],
            'email' => [
                'sometimes', 'string', 'email:rfc', 'max:190',
                Rule::unique('employees', 'email')->ignore($employee->id),
            ],
            'phone' => ['nullable', 'string', 'max:30'],

            'date_of_birth' => ['nullable', 'date', 'before:today'],
            'nationality' => ['nullable', 'string', 'max:80'],
            'address' => ['nullable', 'string', 'max:500'],
            'emergency_contact_name' => ['nullable', 'string', 'max:150'],
            'emergency_contact_phone' => ['nullable', 'string', 'max:30'],
            'emergency_contact_relation' => ['nullable', 'string', 'max:60'],

            'joining_date' => ['sometimes', 'date'],
            'department_id' => ['nullable', 'integer', Rule::exists('departments', 'id')->whereNull('deleted_at')],
            'designation_id' => ['nullable', 'integer', Rule::exists('designations', 'id')->whereNull('deleted_at')],
            'employment_type' => ['sometimes', Rule::in(Employee::TYPES)],
            'reporting_manager_id' => ['nullable', 'integer', Rule::exists('employees', 'id')->whereNull('deleted_at')],
            'primary_project_id' => ['nullable', 'integer', Rule::exists('projects', 'id')->whereNull('deleted_at')],
            'primary_site_id' => ['nullable', 'integer', Rule::exists('sites', 'id')->whereNull('deleted_at')],
            'employment_status' => ['sometimes', Rule::in(Employee::STATUSES)],
        ], $this->salaryRules());
    }

    public function withValidator(Validator $validator): void
    {
        $this->checkEmployeeRelations($validator, $this->route('employee')?->id);
    }
}
