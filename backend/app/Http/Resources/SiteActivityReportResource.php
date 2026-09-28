<?php

namespace App\Http\Resources;

use App\Models\SiteActivityReport;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * One site activity report.
 *
 * Three things this payload is careful *not* to carry:
 *
 *  - a **storage path**. Photographs go out as
 *    {@see SiteActivityReportPhotoResource} — id, caption, order — and are
 *    fetched through the report's own policy-checked route.
 *  - a **`can_edit` flag**. Whether *this user* may edit is the policy's
 *    answer and is asked at `PUT`, not precomputed into a list. What the
 *    payload does carry is `is_editable`, which is a fact about the
 *    *record's state* ("is it still a draft?") rather than about the
 *    reader — the two are different questions and only one of them can be
 *    decided without knowing who is asking.
 *  - an **`employee_id` a client sent**. There is no such thing here: the
 *    column is written from the session by SiteActivityReportService, so
 *    the value in this resource is by construction the value the server
 *    decided.
 *
 * Coordinates are emitted as-is, never rounded up to a "close enough" pin:
 * a report carries the fix it was submitted with, and re-deriving one at
 * read time would be inventing evidence.
 */
class SiteActivityReportResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        /** @var SiteActivityReport $resource */
        $resource = $this->resource;

        return [
            'id' => $resource->id,

            'employee_id' => $resource->employee_id,
            'employee' => $this->whenLoaded('employee', fn () => new EmployeeBriefResource($resource->employee)),

            'project_id' => $resource->project_id,
            'project' => $this->whenLoaded('project', fn () => $resource->project
                ? ['id' => $resource->project->id, 'name' => $resource->project->name, 'code' => $resource->project->code]
                : null),

            'site_id' => $resource->site_id,
            'site' => $this->whenLoaded('site', fn () => $resource->site
                ? ['id' => $resource->site->id, 'name' => $resource->site->name, 'code' => $resource->site->code]
                : null),

            'report_date' => $resource->report_date?->toDateString(),

            'work_category' => $resource->work_category,
            'work_performed' => $resource->work_performed,
            'progress_percentage' => $resource->progress_percentage,

            'manpower' => $resource->manpower,
            'materials_used' => $resource->materials_used,
            'equipment_used' => $resource->equipment_used,

            'issues' => $resource->issues,
            'safety_issues' => $resource->safety_issues,
            'remarks' => $resource->remarks,

            'latitude' => $resource->latitude,
            'longitude' => $resource->longitude,
            'gps_accuracy' => $resource->gps_accuracy,
            'has_gps_fix' => $resource->hasGpsFix(),

            'status' => $resource->status,
            'is_draft' => $resource->isDraft(),
            'is_editable' => $resource->isEditable(),
            'submitted_at' => $resource->submitted_at?->toIso8601String(),

            'photos' => $this->whenLoaded(
                'photos',
                fn () => SiteActivityReportPhotoResource::collection($resource->photos),
            ),
            'photo_count' => $this->whenLoaded('photos', fn () => $resource->photos->count()),

            'created_at' => $resource->created_at?->toIso8601String(),
            'updated_at' => $resource->updated_at?->toIso8601String(),
        ];
    }
}
