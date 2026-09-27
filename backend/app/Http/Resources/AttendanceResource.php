<?php

namespace App\Http\Resources;

use App\Models\Attendance;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * One attendance row, as the client may see it.
 *
 * The single most important thing this class does NOT do: it never emits
 * `check_in_selfie_path` or `check_out_selfie_path`. Those are storage
 * paths — `storage/app/private/attendance-selfies/7/<uuid>.jpg` tells a
 * reader where a file lives, how this app organises private data, and gives
 * them something to guess at. The selfie arrives only through
 * `GET /attendance/{id}/selfie`, behind the policy that governs the row
 * itself, and all this resource says about it is `has_selfie`.
 *
 * Distance is included because the server computed it: the client's own
 * estimate is advisory and may differ by a few metres, and showing the
 * number that actually decided is the honest one.
 */
class AttendanceResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        /** @var Attendance $resource */
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

            'attendance_date' => $resource->attendance_date?->toDateString(),
            'check_in_at' => $resource->check_in_at?->toIso8601String(),
            'check_out_at' => $resource->check_out_at?->toIso8601String(),

            'check_in_latitude' => $resource->check_in_latitude,
            'check_in_longitude' => $resource->check_in_longitude,
            'check_in_accuracy' => $resource->check_in_accuracy,
            'check_in_distance' => $resource->check_in_distance,
            'has_selfie' => $resource->hasSelfie(),

            'check_out_latitude' => $resource->check_out_latitude,
            'check_out_longitude' => $resource->check_out_longitude,
            'check_out_accuracy' => $resource->check_out_accuracy,
            'check_out_distance' => $resource->check_out_distance,

            'shift_id' => $resource->shift_id,
            'shift' => $this->whenLoaded('shift', fn () => $resource->shift
                ? [
                    'id' => $resource->shift->id,
                    'name' => $resource->shift->name,
                    'start_time' => $resource->shift->start_time,
                    'end_time' => $resource->shift->end_time,
                ]
                : null),
            'scheduled_start_at' => $resource->scheduled_start_at?->toIso8601String(),
            'scheduled_end_at' => $resource->scheduled_end_at?->toIso8601String(),

            'working_minutes' => (int) $resource->working_minutes,
            'break_minutes' => (int) $resource->break_minutes,
            'overtime_minutes' => (int) $resource->overtime_minutes,
            'late_minutes' => (int) $resource->late_minutes,
            'early_departure_minutes' => (int) $resource->early_departure_minutes,

            'status' => $resource->status,
            'source' => $resource->source,
            'notes' => $resource->notes,

            'created_at' => $resource->created_at?->toIso8601String(),
            'updated_at' => $resource->updated_at?->toIso8601String(),
        ];
    }
}
