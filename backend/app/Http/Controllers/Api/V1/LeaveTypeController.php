<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Concerns\BuildsResourceLists;
use App\Http\Controllers\Controller;
use App\Http\Requests\StoreLeaveTypeRequest;
use App\Http\Requests\UpdateLeaveTypeRequest;
use App\Http\Resources\LeaveTypeResource;
use App\Http\Responses\ApiResponse;
use App\Http\Responses\PaginatedResponse;
use App\Models\LeaveType;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * /api/v1/leave-types
 *
 * Policy configuration, so master data with an owner after all: reading needs
 * `leave.view`, writing needs `leave.manage`, and there is no row-level rule
 * to speak of — a leave type belongs to the organisation rather than to a
 * person. See LeaveTypePolicy.
 *
 * Deletion is a `delete()` because LeaveType uses soft deletes: a type with
 * requests behind it disappears from the pickers while the history that
 * referenced it stays intact, which is exactly what an archived policy should
 * do.
 */
class LeaveTypeController extends Controller
{
    use BuildsResourceLists;

    public function index(Request $request): JsonResponse
    {
        $this->authorize('viewAny', LeaveType::class);

        $query = LeaveType::query();

        // Archived types are hidden by default rather than deleted: an HR
        // admin who needs to see what was retired sends `?status=inactive`,
        // and everybody else's picker is not cluttered with something they
        // cannot choose.
        if (($status = $this->param($request, 'status')) !== null) {
            $query->where('status', $status);
        } else {
            $query->where('status', LeaveType::STATUS_ACTIVE);
        }

        if ($term = $this->searchTerm($request)) {
            $like = '%'.$this->escapeLike($term).'%';
            $query->where(function ($q) use ($like) {
                $q->where('name', 'like', $like)
                    ->orWhere('code', 'like', $like);
            });
        }

        $page = $query->orderBy(
            $this->sortColumn($request, ['name', 'code', 'entitlement_days', 'created_at'], 'name'),
            $this->sortDirection($request),
        )->orderBy('id')->paginate($this->perPage($request));

        return PaginatedResponse::make(
            'Leave types retrieved.',
            LeaveTypeResource::collection($page->getCollection()),
            $page,
        );
    }

    public function show(Request $request, LeaveType $leaveType): JsonResponse
    {
        $this->authorize('view', $leaveType);

        return ApiResponse::success('Leave type retrieved.', new LeaveTypeResource($leaveType));
    }

    public function store(StoreLeaveTypeRequest $request): JsonResponse
    {
        $type = LeaveType::create($request->validated());

        return ApiResponse::created('Leave type created.', new LeaveTypeResource($type));
    }

    public function update(UpdateLeaveTypeRequest $request, LeaveType $leaveType): JsonResponse
    {
        $leaveType->update($request->validated());

        return ApiResponse::success('Leave type updated.', new LeaveTypeResource($leaveType->refresh()));
    }

    public function destroy(LeaveType $leaveType): JsonResponse
    {
        $this->authorize('delete', $leaveType);

        // Soft delete. Requests pointing here keep their `leave_type_id`, so
        // last year's sick leave still says it was sick leave — a hard delete
        // would either fail on the foreign key or (with cascade) erase the
        // classification of every request that ever used this policy.
        $leaveType->delete();

        return ApiResponse::success('Leave type archived.');
    }
}
