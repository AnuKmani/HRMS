<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Concerns\BuildsResourceLists;
use App\Http\Controllers\Controller;
use App\Http\Resources\AssetAssignmentResource;
use App\Http\Responses\ApiResponse;
use App\Http\Responses\PaginatedResponse;
use App\Models\AssetAssignment;
use App\Support\Visibility;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * GET /api/v1/asset-assignments
 *
 * The hand-over log — every time anything was given to anybody and taken
 * back — and a **read-only controller**, because nothing in this module
 * creates an assignment directly. `assign()` and `returnAsset()` live on
 * AssetController and go through AssetService, which takes the lock that
 * makes "at most one active hand-over per asset" a guarantee rather than a
 * hope. A second writer here would be a second place to get that wrong.
 *
 * **`assets.history.view` is the cross-employee door, and it is the only
 * one on this endpoint.** A holder of `assets.view` alone reads their own
 * rows through Visibility — an employee asking "what have I been handed?"
 * is answered without it — while the log of *who else* has held anything
 * needs the permission, because a hand-over log is a control document
 * rather than a personal file. Two questions, two lists, and neither can be
 * derived from the other.
 *
 * Unpaginated by default would be wrong here (the log grows), so it
 * paginates exactly like every other list; what it does *not* do is filter
 * on the asset's current status, because a closed hand-back is the whole
 * reason somebody opens this screen.
 */
class AssetAssignmentController extends Controller
{
    use BuildsResourceLists;

    public function index(Request $request): JsonResponse
    {
        $this->authorize('viewAny', AssetAssignment::class);

        $query = AssetAssignment::query()
            ->with(['asset.assetType', 'employee'])
            ->select('asset_assignments.*');

        $query = Visibility::assetAssignmentsFor($query, $request->user());

        $this->applyFilters($query, $request);

        $query->orderBy(
            $this->sortColumn($request, [
                'assigned_date', 'returned_date', 'expected_return_date',
                'status', 'created_at',
            ], 'assigned_date'),
            $this->sortDirection($request, 'desc'),
        );

        $page = $query->paginate($this->perPage($request));

        return PaginatedResponse::make(
            'Asset assignments.',
            AssetAssignmentResource::collection($page),
            $page,
        );
    }

    /**
     * GET /api/v1/asset-assignments/{assignment}
     */
    public function show(Request $request, AssetAssignment $assignment): JsonResponse
    {
        $this->authorize('view', $assignment);

        $assignment->load(['asset.assetType', 'employee']);

        return ApiResponse::success('Asset assignment.', new AssetAssignmentResource($assignment));
    }

    /**
     * @param  Builder<AssetAssignment>  $query
     */
    private function applyFilters(Builder $query, Request $request): void
    {
        if ($id = $this->param($request, 'asset_id')) {
            $query->where('asset_assignments.asset_id', (int) $id);
        }

        if ($id = $this->param($request, 'employee_id')) {
            $query->where('asset_assignments.employee_id', (int) $id);
        }

        if ($status = $this->param($request, 'status')) {
            $wanted = array_values(array_intersect(
                array_filter(array_map('trim', explode(',', $status))),
                AssetAssignment::STATUSES,
            ));

            $query->whereIn('asset_assignments.status', $wanted ?: ['__none__']);
        }

        if ($this->flagged($request, 'overdue')) {
            // Late *and* still out: a hand-back that arrived after its
            // deadline is in the past tense, and a screen asking "what is
            // overdue" means "what do I have to chase".
            $query->where('asset_assignments.status', AssetAssignment::STATUS_ACTIVE)
                ->whereNotNull('asset_assignments.expected_return_date')
                ->where('asset_assignments.expected_return_date', '<', now()->toDateString());
        }

        if ($from = $this->param($request, 'assigned_from')) {
            $query->where('asset_assignments.assigned_date', '>=', $from);
        }

        if ($to = $this->param($request, 'assigned_to')) {
            $query->where('asset_assignments.assigned_date', '<=', $to);
        }
    }

    private function flagged(Request $request, string $key): bool
    {
        $value = $this->param($request, $key);

        return $value !== null && in_array(strtolower($value), ['1', 'true', 'yes'], true);
    }
}
