<?php

namespace App\Http\Resources;

use App\Models\Project;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * A project.
 *
 * The project manager is rendered through EmployeeResource — the projection
 * with no salary on it — for the reason explained there: this payload is
 * reachable behind `projects.view`, which Management, Finance and every
 * field role holds, and none of them has asked about payroll.
 */
class ProjectResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        /** @var Project $resource */
        $resource = $this->resource;

        return [
            'id' => $resource->id,
            'name' => $resource->name,
            'code' => $resource->code,
            'client' => $resource->client,
            'description' => $resource->description,
            'location' => $resource->location,

            'project_manager_id' => $resource->project_manager_id,
            'project_manager' => $this->whenLoaded('projectManager', fn () => $resource->projectManager
                ? new EmployeeResource($resource->projectManager)
                : null),

            'start_date' => $resource->start_date?->toDateString(),
            'end_date' => $resource->end_date?->toDateString(),
            'status' => $resource->status,

            'sites_count' => $this->counted('sites_count'),
            'employees_count' => $this->counted('employees_count'),

            'created_at' => $resource->created_at?->toIso8601String(),
        ];
    }

    private function counted(string $attribute): ?int
    {
        $attributes = $this->resource->getAttributes();

        return array_key_exists($attribute, $attributes) ? (int) $attributes[$attribute] : null;
    }
}
