<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Concerns\BuildsResourceLists;
use App\Http\Controllers\Controller;
use App\Http\Requests\StoreDepartmentRequest;
use App\Http\Requests\UpdateDepartmentRequest;
use App\Http\Resources\DepartmentResource;
use App\Http\Responses\ApiResponse;
use App\Http\Responses\PaginatedResponse;
use App\Models\Department;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * /api/v1/departments
 *
 * The simplest module in Phase 4, and deliberately so: master data with no
 * owner, no history and no row-level rules — see DepartmentPolicy for why
 * there are none. Filtering and sorting live here rather than in a service
 * because they are *read* shaping, not business logic; the only writes are
 * `fill` + `save` on a model whose rules are entirely in the form requests.
 */
class DepartmentController extends Controller
{
    use BuildsResourceLists;

    public function index(Request $request): JsonResponse
    {
        $this->authorize('viewAny', Department::class);

        $query = Department::query()
            ->withCount(['designations', 'employees']);

        if ($term = $this->searchTerm($request)) {
            $like = '%'.$this->escapeLike($term).'%';
            $query->where(function ($q) use ($like) {
                $q->where('name', 'like', $like)
                    ->orWhere('code', 'like', $like);
            });
        }

        if ($status = $this->param($request, 'status')) {
            $query->where('status', $status);
        }

        $query->orderBy(
            $this->sortColumn($request, ['name', 'code', 'status', 'created_at', 'id'], 'name'),
            $this->sortDirection($request),
        )->orderBy('id');

        $page = $query->paginate($this->perPage($request));

        return PaginatedResponse::make(
            'Departments retrieved.',
            DepartmentResource::collection($page->getCollection()),
            $page,
        );
    }

    public function show(Department $department): JsonResponse
    {
        $this->authorize('view', $department);

        $department->loadCount(['designations', 'employees']);

        return ApiResponse::success('Department retrieved.', new DepartmentResource($department));
    }

    public function store(StoreDepartmentRequest $request): JsonResponse
    {
        $department = Department::create($request->validated());

        return ApiResponse::created(
            'Department created.',
            new DepartmentResource($department->loadCount(['designations', 'employees'])),
        );
    }

    public function update(UpdateDepartmentRequest $request, Department $department): JsonResponse
    {
        $department->update($request->validated());

        return ApiResponse::success(
            'Department updated.',
            new DepartmentResource($department->refresh()->loadCount(['designations', 'employees'])),
        );
    }

    public function destroy(Department $department): JsonResponse
    {
        $this->authorize('delete', $department);

        // Soft delete: designations and employees point here with
        // nullOnDelete, so the row is archived and those references survive
        // rather than being quietly emptied.
        //
        // Only once nobody sits under it, though. A soft-deleted department
        // disappears from every belongsTo, so archiving one that still has
        // people would leave them visibly teamless with no record of which
        // team they were in.
        if ($department->employees()->exists()) {
            return ApiResponse::error(
                'This department still has employees. Reassign them first.',
                ['department_id' => 'Department still has employees.'],
                422,
            );
        }

        $department->delete();

        return ApiResponse::success('Department deleted.');
    }
}
