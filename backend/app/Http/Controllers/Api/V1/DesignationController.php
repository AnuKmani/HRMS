<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Concerns\BuildsResourceLists;
use App\Http\Controllers\Controller;
use App\Http\Requests\StoreDesignationRequest;
use App\Http\Requests\UpdateDesignationRequest;
use App\Http\Resources\DesignationResource;
use App\Http\Responses\ApiResponse;
use App\Http\Responses\PaginatedResponse;
use App\Models\Designation;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * /api/v1/designations
 *
 * Same shape as departments, plus a department filter — the one relationship
 * this module has. `department` and `department_id` are both accepted as the
 * filter name because clients reach for either.
 */
class DesignationController extends Controller
{
    use BuildsResourceLists;

    public function index(Request $request): JsonResponse
    {
        $this->authorize('viewAny', Designation::class);

        $query = Designation::query()->with('department');

        if ($term = $this->searchTerm($request)) {
            $like = '%'.$this->escapeLike($term).'%';
            $query->where(function ($q) use ($like) {
                $q->where('name', 'like', $like)
                    ->orWhere('code', 'like', $like);
            });
        }

        if ($departmentId = $this->param($request, 'department_id', 'department')) {
            // `department=0` (or any non-numeric) matches nothing rather than
            // throwing: a bad id in a query string is a stale link, not a
            // malformed request worth a 422.
            $query->where('department_id', (int) $departmentId);
        }

        if ($status = $this->param($request, 'status')) {
            $query->where('status', $status);
        }

        $query->orderBy(
            $this->sortColumn($request, ['name', 'code', 'status', 'department_id', 'created_at', 'id'], 'name'),
            $this->sortDirection($request),
        )->orderBy('id');

        $page = $query->paginate($this->perPage($request));

        return PaginatedResponse::make(
            'Designations retrieved.',
            DesignationResource::collection($page->getCollection()),
            $page,
        );
    }

    public function show(Designation $designation): JsonResponse
    {
        $this->authorize('view', $designation);

        $designation->load('department');

        return ApiResponse::success('Designation retrieved.', new DesignationResource($designation));
    }

    public function store(StoreDesignationRequest $request): JsonResponse
    {
        $designation = Designation::create($request->validated());

        return ApiResponse::created(
            'Designation created.',
            new DesignationResource($designation->load('department')),
        );
    }

    public function update(UpdateDesignationRequest $request, Designation $designation): JsonResponse
    {
        $designation->update($request->validated());

        return ApiResponse::success(
            'Designation updated.',
            new DesignationResource($designation->refresh()->load('department')),
        );
    }

    public function destroy(Designation $designation): JsonResponse
    {
        $this->authorize('delete', $designation);

        // Soft delete. Employees keep pointing at this row, so a re-org that
        // archives a job title does not orphan the people holding it — but
        // only once nobody holds it, for the same reason as departments:
        // a soft-deleted row stops resolving from belongsTo.
        if ($designation->employees()->exists()) {
            return ApiResponse::error(
                'This designation still has employees. Reassign them first.',
                ['designation_id' => 'Designation still has employees.'],
                422,
            );
        }

        $designation->delete();

        return ApiResponse::success('Designation deleted.');
    }
}
