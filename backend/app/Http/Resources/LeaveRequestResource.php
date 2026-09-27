<?php

namespace App\Http\Resources;

use App\Models\LeaveRequest;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * One leave request, in the shape the app renders.
 *
 * Two things this resource is careful about:
 *
 *  - **`certificate_path` is never emitted.** The storage path of a medical
 *    document is exactly the sort of detail that should not leave the server —
 *    it says where private files live and hands a reader something to guess
 *    at. The certificate is reached only through
 *    `GET /leave/{id}/certificate`, behind the policy that already governs
 *    the request, and all this says about it is that it exists, what it is
 *    called and when it was filed.
 *
 *  - **derived flags are emitted as flags, not as client logic.** Whether a
 *    certificate is required is a property of the leave *type*, whether the
 *    deadline has passed is a property of the *record*, and neither is
 *    something a screen should re-derive from dates and config — the server
 *    already has both answers and only one of them is right.
 *
 * The approval chain is opt-in (`approval_chain`) so a list of fifty rows
 * does not silently issue fifty sequences of queries.
 */
class LeaveRequestResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        /** @var LeaveRequest $resource */
        $resource = $this->resource;

        return [
            'id' => $resource->id,

            'employee_id' => $resource->employee_id,
            'employee' => $this->whenLoaded('employee', fn () => new EmployeeBriefResource($resource->employee)),

            'leave_type_id' => $resource->leave_type_id,
            'leave_type' => $this->whenLoaded('leaveType', fn () => new LeaveTypeResource($resource->leaveType)),

            'site_id' => $resource->site_id,
            'site' => $this->whenLoaded('site', fn () => $resource->site
                ? ['id' => $resource->site->id, 'name' => $resource->site->name, 'code' => $resource->site->code]
                : null),

            'start_date' => $resource->start_date?->toDateString(),
            'end_date' => $resource->end_date?->toDateString(),
            'summary' => $resource->summary(),
            'requested_days' => (float) $resource->requested_days,
            'reason' => $resource->reason,

            'status' => $resource->status,
            'submitted_at' => $resource->submitted_at?->toIso8601String(),
            'approved_at' => $resource->approved_at?->toIso8601String(),
            'rejected_at' => $resource->rejected_at?->toIso8601String(),
            'cancelled_at' => $resource->cancelled_at?->toIso8601String(),
            'approved_by' => $resource->approved_by,
            'rejected_by' => $resource->rejected_by,
            'remarks' => $resource->remarks,

            'current_approval_step' => $resource->current_approval_step,
            'approval_workflow_id' => $resource->approval_workflow_id,

            // --- medical certificate -------------------------------------
            // `certificate_required` is read off the type; `certificate_due_at`
            // and `certificate_overdue` off the record. Both are here so a
            // screen can render "due in 2 days" without knowing which of the
            // two it is looking at.
            'certificate' => (object) [
                'required' => $resource->requiresDocument(),
                'has_file' => $resource->hasCertificate(),
                // Metadata, never the path.
                'original_name' => $resource->certificate_original_name,
                'mime' => $resource->certificate_mime,
                'size' => $resource->certificate_size,
                'uploaded_at' => $resource->certificate_uploaded_at?->toIso8601String(),
                'due_at' => $resource->certificate_due_at?->toDateString(),
                'overdue' => $resource->hasCertificate() === false && $resource->certificateIsOverdue(),
            ],

            // --- loss of pay ---------------------------------------------
            // Null on a request that was never converted, so a client can
            // branch on presence rather than testing a status string against
            // a constant it had to copy from the docs.
            'lop' => $resource->lop_applied_at === null ? null : (object) [
                'days' => (float) $resource->lop_days,
                'reason' => $resource->lop_reason,
                'applied_at' => $resource->lop_applied_at?->toIso8601String(),
            ],

            'approval_chain' => $this->whenLoaded(
                'approvalRecords',
                fn () => ApprovalRecordResource::collection($resource->approvalRecords),
            ),

            'created_at' => $resource->created_at?->toIso8601String(),
            'updated_at' => $resource->updated_at?->toIso8601String(),
        ];
    }
}
