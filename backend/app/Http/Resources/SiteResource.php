<?php

namespace App\Http\Resources;

use App\Models\Site;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * A site, coordinates and all.
 *
 * Latitude, longitude and the geofence radius are read straight off the row.
 * Nothing in the response — and nothing in the code that builds it — derives
 * them from a constant: two sites of the same project routinely sit hundreds
 * of kilometres apart, and a hard-coded origin is precisely the bug that
 * makes geofencing fail silently for every deployment but the first one.
 *
 * Manager and supervisor go out as EmployeeResource (no salary), same as the
 * project manager on a project.
 */
class SiteResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        /** @var Site $resource */
        $resource = $this->resource;

        return [
            'id' => $resource->id,

            'project_id' => $resource->project_id,
            'project' => $this->whenLoaded('project', fn () => $resource->project
                ? ['id' => $resource->project->id, 'name' => $resource->project->name, 'code' => $resource->project->code]
                : null),

            'name' => $resource->name,
            'code' => $resource->code,
            'address' => $resource->address,

            'latitude' => $resource->latitude,
            'longitude' => $resource->longitude,
            // Metres, from the column. Configured per deployment in
            // config/hrms.php only as a *bound on what may be written*.
            'geofence_radius' => $resource->geofence_radius,

            'site_manager_id' => $resource->site_manager_id,
            'site_manager' => $this->whenLoaded('siteManager', fn () => $resource->siteManager
                ? new EmployeeResource($resource->siteManager)
                : null),

            'site_supervisor_id' => $resource->site_supervisor_id,
            'site_supervisor' => $this->whenLoaded('siteSupervisor', fn () => $resource->siteSupervisor
                ? new EmployeeResource($resource->siteSupervisor)
                : null),

            'shift_id' => $resource->shift_id,
            'shift' => $this->whenLoaded('shift', fn () => $resource->shift
                ? ['id' => $resource->shift->id, 'name' => $resource->shift->name, 'code' => $resource->shift->code]
                : null),

            'working_hours_setting_id' => $resource->working_hours_setting_id,
            'status' => $resource->status,

            'created_at' => $resource->created_at?->toIso8601String(),
        ];
    }
}
