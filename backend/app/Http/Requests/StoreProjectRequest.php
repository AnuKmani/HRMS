<?php

namespace App\Http\Requests;

use App\Models\Project;
use App\Services\Projects\ProjectService;
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
            // Blank: the next PRJ-#### code is assigned.
            'code' => ['nullable', 'string', 'max:30', 'alpha_dash', Rule::unique('projects', 'code')],
            'client' => ['nullable', 'string', 'max:150'],
            'main_contractor' => ['nullable', 'string', 'max:150'],
            'description' => ['nullable', 'string', 'max:1000'],
            'location' => ['nullable', 'string', 'max:500'],
            'project_manager_id' => ['nullable', 'integer', $this->liveEmployee()],
            'start_date' => ['nullable', 'date'],
            'end_date' => ['nullable', 'date'],
            'status' => ['nullable', Rule::in(Project::STATUSES)],

            // Work stages with weights adding up to 100 (a template is used
            // when none are sent).
            'stages' => ['sometimes', 'array', 'min:1', 'max:30'],
            'stages.*.name' => ['required_with:stages', 'string', 'max:120'],
            'stages.*.weight' => ['required_with:stages', 'numeric', 'min:0.5', 'max:100'],

            'subcontractors' => ['sometimes', 'array', 'max:100'],
            'subcontractors.*.name' => ['required_with:subcontractors', 'string', 'max:150'],
            'subcontractors.*.trade' => ['nullable', 'string', 'max:100'],
            'subcontractors.*.contact_name' => ['nullable', 'string', 'max:120'],
            'subcontractors.*.contact_phone' => ['nullable', 'string', 'max:40'],
            'subcontractors.*.contract_value' => ['nullable', 'numeric', 'min:0'],
            'subcontractors.*.start_date' => ['nullable', 'date_format:Y-m-d'],
            'subcontractors.*.end_date' => ['nullable', 'date_format:Y-m-d'],

            'site_engineer_ids' => ['sometimes', 'array'],
            'site_engineer_ids.*' => ['integer', Rule::exists('employees', 'id')->whereNull('deleted_at')],
            'site_supervisor_ids' => ['sometimes', 'array'],
            'site_supervisor_ids.*' => ['integer', Rule::exists('employees', 'id')->whereNull('deleted_at')],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator) {
            // One company-wide project list: a second manager adding the
            // same project is told who already has it.
            if (is_string($this->input('name')) && ($existing = ProjectService::duplicateOf($this->input('name'))) !== null) {
                $validator->errors()->add('name', sprintf(
                    'This project is already added: %s (%s)%s.',
                    $existing->name,
                    $existing->code,
                    $existing->projectManager !== null ? ', managed by '.$existing->projectManager->full_name : '',
                ));
            }

            $stages = $this->input('stages');
            if (is_array($stages) && $stages !== []) {
                $total = array_sum(array_map(fn ($s) => (float) ($s['weight'] ?? 0), $stages));
                if (abs($total - 100) > 0.01) {
                    $validator->errors()->add('stages', sprintf('Stage weights must add up to 100%% (they add up to %s%%).', rtrim(rtrim(number_format($total, 2), '0'), '.')));
                }
            }

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
