<?php

namespace App\Http\Resources;

use App\Models\ApprovalRecord;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * One materialised approval step, for the chain a client draws under a
 * request.
 *
 * `approver_role` / `approver_permission` are included because a step that
 * resolved to "your Project Manager" should say so, and `approver_employee_id`
 * is included because when the runtime *did* pin it to a specific person at
 * submit time, that person is the one the screen should name — the chain is
 * frozen, and re-resolving it now would silently show a different answer for
 * the same history.
 *
 * `acted_by` is a user id, deliberately: approvals are an act performed by a
 * session, and the employee behind that session may not exist.
 */
class ApprovalRecordResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        /** @var ApprovalRecord $resource */
        $resource = $this->resource;

        return [
            'id' => $resource->id,
            'subject_type' => $resource->subject_type,
            'subject_id' => $resource->subject_id,
            'approval_workflow_id' => $resource->approval_workflow_id,

            'sequence' => $resource->sequence,
            'name' => $resource->name,

            'approver_type' => $resource->approver_type,
            'approver_role' => $resource->approver_role,
            'approver_permission' => $resource->approver_permission,
            'approver_employee_id' => $resource->approver_employee_id,

            'status' => $resource->status,
            'is_current' => $resource->isCurrent(),
            'acted_by' => $resource->acted_by,
            'acted_at' => $resource->acted_at?->toIso8601String(),
            'remarks' => $resource->remarks,

            'actor' => $this->whenLoaded('actor', fn () => new UserResource($resource->actor)),
            'resolved_approver' => $this->whenLoaded(
                'resolvedApprover',
                fn () => new EmployeeBriefResource($resource->resolvedApprover),
            ),
        ];
    }
}
