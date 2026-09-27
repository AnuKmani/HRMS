<?php

namespace App\Http\Requests;

use App\Models\EmployeeSiteAssignment;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

/**
 * PUT /api/v1/employee-site-assignments/{assignment}
 *
 * This endpoint exists to *close* a posting, not to rewrite one. The fields
 * that make a row historical — who, where, from when, of what kind — are
 * refused outright rather than silently ignored: a client that sends them
 * thinks it changed something, and being told nothing back would be a lie by
 * omission. Ending it and creating a next row is how the record is meant to
 * grow.
 *
 * Reopening is refused too. `ended` is a conclusion; a row that went from
 * ended back to active would say somebody was standing on a site during the
 * months the table says they were not.
 */
class UpdateEmployeeSiteAssignmentRequest extends FormRequest
{
    /** Fields that must never change once the row exists. */
    private const IMMUTABLE = [
        'employee_id',
        'project_id',
        'site_id',
        'assignment_type',
        'start_date',
    ];

    public function authorize(): bool
    {
        return $this->user()?->can('assignments.manage') ?? false;
    }

    /**
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        /** @var EmployeeSiteAssignment $assignment */
        $assignment = $this->route('assignment');

        return [
            'status' => ['required', Rule::in(['ended', 'cancelled'])],
            'end_date' => ['nullable', 'date', 'after_or_equal:'.$assignment->start_date->toDateString()],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator) {
            $sent = array_intersect_key($this->all(), array_flip(self::IMMUTABLE));

            foreach (array_keys($sent) as $field) {
                $validator->errors()->add(
                    $field,
                    'Assignment history cannot be rewritten. End this assignment and create a new one instead.',
                );
            }
        });
    }
}
