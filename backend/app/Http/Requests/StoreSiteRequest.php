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

            $duplicate = $this->existingDuplicate();

            if ($duplicate !== null) {
                $validator->errors()->add(
                    'name',
                    sprintf('This site is already added: %s (%s).', $duplicate->name, $duplicate->code),
                );
            }
        });
    }

    /**
     * There is one company-wide site list, shared by every project manager.
     * A second manager adding the same place gets told it is already there
     * instead of creating a twin: same name (ignoring case and spacing), or
     * a pin within `hrms.sites.duplicate_radius_m` of an existing site.
     */
    private function existingDuplicate(): ?Site
    {
        $name = mb_strtolower(preg_replace('/\s+/', ' ', trim((string) $this->input('name'))));

        if ($name !== '') {
            $byName = Site::query()
                ->whereRaw('LOWER(TRIM(name)) = ?', [$name])
                ->first();

            if ($byName !== null) {
                return $byName;
            }
        }

        $lat = $this->input('latitude');
        $lng = $this->input('longitude');
        $radius = (float) config('hrms.sites.duplicate_radius_m', 100);

        if (! is_numeric($lat) || ! is_numeric($lng) || $radius <= 0) {
            return null;
        }

        // A cheap bounding box first, then the real distance.
        $dLat = $radius / 111_320;
        $dLng = $radius / (111_320 * max(cos(deg2rad((float) $lat)), 0.01));

        return Site::query()
            ->whereNotNull('latitude')
            ->whereNotNull('longitude')
            ->whereBetween('latitude', [(float) $lat - $dLat, (float) $lat + $dLat])
            ->whereBetween('longitude', [(float) $lng - $dLng, (float) $lng + $dLng])
            ->get()
            ->first(fn (Site $site) => self::metresBetween(
                (float) $lat, (float) $lng, (float) $site->latitude, (float) $site->longitude,
            ) <= $radius);
    }

    private static function metresBetween(float $lat1, float $lng1, float $lat2, float $lng2): float
    {
        $r = 6_371_000;
        $dLat = deg2rad($lat2 - $lat1);
        $dLng = deg2rad($lng2 - $lng1);
        $a = sin($dLat / 2) ** 2 + cos(deg2rad($lat1)) * cos(deg2rad($lat2)) * sin($dLng / 2) ** 2;

        return 2 * $r * asin(min(1.0, sqrt($a)));
    }
}
