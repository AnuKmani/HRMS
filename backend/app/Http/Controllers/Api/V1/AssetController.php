<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Concerns\BuildsResourceLists;
use App\Http\Controllers\Controller;
use App\Http\Requests\AssignAssetRequest;
use App\Http\Requests\ChangeAssetStatusRequest;
use App\Http\Requests\ReturnAssetRequest;
use App\Http\Requests\StoreAssetRequest;
use App\Http\Requests\UpdateAssetRequest;
use App\Http\Resources\AssetResource;
use App\Http\Responses\ApiResponse;
use App\Http\Responses\PaginatedResponse;
use App\Models\Asset;
use App\Services\Assets\AssetService;
use App\Support\Visibility;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * /api/v1/assets
 *
 * The company's property register, and the only controller that writes one
 * — and it writes none of it. Create, edit, assign, return and every status
 * change go through AssetService, so "which sequence turns an available
 * laptop into a retired one, and what has to be true at each step" has
 * exactly one answer. This class decides *who may ask*; the service decides
 * *whether the ask is possible*, under a lock, and answers 409 naming the
 * state rather than 403 — "already out with somebody" and "you are not
 * allowed" are different sentences and only one says what to do next.
 *
 * The row scope is Visibility's, asked here for the list and in AssetPolicy
 * for the single record. Without `assets.manage` a caller sees only the
 * assets they have actually been handed, current *and* past — because a
 * laptop somebody had last year is still theirs to recognise in their own
 * history, and hiding it would make "your assignment history" a phrase with
 * a hole in it.
 *
 * `purchase_cost` never reaches a payload the reader is not entitled to,
 * and that decision lives in AssetResource rather than here: a resource is
 * the last place a number can leave, and a controller that forgot to strip
 * it would ship it anyway.
 *
 * There is no `destroy`. An asset is *retired* — written off, kept, still
 * answerable — because "what did we own in 2026?" has to be answerable in
 * 2030, and a hard delete is how that stops being true.
 */
class AssetController extends Controller
{
    use BuildsResourceLists;

    public function __construct(
        private readonly AssetService $service,
    ) {}

    /**
     * GET /api/v1/assets
     *
     * Seven filters: which kind, which state, what condition, who has it,
     * is it out, is it late, and what is it called.
     */
    public function index(Request $request): JsonResponse
    {
        $this->authorize('viewAny', Asset::class);

        $query = Asset::query()
            ->with(['assetType'])
            ->with(['assignments.employee'])
            ->select('assets.*');

        $query->join('asset_types', 'asset_types.id', '=', 'assets.asset_type_id');

        $query = Visibility::assetsFor($query, $request->user());

        $this->applyFilters($query, $request);

        $query->orderBy(
            $this->sortColumn($request, [
                'asset_code', 'name', 'status', 'current_condition',
                'purchase_date', 'created_at', 'updated_at',
            ], 'asset_code'),
            $this->sortDirection($request, 'asc'),
        );

        $page = $query->paginate($this->perPage($request));

        return PaginatedResponse::make(
            'Assets.',
            AssetResource::collection($page),
            $page,
        );
    }

    /**
     * POST /api/v1/assets
     */
    public function store(StoreAssetRequest $request): JsonResponse
    {
        $asset = $this->service->create($request->user(), $request->validated());
        $asset->load(['assetType', 'assignments.employee']);

        return ApiResponse::created('Asset created.', new AssetResource($asset));
    }

    /**
     * GET /api/v1/assets/{asset}
     */
    public function show(Request $request, Asset $asset): JsonResponse
    {
        $this->authorize('view', $asset);

        $asset->load(['assetType', 'assignments.employee']);

        return ApiResponse::success('Asset.', new AssetResource($asset));
    }

    /**
     * PUT /api/v1/assets/{asset}
     *
     * The master record only. Condition and status are `prohibited` in the
     * request rather than silently dropped, because an update that accepted
     * them would be a back door past assign, return and changeStatus — the
     * three acts that record who, when and why.
     */
    public function update(UpdateAssetRequest $request, Asset $asset): JsonResponse
    {
        $asset = $this->service->update($request->user(), $asset, $request->validated());
        $asset->load(['assetType', 'assignments.employee']);

        return ApiResponse::success('Asset updated.', new AssetResource($asset));
    }

    /**
     * POST /api/v1/assets/{asset}/assign
     *
     * The FormRequest settled *who* and *when*; whether it is possible —
     * is the asset available, does a second hand-over collide with one
     * already in flight — is AssetService's answer from inside a
     * `lockForUpdate()` transaction, because the second question is about a
     * row nobody can see until the lock is taken.
     */
    public function assign(AssignAssetRequest $request, Asset $asset): JsonResponse
    {
        $this->service->assign($request->user(), $asset, $request->assignmentPayload());

        $asset->refresh()->load(['assetType', 'assignments.employee']);

        return ApiResponse::success('Asset assigned.', new AssetResource($asset));
    }

    /**
     * POST /api/v1/assets/{asset}/return
     *
     * Closes the hand-over rather than removing it: the row stays, with who
     * took it, who took it back, both conditions and both dates — because
     * "who had this laptop in March?" has to still be answerable in
     * November.
     */
    public function returnAsset(ReturnAssetRequest $request, Asset $asset): JsonResponse
    {
        $this->service->returnAsset($request->user(), $asset, $request->returnPayload());

        $asset->refresh()->load(['assetType', 'assignments.employee']);

        return ApiResponse::success('Asset returned.', new AssetResource($asset));
    }

    /**
     * PATCH /api/v1/assets/{asset}/status
     *
     * Maintenance, damaged, lost, retired — the moves that are *not* a
     * hand-over. `assigned` and `available` are refused with sentences that
     * name the right endpoint, because both would otherwise leave an asset
     * whose status and open hand-over disagree.
     */
    public function changeStatus(ChangeAssetStatusRequest $request, Asset $asset): JsonResponse
    {
        $asset = $this->service->changeStatus(
            $request->user(),
            $asset,
            (string) $request->validated('status'),
            $request->statusPayload(),
        );
        $asset->load(['assetType', 'assignments.employee']);

        return ApiResponse::success('Asset status updated.', new AssetResource($asset));
    }

    /**
     * @param  Builder<Asset>  $query
     */
    private function applyFilters(Builder $query, Request $request): void
    {
        if ($id = $this->param($request, 'asset_type_id')) {
            $query->where('assets.asset_type_id', (int) $id);
        }

        if ($status = $this->param($request, 'status')) {
            $wanted = array_values(array_intersect(
                array_filter(array_map('trim', explode(',', $status))),
                Asset::STATUSES,
            ));

            $query->whereIn('assets.status', $wanted ?: ['__none__']);
        }

        if ($condition = $this->param($request, 'condition')) {
            $wanted = array_values(array_intersect(
                array_filter(array_map('trim', explode(',', $condition))),
                Asset::CONDITIONS,
            ));

            $query->whereIn('assets.current_condition', $wanted ?: ['__none__']);
        }

        // The three "who has it" filters. All of them are *subqueries*
        // rather than joins, because joining `asset_assignments` here would
        // multiply every asset by its history and silently change the
        // page's totals — the one bug that makes a pager lie.
        if ($id = $this->param($request, 'employee_id')) {
            $query->whereIn('assets.id', function ($sub) use ($id) {
                $sub->select('asset_id')
                    ->from('asset_assignments')
                    ->where('employee_id', (int) $id)
                    ->where('status', 'active');
            });
        }

        if ($this->flagged($request, 'assigned')) {
            $query->whereIn('assets.status', [Asset::STATUS_ASSIGNED]);
        }

        if ($this->flagged($request, 'available')) {
            $query->whereIn('assets.status', [Asset::STATUS_AVAILABLE]);
        }

        if ($this->flagged($request, 'overdue')) {
            $query->whereHas('assignments', function ($sub) {
                $sub->where('status', 'active')
                    ->whereNotNull('expected_return_date')
                    ->where('expected_return_date', '<', now()->toDateString());
            });
        }

        if ($from = $this->param($request, 'purchased_from')) {
            $query->where('assets.purchase_date', '>=', $from);
        }

        if ($to = $this->param($request, 'purchased_to')) {
            $query->where('assets.purchase_date', '<=', $to);
        }

        if ($term = $this->searchTerm($request)) {
            $like = '%'.$this->escapeLike($term).'%';

            $query->where(function (Builder $inner) use ($like) {
                $inner->where('assets.asset_code', 'like', $like)
                    ->orWhere('assets.name', 'like', $like)
                    ->orWhere('assets.serial_number', 'like', $like)
                    ->orWhere('assets.model', 'like', $like)
                    ->orWhere('asset_types.name', 'like', $like);
            });
        }
    }

    private function flagged(Request $request, string $key): bool
    {
        $value = $this->param($request, $key);

        return $value !== null && in_array(strtolower($value), ['1', 'true', 'yes'], true);
    }
}
