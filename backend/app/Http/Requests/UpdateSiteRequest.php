<?php

namespace App\Http\Requests;

use App\Http\Requests\Concerns\ValidatesSiteCoordinates;
use App\Models\Site;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

/**
 * PUT /api/v1/sites/{site}
 *
 * `sometimes` throughout, so a client may move a site's project or rewrite
 * its address without re-sending coordinates it has no reason to repeat. The
 * coordinate consistency check reads the row for anything the payload leaves
 * out — see ValidatesSiteCoordinates.
 */
class UpdateSiteRequest extends FormRequest
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
        $id = $this->route('site')?->id ?? $this->route('site');

        return array_merge([
            'project_id' => ['sometimes', 'integer', Rule::exists('projects', 'id')->whereNull('deleted_at')],
            'name' => ['sometimes', 'string', 'max:150'],
            'code' => [
                'sometimes', 'string', 'max:30', 'alpha_dash',
                Rule::unique('sites', 'code')->ignore($id),
            ],
            'address' => ['nullable', 'string', 'max:500'],
            'site_manager_id' => ['sometimes', 'nullable', 'integer', Rule::exists('employees', 'id')->whereNull('deleted_at')],
            'site_supervisor_id' => ['sometimes', 'nullable', 'integer', Rule::exists('employees', 'id')->whereNull('deleted_at')],
            'working_hours_setting_id' => ['sometimes', 'nullable', 'integer', Rule::exists('settings', 'id')],
            'shift_id' => ['sometimes', 'nullable', 'integer', Rule::exists('shifts', 'id')->whereNull('deleted_at')],
            'status' => ['sometimes', Rule::in(Site::STATUSES)],
        ], $this->coordinateRules());
    }

    public function withValidator(Validator $validator): void
    {
        $this->checkCoordinateConsistency($validator);

        $validator->after(function (Validator $validator) {
            /** @var Site|null $site */
            $site = $this->route('site');

            $manager = $this->input('site_manager_id', $site?->site_manager_id);
            $supervisor = $this->input('site_supervisor_id', $site?->site_supervisor_id);

            if ($manager !== null && $supervisor !== null && (int) $manager === (int) $supervisor) {
                $validator->errors()->add(
                    'site_supervisor_id',
                    'The supervisor must be a different person from the site manager.',
                );
            }
        });
    }
}
