<?php

namespace App\Http\Resources;

use App\Models\DailySiteReportMaterial;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * One material line item on a daily site report: a name, a quantity, a unit
 * and a note.
 *
 * Nothing else, and in particular nothing that would make it look like
 * inventory — no stock level, no cost, no supplier. This is a record of what
 * a site consumed on a day, and a reader who mistakes it for a store ledger
 * has been misinformed by the shape of the response.
 *
 * `quantity` is emitted from the cast (a fixed three decimal places) rather
 * than raw, so `2` and `2.000` never both appear for the same report in the
 * same list.
 */
class DailySiteReportMaterialResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        /** @var DailySiteReportMaterial $resource */
        $resource = $this->resource;

        return [
            'id' => $resource->id,
            'material_name' => $resource->material_name,
            'quantity' => $resource->quantity,
            'unit' => $resource->unit,
            'remarks' => $resource->remarks,
            'sort_order' => $resource->sort_order,
        ];
    }
}
