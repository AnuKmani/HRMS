<?php

namespace App\Http\Resources;

use App\Models\Holiday;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * One holiday.
 *
 * No storage path, no audit trail, no recurrence rule — a holiday is four
 * fields and a status, and the spec's instruction not to over-engineer
 * recurrence is honoured by simply having nowhere for it to go.
 */
class HolidayResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        /** @var Holiday $resource */
        $resource = $this->resource;

        return [
            'id' => $resource->id,
            'name' => $resource->name,
            'date' => $resource->date?->toDateString(),
            'type' => $resource->type,
            'site_id' => $resource->site_id,
            'site' => $this->whenLoaded('site', fn () => $resource->site
                ? ['id' => $resource->site->id, 'name' => $resource->site->name, 'code' => $resource->site->code]
                : null),
            'description' => $resource->description,
            'status' => $resource->status,
            'is_active' => $resource->isActive(),

            'created_at' => $resource->created_at?->toIso8601String(),
            'updated_at' => $resource->updated_at?->toIso8601String(),
        ];
    }
}
