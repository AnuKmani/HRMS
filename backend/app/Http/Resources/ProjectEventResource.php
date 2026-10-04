<?php

namespace App\Http\Resources;

use App\Models\ProjectEvent;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class ProjectEventResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        /** @var ProjectEvent $event */
        $event = $this->resource;

        return [
            'id' => $event->id,
            'project_id' => $event->project_id,
            'type' => $event->type,
            'label' => $event->label(),
            'title' => $event->title,
            'event_date' => $event->event_date?->toDateString(),
            'notes' => $event->notes,
        ];
    }
}
