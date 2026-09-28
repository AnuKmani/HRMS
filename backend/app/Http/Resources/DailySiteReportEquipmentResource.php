<?php

namespace App\Http\Resources;

use App\Models\DailySiteReportEquipment;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * One equipment line item on a daily site report.
 *
 * `operating_hours` is emitted as the meter reading recorded for that day —
 * nullable, because a machine that did not run has no reading — and is not
 * a figure the system accumulates between reports. `condition` is whatever
 * word the supervisor used; the shape carries it, it does not constrain it.
 */
class DailySiteReportEquipmentResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        /** @var DailySiteReportEquipment $resource */
        $resource = $this->resource;

        return [
            'id' => $resource->id,
            'equipment_name' => $resource->equipment_name,
            'quantity' => $resource->quantity,
            'operating_hours' => $resource->operating_hours,
            'condition' => $resource->condition,
            'remarks' => $resource->remarks,
            'sort_order' => $resource->sort_order,
        ];
    }
}
