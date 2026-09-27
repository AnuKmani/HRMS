<?php

namespace App\Http\Resources;

use App\Models\Designation;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * A designation, and the department it hangs under.
 *
 * `department_id` is always present — it is the foreign key, and a client
 * filtering by department needs it even when the relation was not loaded.
 * `department` is only rendered when it was eager-loaded, so a list endpoint
 * that selected for a flat payload never issues a query per row.
 */
class DesignationResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        /** @var Designation $resource */
        $resource = $this->resource;

        return [
            'id' => $resource->id,
            'department_id' => $resource->department_id,
            'department' => $this->whenLoaded('department', fn () => $resource->department
                ? ['id' => $resource->department->id, 'name' => $resource->department->name]
                : null),

            'name' => $resource->name,
            'code' => $resource->code,
            'description' => $resource->description,
            'status' => $resource->status,

            'employees_count' => array_key_exists('employees_count', $resource->getAttributes())
                ? (int) $resource->getAttributes()['employees_count']
                : null,

            'created_at' => $resource->created_at?->toIso8601String(),
        ];
    }
}
