<?php

namespace App\Http\Resources;

use App\Models\Employee;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * The employee projection that goes out on list endpoints and as a nested
 * reference inside projects, sites and assignments.
 *
 * Salary, date of birth, address and the emergency contacts are absent —
 * not null, absent. This resource is embedded inside ProjectResource (the
 * project manager) and SiteResource (the manager and supervisor), so
 * whatever it carries travels into responses whose own gate is
 * `projects.view`, not `employees.view`. Putting payroll on it would leak a
 * figure to every role that can open a project, and there would be no
 * endpoint left to say no.
 *
 * Anything needing those fields asks for EmployeeDetailResource instead,
 * which is only ever built after EmployeePolicy::view has passed.
 */
class EmployeeResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        /** @var Employee $resource */
        $resource = $this->resource;

        return [
            'id' => $resource->id,
            'employee_code' => $resource->employee_code,
            'first_name' => $resource->first_name,
            'middle_name' => $resource->middle_name,
            'last_name' => $resource->last_name,
            'full_name' => $resource->full_name,
            'email' => $resource->email,
            'phone' => $resource->phone,
            'photo_path' => $resource->photo_path,

            'department_id' => $resource->department_id,
            'department' => $this->whenLoaded('department', fn () => $resource->department
                ? ['id' => $resource->department->id, 'name' => $resource->department->name]
                : null),

            'designation_id' => $resource->designation_id,
            'designation' => $this->whenLoaded('designation', fn () => $resource->designation
                ? ['id' => $resource->designation->id, 'name' => $resource->designation->name]
                : null),

            'employment_type' => $resource->employment_type,
            'employment_status' => $resource->employment_status,
            'joining_date' => $resource->joining_date?->toDateString(),

            'reporting_manager_id' => $resource->reporting_manager_id,
            'reporting_manager' => $this->whenLoaded('reportingManager', fn () => $resource->reportingManager
                ? new EmployeeResource($resource->reportingManager)
                : null),

            'primary_project_id' => $resource->primary_project_id,
            'primary_project' => $this->whenLoaded('primaryProject', fn () => $resource->primaryProject
                ? ['id' => $resource->primaryProject->id, 'name' => $resource->primaryProject->name, 'code' => $resource->primaryProject->code]
                : null),

            'primary_site_id' => $resource->primary_site_id,
            'primary_site' => $this->whenLoaded('primarySite', fn () => $resource->primarySite
                ? ['id' => $resource->primarySite->id, 'name' => $resource->primarySite->name, 'code' => $resource->primarySite->code]
                : null),
        ];
    }
}
