<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Spatie\Permission\Models\Role;

/**
 * A role and the permissions it grants.
 *
 * Read-only for now: Phase 3 only proves that permission-gated routes work.
 * Editing the map arrives with the RBAC management module.
 */
class RoleResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        /** @var Role $resource */
        $resource = $this->resource;

        return [
            'name' => $resource->name,
            'guard_name' => $resource->guard_name,
            'permissions' => $resource->permissions
                ->pluck('name')
                ->sort()
                ->values()
                ->all(),
        ];
    }
}
