<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Resources\RoleResource;
use App\Http\Responses\ApiResponse;
use Illuminate\Http\JsonResponse;
use Spatie\Permission\Models\Role;

/**
 * GET /api/v1/roles — the reference route for backend authorization.
 *
 * Small on purpose: Phase 3 has to prove that a permission from Phase 2 can
 * gate a real endpoint (the `permission:roles.view` middleware below), not
 * merely exist in the database. The RBAC management module that lets an admin
 * *edit* this map arrives later and will add the write endpoints.
 */
class RoleController extends Controller
{
    public function index(): JsonResponse
    {
        $roles = Role::query()
            ->with('permissions:id,name')
            ->orderBy('name')
            ->get();

        return ApiResponse::success('All roles.', RoleResource::collection($roles));
    }
}
