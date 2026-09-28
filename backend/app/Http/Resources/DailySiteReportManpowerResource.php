<?php

namespace App\Http\Resources;

use App\Models\DailySiteReportManpower;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * One workforce category on a daily site report.
 *
 * `category` goes out exactly as it came in. It is free text by design (see
 * the daily_site_report_manpower migration) — the field's job is to carry
 * whatever word this site's supervisor uses for the people they counted, and
 * a resource that normalised it to a canonical list would be quietly
 * discarding the difference between "electricians" and "electrical
 * subcontractors" that somebody deliberately typed.
 */
class DailySiteReportManpowerResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        /** @var DailySiteReportManpower $resource */
        $resource = $this->resource;

        return [
            'id' => $resource->id,
            'category' => $resource->category,
            'count' => $resource->count,
            'sort_order' => $resource->sort_order,
        ];
    }
}
