<?php

namespace App\Http\Resources;

use App\Models\SiteActivityReportPhoto;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * One photograph on a site activity report — the metadata, and nothing
 * that would tell you where the file is.
 *
 * `path` is deliberately absent rather than `$hidden`: a resource is the
 * only place this model is ever serialized, so not emitting it is a
 * property of the response itself instead of a property of one particular
 * shape somebody might later build from the model in a console command.
 *
 * The bytes come from `GET /api/v1/site-activity-reports/{report}/photos/{photo}`,
 * which asks the report's policy first and streams with `no-store`. The
 * client builds that path from the two ids it already has — the same way it
 * builds the attendance selfie URL — so no absolute URL, no signed token and
 * no storage key appears in the payload for a scraper to collect.
 */
class SiteActivityReportPhotoResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        /** @var SiteActivityReportPhoto $resource */
        $resource = $this->resource;

        return [
            'id' => $resource->id,
            'caption' => $resource->caption,
            'sort_order' => $resource->sort_order,
            'mime_type' => $resource->mime_type,
            'size_bytes' => $resource->size_bytes,
            'created_at' => $resource->created_at?->toIso8601String(),
        ];
    }
}
