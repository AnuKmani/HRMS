<?php

namespace App\Http\Resources;

use App\Models\LeaveBalance;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * One balance, with `remaining` computed rather than read.
 *
 * The formula is repeated here only as a comment — `remaining()` on the model
 * is the single implementation, and the migration's argument for not storing
 * the column applies equally to not recomputing it in a second place.
 *
 *     remaining = entitlement + carry_forward + adjustment - used - pending
 *
 * `used` and `pending` are both surfaced because they answer different
 * questions a client genuinely asks: "have I taken 6 days?" and "am I still
 * waiting on 3?". Collapsing them into one number would make an approved
 * leave and a requested leave look identical, which is the exact confusion
 * the two columns exist to prevent.
 */
class LeaveBalanceResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        /** @var LeaveBalance $resource */
        $resource = $this->resource;

        return [
            'id' => $resource->id,
            'employee_id' => $resource->employee_id,
            'leave_type_id' => $resource->leave_type_id,
            'year' => $resource->year,

            'entitlement' => $resource->entitlement,
            'carry_forward' => $resource->carry_forward,
            'adjustment' => $resource->adjustment,
            'used' => (float) $resource->used,
            'pending' => (float) $resource->pending,
            'remaining' => $resource->remaining(),

            // Kept so a client can flag a pot that has been driven below zero
            // by an adjustment without repeating the formula in Dart.
            'is_negative' => $resource->remaining() < 0,

            'leave_type' => $this->whenLoaded('leaveType', fn () => new LeaveTypeResource($resource->leaveType)),
            'employee' => $this->whenLoaded('employee', fn () => new EmployeeBriefResource($resource->employee)),

            'created_at' => $resource->created_at?->toIso8601String(),
            'updated_at' => $resource->updated_at?->toIso8601String(),
        ];
    }
}
