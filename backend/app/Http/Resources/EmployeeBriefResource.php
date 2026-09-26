<?php

namespace App\Http\Resources;

use App\Models\Employee;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * The employee relationship attached to a login.
 *
 * A deliberately narrow projection: enough for the app to greet the user by
 * their real name and show which team they sit in, and nothing more. Salary,
 * date of birth, address, phone and emergency contacts live in the employee
 * master record and are reached through the employee module (Phase 4) behind
 * `employees.view`, not through an authenticated session.
 */
class EmployeeBriefResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        /** @var Employee $resource */
        $resource = $this->resource;

        return [
            'id' => $resource->id,
            'employee_code' => $resource->employee_code,
            'full_name' => $resource->full_name,
            'photo_path' => $resource->photo_path,
            // relationLoaded() rather than whenLoaded(): the latter is a
            // JsonResource method and does not exist on an Eloquent model.
            // Null when the relation was never eager-loaded, so the resource
            // never triggers an N+1 query of its own.
            'department' => $resource->relationLoaded('department') ? $resource->department?->name : null,
            'designation' => $resource->relationLoaded('designation') ? $resource->designation?->name : null,
            'employment_type' => $resource->employment_type,
            'employment_status' => $resource->employment_status,
        ];
    }
}
