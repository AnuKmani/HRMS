<?php

namespace App\Http\Resources;

use App\Models\DailySiteReport;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * One daily site report — the document, plus the four child sets that make
 * it worth reading.
 *
 * The children are `whenLoaded(...)` so `GET /daily-site-reports` (a list of
 * summaries) does not pay for eight rows of manpower, materials, equipment
 * and photographs per report while somebody scrolls; `show` eager-loads them
 * and pays once.
 *
 * `total_manpower` is emitted as the stored figure rather than re-summed
 * here. DailySiteReportService derived it when the report was written, and
 * a resource that recomputed it would silently disagree with the PDF — which
 * reads the stored column — the first time a child row was edited by hand.
 *
 * `creator` is the author and the row-level policy's first question; it is
 * emitted as a UserResource so the "prepared by" line on a screen matches
 * the "prepared by" line in the generated document.
 */
class DailySiteReportResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        /** @var DailySiteReport $resource */
        $resource = $this->resource;

        return [
            'id' => $resource->id,

            'created_by' => $resource->created_by,
            'creator' => $this->whenLoaded('creator', fn () => new UserResource($resource->creator)),

            'project_id' => $resource->project_id,
            'project' => $this->whenLoaded('project', fn () => $resource->project
                ? ['id' => $resource->project->id, 'name' => $resource->project->name, 'code' => $resource->project->code]
                : null),

            'site_id' => $resource->site_id,
            'site' => $this->whenLoaded('site', fn () => $resource->site
                ? ['id' => $resource->site->id, 'name' => $resource->site->name, 'code' => $resource->site->code]
                : null),

            'report_date' => $resource->report_date?->toDateString(),

            'total_manpower' => $resource->total_manpower,

            'work_planned' => $resource->work_planned,
            'work_completed' => $resource->work_completed,

            'safety_observations' => $resource->safety_observations,
            'delays' => $resource->delays,
            'issues' => $resource->issues,
            'remarks' => $resource->remarks,

            'status' => $resource->status,
            'is_draft' => $resource->isDraft(),
            'is_editable' => $resource->isEditable(),
            'submitted_at' => $resource->submitted_at?->toIso8601String(),
            'approved_at' => $resource->approved_at?->toIso8601String(),

            'manpower' => $this->whenLoaded(
                'manpower',
                fn () => DailySiteReportManpowerResource::collection($resource->manpower),
            ),
            'materials' => $this->whenLoaded(
                'materials',
                fn () => DailySiteReportMaterialResource::collection($resource->materials),
            ),
            'equipment' => $this->whenLoaded(
                'equipment',
                fn () => DailySiteReportEquipmentResource::collection($resource->equipment),
            ),

            'photos' => $this->whenLoaded(
                'photos',
                fn () => DailySiteReportPhotoResource::collection($resource->photos),
            ),
            'photo_count' => $this->whenLoaded('photos', fn () => $resource->photos->count()),

            'created_at' => $resource->created_at?->toIso8601String(),
            'updated_at' => $resource->updated_at?->toIso8601String(),
        ];
    }
}
