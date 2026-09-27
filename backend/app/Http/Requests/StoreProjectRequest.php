<?php

namespace App\Http\Requests;

use App\Models\Project;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

/**
 * POST /api/v1/projects
 *
 * The date order is checked in `withValidator` rather than by
 * `after_or_equal:start_date`, because that rule compares against null — and
 * therefore silently passes — whenever `start_date` is absent. Here an end
 * date with no start date at all is called out by name instead of being
 * quietly accepted.
 */
class StoreProjectRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('projects.manage') ?? false;
    }

    /**
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:150'],
            'code' => ['required', 'string', 'max:30', 'alpha_dash', Rule::unique('projects', 'code')],
            'client' => ['nullable', 'string', 'max:150'],
            'description' => ['nullable', 'string', 'max:1000'],
            'location' => ['nullable', 'string', 'max:500'],
            'project_manager_id' => ['nullable', 'integer', $this->liveEmployee()],
            'start_date' => ['nullable', 'date'],
            'end_date' => ['nullable', 'date'],
            'status' => ['required', Rule::in(Project::STATUSES)],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator) {
            $end = $this->filled('end_date') ? $this->input('end_date') : null;

            if ($end === null) {
                return;
            }

            $start = $this->filled('start_date') ? $this->input('start_date') : null;

            if ($start === null) {
                $validator->errors()->add(
                    'end_date',
                    'A project needs a start date before it can have an end date.',
                );

                return;
            }

            if (strtotime((string) $end) < strtotime((string) $start)) {
                $validator->errors()->add(
                    'end_date',
                    'The end date must be on or after the start date.',
                );
            }
        });
    }

    // `mixed`, not `Rule` — see StoreDesignationRequest::liveDepartment().
    private function liveEmployee(): mixed
    {
        return Rule::exists('employees', 'id')->whereNull('deleted_at');
    }
}
