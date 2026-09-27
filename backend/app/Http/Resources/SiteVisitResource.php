<?php

namespace App\Http\Resources;

use App\Models\SiteVisit;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * One bounded site visit.
 *
 * Same discipline as AttendanceResource: `start_*` / `end_*` are the two
 * points the visitor deliberately recorded, and there is nothing between
 * them because nothing was ever captured between them.
 */
class SiteVisitResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        /** @var SiteVisit $resource */
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

            'started_at' => $resource->started_at?->toIso8601String(),
            'ended_at' => $resource->ended_at?->toIso8601String(),
            'duration_minutes' => $resource->durationMinutes(),

            'start_latitude' => $resource->start_latitude,
            'start_longitude' => $resource->start_longitude,
            'start_accuracy' => $resource->start_accuracy,
            'start_distance' => $resource->start_distance,

            'end_latitude' => $resource->end_latitude,
            'end_longitude' => $resource->end_longitude,
            'end_accuracy' => $resource->end_accuracy,
            'end_distance' => $resource->end_distance,

            'purpose' => $resource->purpose,
            'remarks' => $resource->remarks,
            'status' => $resource->status,

            'created_at' => $resource->created_at?->toIso8601String(),
            'updated_at' => $resource->updated_at?->toIso8601String(),
        ];
    }
}
