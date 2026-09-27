<?php

namespace App\Http\Resources;

use App\Models\LeaveType;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * A configurable leave type in full — every number the calculator will read.
 *
 * The resource deliberately exposes the whole policy surface rather than a
 * list-friendly subset: a Flutter form that is asking "how many days can I
 * take?" and a payroll report asking "is this paid?" are reading the same row,
 * and splitting it into two projections would guarantee they eventually
 * disagree about one of the numbers.
 *
 * `approval_workflow_id` is emitted so a screen can say "this goes to your
 * supervisor first" without a second request. It is null when the type uses
 * the default chain, which is the common case.
 */
class LeaveTypeResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        /** @var LeaveType $resource */
        $resource = $this->resource;

        return [
            'id' => $resource->id,
            'name' => $resource->name,
            'code' => $resource->code,
            'description' => $resource->description,

            'entitlement_days' => $resource->entitlement_days,
            'carry_forward_enabled' => $resource->carry_forward_enabled,
            'carry_forward_limit' => $resource->carry_forward_limit,
            'maximum_days_per_request' => $resource->maximum_days_per_request,

            'is_paid' => $resource->is_paid,
            'requires_document' => $resource->requires_document,
            'document_deadline_days' => $resource->document_deadline_days,
            'allow_negative_balance' => $resource->allow_negative_balance,

            'status' => $resource->status,
            'approval_workflow_id' => $resource->approval_workflow_id,

            'created_at' => $resource->created_at?->toIso8601String(),
            'updated_at' => $resource->updated_at?->toIso8601String(),
        ];
    }
}
