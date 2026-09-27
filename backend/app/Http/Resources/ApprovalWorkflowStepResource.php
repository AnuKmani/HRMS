<?php

namespace App\Http\Resources;

use App\Models\ApprovalWorkflowStep;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * One configured step of a workflow definition — *not* a decision.
 *
 * The distinction matters to a reader: this row says who a request would go
 * to, while an ApprovalRecord says who it is waiting on and what they did.
 * Definition rows never carry a status of their own beyond active/inactive
 * (a step is either part of the chain or it is not), which is why this
 * resource has no `acted_at` — putting one here would invite a client to
 * render a decided step for a request nobody has ever submitted.
 */
class ApprovalWorkflowStepResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        /** @var ApprovalWorkflowStep $resource */
        $resource = $this->resource;

        return [
            'id' => $resource->id,
            'approval_workflow_id' => $resource->approval_workflow_id,
            'sequence' => $resource->sequence,
            'name' => $resource->name,

            'approver_type' => $resource->approver_type,
            'approver_role' => $resource->approver_role,
            'approver_permission' => $resource->approver_permission,

            // "Reporting manager", not "reporting_manager": this is read by
            // people configuring a chain, not by a parser.
            'approver_label' => match ($resource->approver_type) {
                ApprovalWorkflowStep::TYPE_ROLE => (string) $resource->approver_role,
                ApprovalWorkflowStep::TYPE_PERMISSION => (string) $resource->approver_permission,
                default => 'Reporting manager',
            },

            'created_at' => $resource->created_at?->toIso8601String(),
        ];
    }
}
