<?php

namespace App\Http\Resources;

use App\Models\EmployeeSiteAssignment;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * One line of posting history.
 *
 * Every field that identifies the row — employee, project, site, type, start
 * date — is shown because none of them ever changes. What varies is `status`
 * and `end_date`, which is exactly what the only permitted update touches.
 *
 * `employee` is an EmployeeResource: the roster projection with no salary on
 * it. This payload is reachable behind `assignments.view`, which a Site
 * Supervisor holds, and a supervisor who can see who worked their site has
 * no business seeing what they were paid for it.
 */
class EmployeeSiteAssignmentResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        /** @var EmployeeSiteAssignment $resource */
        $resource = $this->resource;

        return [
            'id' => $resource->id,

            'employee_id' => $resource->employee_id,
            'employee' => $this->whenLoaded('employee', fn () => $resource->employee
                ? new EmployeeResource($resource->employee)
                : null),

            'project_id' => $resource->project_id,
            'project' => $this->whenLoaded('project', fn () => $resource->project
                ? ['id' => $resource->project->id, 'name' => $resource->project->name, 'code' => $resource->project->code]
                : null),

            'site_id' => $resource->site_id,
            'site' => $this->whenLoaded('site', fn () => $resource->site
                ? ['id' => $resource->site->id, 'name' => $resource->site->name, 'code' => $resource->site->code]
                : null),

            'assignment_type' => $resource->assignment_type,
            'start_date' => $resource->start_date?->toDateString(),
            'end_date' => $resource->end_date?->toDateString(),
            'status' => $resource->status,

            'created_at' => $resource->created_at?->toIso8601String(),
        ];
    }
}
