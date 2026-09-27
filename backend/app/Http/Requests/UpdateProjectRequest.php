<?php

namespace App\Http\Requests;

use App\Models\Project;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

/**
 * PUT /api/v1/projects/{project}
 *
 * Every field `sometimes`, and the date order is checked against whichever
 * start date is actually in play: the one this payload carries when it
 * carries one, the row's own when it does not. Delegating that to
 * `after_or_equal:start_date` would compare against null and pass — letting a
 * shortening request slip the end date ahead of a start already stored.
 */
class UpdateProjectRequest extends FormRequest
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
        $id = $this->route('project')?->id ?? $this->route('project');

        return [
            'name' => ['sometimes', 'string', 'max:150'],
            'code' => [
                'sometimes', 'string', 'max:30', 'alpha_dash',
                Rule::unique('projects', 'code')->ignore($id),
            ],
            'client' => ['nullable', 'string', 'max:150'],
            'description' => ['nullable', 'string', 'max:1000'],
            'location' => ['nullable', 'string', 'max:500'],
            'project_manager_id' => ['sometimes', 'nullable', 'integer', Rule::exists('employees', 'id')->whereNull('deleted_at')],
            'start_date' => ['sometimes', 'nullable', 'date'],
            'end_date' => ['sometimes', 'nullable', 'date'],
            'status' => ['sometimes', Rule::in(Project::STATUSES)],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator) {
            if (! array_key_exists('end_date', $this->all())) {
                return;
            }

            $end = $this->input('end_date');

            if ($end === null || $end === '') {
                return;
            }

            $start = $this->input('start_date');

            if ($start === null || $start === '') {
                $start = $this->route('project')?->start_date?->toDateString();
            }

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
}
