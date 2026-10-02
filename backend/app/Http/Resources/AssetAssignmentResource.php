<?php

namespace App\Http\Resources;

use App\Models\AssetAssignment;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * One hand-over of one asset to one person — and, if there was one, the
 * hand-back.
 *
 * **The whole row ships, including the closed ones.** This payload is the
 * answer to "who had this laptop in March, and what condition did it leave
 * in?", and a resource that showed only the active hand-over would be a
 * history that could not be read. `returned_date`, `returned_condition` and
 * `returned_by` are therefore all first-class fields rather than something
 * a second endpoint has to be asked for — and they stay `null` while the
 * asset is still out, because an asset that has not come back has no return
 * condition and filling it with a guess would be worse than an honest null.
 *
 * `status` is `active` or `returned` and means exactly one thing: is this
 * hand-over still open. What condition it came back in is
 * `returned_condition`, deliberately *not* folded into a third or fourth
 * status — one question, one column, and a status that tried to answer two
 * would eventually answer neither.
 *
 * `days_out` is null while the asset is out (the count has no end yet) and
 * `is_overdue` is false rather than null when no return date was ever
 * expected: an open-ended loan is not late, it simply has no deadline, and
 * a `null` there would be read as "maybe".
 *
 * `assigner` / `returner` ship as ids with optional briefs because the
 * *names* matter to whoever reads the log — "who handed it to me" is a real
 * question — while the full user record does not.
 */
class AssetAssignmentResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'asset_id' => $this->asset_id,
            'asset' => $this->whenLoaded('asset', fn () => new AssetResource($this->asset)),
            'employee_id' => $this->employee_id,
            'employee' => $this->whenLoaded('employee', fn () => new EmployeeBriefResource($this->employee)),

            'assigned_date' => $this->assigned_date?->toDateString(),
            'expected_return_date' => $this->expected_return_date?->toDateString(),
            'returned_date' => $this->returned_date?->toDateString(),

            'assigned_condition' => $this->assigned_condition,
            'returned_condition' => $this->returned_condition,

            'assigned_by' => $this->assigned_by,
            'returned_by' => $this->returned_by,

            'status' => $this->status,
            'remarks' => $this->remarks,

            'days_out' => $this->daysOut(),
            'is_overdue' => $this->isOverdue(),
            'is_active' => $this->status === AssetAssignment::STATUS_ACTIVE,

            'created_at' => $this->created_at,
            'updated_at' => $this->updated_at,
        ];
    }
}
