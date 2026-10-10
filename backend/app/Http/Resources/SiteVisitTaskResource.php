<?php

namespace App\Http\Resources;

use App\Models\SiteVisitTask;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class SiteVisitTaskResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        /** @var SiteVisitTask $task */
        $task = $this->resource;
        $me = $request->user();

        return [
            'id' => $task->id,
            'visit_date' => $task->visit_date?->toDateString(),
            'status' => $task->displayStatus(),
            'purpose' => $task->purpose,
            'notes' => $task->notes,
            'project_id' => $task->project_id,
            'project_name' => $task->project?->name,
            'site_id' => $task->site_id,
            'site_name' => $task->site?->name,
            'employee_id' => $task->employee_id,
            'employee_name' => $task->employee?->full_name,
            'assigned_by_name' => $task->assigner?->name,
            'completed_at' => $task->completed_at?->toIso8601String(),
            'completion_note' => $task->completion_note,
            'site_visit_id' => $task->site_visit_id,
            // What the caller may do with this row, decided here once.
            'is_mine' => $me?->employee !== null && (int) $me->employee->id === (int) $task->employee_id,
            'assigned_by_me' => $me !== null && (int) $task->assigned_by === (int) $me->id,
        ];
    }
}
