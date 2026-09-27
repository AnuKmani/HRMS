<?php

namespace App\Http\Requests;

use App\Http\Requests\Concerns\ValidatesSiteCoordinates;
use App\Models\Site;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

/**
 * POST /api/v1/sites
 *
 * `project_id` is required and must be a live project: a site with no
 * project is an orphan the assignment table can never reference, because
 * `employee_site_assignments` carries both and insists they agree.
 *
 * Coordinates, radius and shift come from the row — never from a constant
 * anywhere in this codebase. The bounds on radius are configured in
 * config/hrms.php so a deployment can move its own ceiling.
 */
class StoreSiteRequest extends FormRequest
{
    use ValidatesSiteCoordinates;

    public function authorize(): bool
    {
        return $this->user()?->can('sites.manage') ?? false;
    }

    /**
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        return array_merge([
            'project_id' => ['required', 'integer', Rule::exists('projects', 'id')->whereNull('deleted_at')],
            'name' => ['required', 'string', 'max:150'],
            'code' => ['required', 'string', 'max:30', 'alpha_dash', Rule::unique('sites', 'code')],
            'address' => ['nullable', 'string', 'max:500'],
            'site_manager_id' => ['nullable', 'integer', Rule::exists('employees', 'id')->whereNull('deleted_at')],
            'site_supervisor_id' => ['nullable', 'integer', Rule::exists('employees', 'id')->whereNull('deleted_at')],
            'working_hours_setting_id' => ['nullable', 'integer', Rule::exists('settings', 'id')],
            'shift_id' => ['nullable', 'integer', Rule::exists('shifts', 'id')->whereNull('deleted_at')],
            'status' => ['required', Rule::in(Site::STATUSES)],
        ], $this->coordinateRules());
    }

    public function withValidator(Validator $validator): void
    {
        $this->checkCoordinateConsistency($validator);

        $validator->after(function (Validator $validator) {
            $manager = $this->input('site_manager_id');
            $supervisor = $this->input('site_supervisor_id');

            if ($manager !== null && $supervisor !== null && (int) $manager === (int) $supervisor) {
                $validator->errors()->add(
                    'site_supervisor_id',
                    'The supervisor must be a different person from the site manager.',
                );
            }
        });
    }
}
