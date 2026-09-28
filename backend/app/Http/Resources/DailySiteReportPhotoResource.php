<?php

namespace App\Http\Resources;

use App\Models\DailySiteReportPhoto;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * One photograph on a daily site report.
 *
 * Same contract as {@see SiteActivityReportPhotoResource}, and for the same
 * reason: the row knows where the file is and the client must never be told.
 * The two resources are separate classes rather than one shared with an
 * `if` because they sit on different reports with different policies, and a
 * photo resource that had to ask "which report am I on?" to decide anything
 * would already be in trouble.
 */
class DailySiteReportPhotoResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        /** @var DailySiteReportPhoto $resource */
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
