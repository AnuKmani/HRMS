<?php

namespace App\Http\Resources;

use App\Models\Department;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * A department row plus its two head-counts, when the caller asked for them.
 *
 * The counts are opt-in: `withCount()` in the controller decides whether the
 * attributes exist at all, and this resource renders `null` rather than
 * firing a query to find out. A list endpoint that needed a count per row
 * and did not say so in its eager loading would be the textbook N+1, on the
 * table most likely to be paginated in the hundreds.
 */
class DepartmentResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        /** @var Department $resource */
        $resource = $this->resource;

        return [
            'id' => $resource->id,
            'name' => $resource->name,
            'code' => $resource->code,
            'description' => $resource->description,
            'status' => $resource->status,

            'designations_count' => $this->counted('designations_count'),
            'employees_count' => $this->counted('employees_count'),

            'created_at' => $resource->created_at?->toIso8601String(),
        ];
    }

    private function counted(string $attribute): ?int
    {
        $attributes = $this->resource->getAttributes();

        return array_key_exists($attribute, $attributes) ? (int) $attributes[$attribute] : null;
    }
}
