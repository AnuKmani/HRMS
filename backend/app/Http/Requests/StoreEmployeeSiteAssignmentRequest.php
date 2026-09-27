<?php

namespace App\Http\Requests;

use App\Models\EmployeeSiteAssignment;
use App\Models\Site;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

/**
 * POST /api/v1/employee-site-assignments
 *
 * The rule that earns its keep is the last one: the site must belong to the
 * project named beside it. Both columns exist independently in the table, so
 * nothing in the schema stops `project A / site of project B` — and every
 * later query that filters on one of them would disagree with every query
 * that filters on the other.
 *
 * There is no `delete` on this resource at all. Ending a posting is an
 * update; the row stays.
 */
class StoreEmployeeSiteAssignmentRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('assignments.manage') ?? false;
    }

    /**
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        return [
            'employee_id' => ['required', 'integer', Rule::exists('employees', 'id')->whereNull('deleted_at')],
            'project_id' => ['required', 'integer', Rule::exists('projects', 'id')->whereNull('deleted_at')],
            'site_id' => ['required', 'integer', Rule::exists('sites', 'id')->whereNull('deleted_at')],
            'start_date' => ['required', 'date'],
            'end_date' => ['nullable', 'date', 'after_or_equal:start_date'],
            'assignment_type' => ['required', Rule::in(EmployeeSiteAssignment::TYPES)],
            'status' => ['nullable', Rule::in(EmployeeSiteAssignment::STATUSES)],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator) {
            if (! $this->filled(['project_id', 'site_id'])) {
                return;
            }

            $owner = Site::query()
                ->whereKey($this->input('site_id'))
                ->value('project_id');

            if ($owner !== null && (int) $owner !== (int) $this->input('project_id')) {
                $validator->errors()->add(
                    'site_id',
                    'The selected site does not belong to the selected project.',
                );
            }
        });
    }
}
