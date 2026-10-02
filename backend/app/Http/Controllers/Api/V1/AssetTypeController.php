<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Concerns\BuildsResourceLists;
use App\Http\Controllers\Controller;
use App\Http\Resources\AssetTypeResource;
use App\Http\Responses\ApiResponse;
use App\Models\AssetType;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * GET /api/v1/asset-types
 *
 * The vocabulary of *kinds* of company asset, and the whole controller is
 * one index method — the same shape as TrainingTypeController for the same
 * reasons, which are worth repeating because they are not accidental:
 *
 *  - **unpaginated**, because this is the table behind every asset form's
 *    picker. It is a handful of rows that change when an operator adds one,
 *    not a collection that grows, and a picker that had to page would be a
 *    picker that hid most of its options behind a tap.
 *
 *  - **no row scope**, because an asset type describes no property. An
 *    Employee and an HR Admin read the same seven words, because both need
 *    them to make sense of a register entry. The narrow rules live on the
 *    assets themselves.
 *
 *  - **a retired type is still listed** unless `status=active` is asked
 *    for, so a laptop registered under a withdrawn kind remains
 *    recognisable as the kind it was registered as.
 */
class AssetTypeController extends Controller
{
    use BuildsResourceLists;

    public function index(Request $request): JsonResponse
    {
        // No policy, and deliberately so: an asset type describes no
        // property, so there is no row for one to guard. The coarse
        // permission the route already requires is the whole answer.
        abort_unless($request->user()?->can('assets.view') === true, 403);

        $query = AssetType::query();

        if ($status = $this->param($request, 'status')) {
            $query->where('status', $status);
        }

        if ($term = $this->searchTerm($request)) {
            $like = '%'.$this->escapeLike($term).'%';

            $query->where(function ($inner) use ($like) {
                $inner->where('name', 'like', $like)
                    ->orWhere('code', 'like', $like);
            });
        }

        $query->orderBy(
            $this->sortColumn($request, ['name', 'code', 'status', 'created_at'], 'name'),
            $this->sortDirection($request, 'asc'),
        );

        return ApiResponse::success(
            'Asset types.',
            AssetTypeResource::collection($query->get()),
        );
    }
}
