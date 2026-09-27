<?php

namespace App\Http\Resources;

use App\Models\OvertimeRequest;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * One overtime request.
 *
 * `requested_minutes` and `approved_minutes` are both emitted and are never
 * allowed to stand in for each other. The pair is the whole story of an
 * overtime claim — what was asked, and what was granted — and a screen that
 * showed only one of them would either overstate a refusal or understate a
 * trim.
 *
 * `payroll_eligible` is the single flag payroll would read, exposed as-is
 * rather than re-derived from `status`, because the server already decided it
 * and a client that reached a different conclusion would be wrong in a way
 * nobody could debug.
 */
class OvertimeRequestResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        /** @var OvertimeRequest $resource */
        $resource = $this->resource;

        return [
            'id' => $resource->id,

            'employee_id' => $resource->employee_id,
            'employee' => $this->whenLoaded('employee', fn () => new EmployeeBriefResource($resource->employee)),

            'overtime_date' => $resource->overtime_date?->toDateString(),

            'project_id' => $resource->project_id,
            'project' => $this->whenLoaded('project', fn () => $resource->project
                ? ['id' => $resource->project->id, 'name' => $resource->project->name, 'code' => $resource->project->code]
                : null),

            'site_id' => $resource->site_id,
            'site' => $this->whenLoaded('site', fn () => $resource->site
                ? ['id' => $resource->site->id, 'name' => $resource->site->name, 'code' => $resource->site->code]
                : null),

            'attendance_id' => $resource->attendance_id,

            'requested_minutes' => $resource->requested_minutes,
            'requested_hours' => $resource->requestedHours(),
            'approved_minutes' => $resource->approved_minutes,
            'approved_hours' => $resource->approvedHours(),

            'reason' => $resource->reason,
            'remarks' => $resource->remarks,

            'status' => $resource->status,
            'payroll_eligible' => $resource->payroll_eligible,
            'is_payroll_eligible' => $resource->isPayrollEligible(),

            'submitted_at' => $resource->submitted_at?->toIso8601String(),
            'cancelled_at' => $resource->cancelled_at?->toIso8601String(),
            'rejected_at' => $resource->rejected_at?->toIso8601String(),
            'approved_at' => $resource->approved_at?->toIso8601String(),
            'approved_by' => $resource->approved_by,
            'rejected_by' => $resource->rejected_by,

            'current_approval_step' => $resource->current_approval_step,
            'approval_workflow_id' => $resource->approval_workflow_id,

            'approval_chain' => $this->whenLoaded(
                'approvalRecords',
                fn () => ApprovalRecordResource::collection($resource->approvalRecords),
            ),

            'created_at' => $resource->created_at?->toIso8601String(),
            'updated_at' => $resource->updated_at?->toIso8601String(),
        ];
    }
}
