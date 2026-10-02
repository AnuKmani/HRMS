<?php

namespace App\Http\Resources;

use App\Models\Asset;
use App\Models\AssetAssignment;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * One piece of company property, in the shape the app renders.
 *
 * **`purchase_cost` is the one field that is not the same for every reader.**
 * It is money in DECIMAL(12,2) and it is withheld — not nulled with a
 * `null` that reads like "no cost recorded", but *omitted*, so a payload
 * without it is visibly different from one whose asset was free. The gate is
 * `assets.manage`, the same permission that opens the rest of the register,
 * and it is asked here rather than in the controller because a resource is
 * the last place a number can leave.
 *
 * `status` and `current_condition` ship as two fields and are never allowed
 * to stand in for each other: the first says where the asset sits in its
 * lifecycle (and therefore what the next legal action is), the second says
 * what condition it is physically in. An asset can be `assigned` and `poor`
 * at the same time, and it usually is.
 *
 * `is_assignable` is a fact about the record rather than about the reader —
 * an asset in maintenance is not available to *anyone* — for the reason
 * SiteActivityReportResource ships `is_editable` and pointedly no
 * `can_edit`: whether *you* may act is AssetPolicy's answer, asked when you
 * try.
 *
 * `asset_code` ships plainly because it is meant to be read: a barcode or
 * QR label will carry exactly this string, and a payload that hid it would
 * make the future label a thing the app could not print.
 */
class AssetResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $active = null;
        $loaded = $this->relationLoaded('assignments');

        if ($loaded) {
            $active = $this->assignments->first(
                fn (AssetAssignment $row) => $row->status === AssetAssignment::STATUS_ACTIVE,
            );
        }

        return [
            'id' => $this->id,
            'asset_code' => $this->asset_code,
            'asset_type_id' => $this->asset_type_id,
            'asset_type' => $this->whenLoaded('assetType', fn () => new AssetTypeResource($this->assetType)),

            'name' => $this->name,
            'description' => $this->description,
            'serial_number' => $this->serial_number,
            'manufacturer' => $this->manufacturer,
            'model' => $this->model,

            'purchase_date' => $this->purchase_date?->toDateString(),
            // Omitted, not nulled — see the class note.
            ...($request->user()?->can('assets.manage')
                ? ['purchase_cost' => $this->purchase_cost]
                : []),

            'current_condition' => $this->current_condition,
            'status' => $this->status,
            'notes' => $this->notes,

            // Whether the asset is out right now. `null` when the history
            // was not fetched is deliberately *not* the same as "nobody has
            // it": a payload that has the history says one or the other, a
            // payload that does not says nothing, and a client must never
            // read "we did not ask" as "it is free".
            'current_assignment' => $loaded && $active !== null
                ? new AssetAssignmentResource($active)
                : null,
            'current_holder' => $loaded && $active?->relationLoaded('employee') && $active->employee !== null
                ? new EmployeeBriefResource($active->employee)
                : null,

            // `null` rather than `[]` when the history was not fetched —
            // no history and no history *loaded* are different facts.
            'assignments' => $loaded
                ? AssetAssignmentResource::collection($this->assignments)
                : null,

            'is_assignable' => $this->isAssignable(),
            'is_retired' => $this->status === Asset::STATUS_RETIRED,

            'created_at' => $this->created_at,
            'updated_at' => $this->updated_at,
        ];
    }
}
