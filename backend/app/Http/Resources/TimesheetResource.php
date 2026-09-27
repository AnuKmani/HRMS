<?php

namespace App\Http\Resources;

use App\Models\Timesheet;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * One derived timesheet row.
 *
 * The three minutes are emitted as minutes *and* as hours, because the two
 * consumers want different things and doing the division in the client would
 * mean every screen agreed on rounding except the one that did not. The
 * server's rounding is `round(x / 60, 2)` on the model, and this resource
 * calls those methods rather than re-deriving them.
 *
 * `attendance_id` is exposed so a reader can tell a snapshot from the source
 * it came from (and so a "view the day" link has somewhere to go). It is
 * deliberately not used to imply that the two always agree — a timesheet is
 * what was true when the period was generated.
 */
class TimesheetResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        /** @var Timesheet $resource */
        $resource = $this->resource;

        return [
            'id' => $resource->id,

            'employee_id' => $resource->employee_id,
            'employee' => $this->whenLoaded('employee', fn () => new EmployeeBriefResource($resource->employee)),

            'timesheet_date' => $resource->timesheet_date?->toDateString(),

            'project_id' => $resource->project_id,
            'project' => $this->whenLoaded('project', fn () => $resource->project
                ? ['id' => $resource->project->id, 'name' => $resource->project->name, 'code' => $resource->project->code]
                : null),

            'site_id' => $resource->site_id,
            'site' => $this->whenLoaded('site', fn () => $resource->site
                ? ['id' => $resource->site->id, 'name' => $resource->site->name, 'code' => $resource->site->code]
                : null),

            'shift_id' => $resource->shift_id,
            'shift' => $this->whenLoaded('shift', fn () => $resource->shift
                ? [
                    'id' => $resource->shift->id,
                    'name' => $resource->shift->name,
                    'start_time' => $resource->shift->start_time,
                    'end_time' => $resource->shift->end_time,
                ]
                : null),

            'attendance_id' => $resource->attendance_id,
            'check_in_at' => $resource->check_in_at?->toIso8601String(),
            'check_out_at' => $resource->check_out_at?->toIso8601String(),

            'working_minutes' => $resource->working_minutes,
            'break_minutes' => $resource->break_minutes,
            'overtime_minutes' => $resource->overtime_minutes,
            'working_hours' => $resource->workingHours(),
            'overtime_hours' => $resource->overtimeHours(),

            'status' => $resource->status,
            'notes' => $resource->notes,

            'created_at' => $resource->created_at?->toIso8601String(),
            'updated_at' => $resource->updated_at?->toIso8601String(),
        ];
    }
}
