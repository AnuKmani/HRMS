<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * The authenticated user as the app needs to know them.
 *
 * This is the payload behind GET /auth/me and the `user` object of a login
 * response. It answers three questions and no others:
 *
 *   1. who am I?                     id / name / email
 *   2. what am I allowed to do?      roles / permissions
 *   3. which HR record backs this?   employee (optional)
 *
 * Deliberately absent: password, remember_token, salary, personal contact
 * details and anything else in the employee master. Sensitive HR data is
 * reached through its own module and its own permission gate, so leaking it
 * here would bypass the RBAC checks Phase 2 put in place.
 *
 * Note there is no `token` field — the bearer token is returned once by the
 * login endpoint and never again, because it cannot be recovered from the
 * database (Sanctum stores only a hash).
 */
class UserResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        // Not `whenLoaded(...)`: that returns MissingValue, and wrapping a
        // MissingValue inside a nested resource means resolve() runs against
        // an object with no id. The explicit guard is unambiguous and still
        // avoids the lazy-load query when the relation was never requested.
        $employee = $this->resource->relationLoaded('employee') && $this->resource->employee !== null
            ? EmployeeBriefResource::make($this->resource->employee)
            : null;

        return [
            'id' => $this->id,
            'name' => $this->name,
            'email' => $this->email,
            'status' => $this->status,
            'roles' => $this->getRoleNames()->sort()->values()->all(),
            'permissions' => $this->getAllPermissions()
                ->pluck('name')
                ->unique()
                ->sort()
                ->values()
                ->all(),
            'employee' => $employee,
        ];
    }
}
