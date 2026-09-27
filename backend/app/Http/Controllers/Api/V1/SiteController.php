<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Concerns\BuildsResourceLists;
use App\Http\Controllers\Controller;
use App\Http\Requests\StoreSiteRequest;
use App\Http\Requests\UpdateSiteRequest;
use App\Http\Resources\SiteResource;
use App\Http\Responses\ApiResponse;
use App\Http\Responses\PaginatedResponse;
use App\Models\Site;
use App\Support\Visibility;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * /api/v1/sites
 *
 * Coordinates and the geofence radius are read from and written to the row
 * only. There is no constant anywhere in this controller, this request or
 * this resource that says where a site is — the nearest thing to a fixed
 * number is the validation bound in config/hrms.php, and that governs what
 * may be *stored*, never where anybody actually stands.
 */
class SiteController extends Controller
{
    use BuildsResourceLists;

    public function index(Request $request): JsonResponse
    {
        $this->authorize('viewAny', Site::class);

        $query = Site::query()->with([
            'project',
            'siteManager',
            'siteSupervisor',
            'shift',
        ]);

        Visibility::projectsAndSitesFor($query, $request->user());

        if ($term = $this->searchTerm($request)) {
            $like = '%'.$this->escapeLike($term).'%';
            $query->where(function ($q) use ($like) {
                $q->where('name', 'like', $like)
                    ->orWhere('code', 'like', $like)
                    ->orWhere('address', 'like', $like);
            });
        }

        if ($value = $this->param($request, 'project_id', 'project')) {
            $query->where('project_id', (int) $value);
        }

        if ($value = $this->param($request, 'status')) {
            $query->where('status', $value);
        }

        if ($value = $this->param($request, 'site_manager_id', 'site_manager', 'manager')) {
            $query->where('site_manager_id', (int) $value);
        }

        if ($value = $this->param($request, 'site_supervisor_id', 'site_supervisor', 'supervisor')) {
            $query->where('site_supervisor_id', (int) $value);
        }

        $query->orderBy(
            $this->sortColumn($request, ['name', 'code', 'status', 'project_id', 'created_at', 'id'], 'name'),
            $this->sortDirection($request),
        )->orderBy('id');

        $page = $query->paginate($this->perPage($request));

        return PaginatedResponse::make(
            'Sites retrieved.',
            SiteResource::collection($page->getCollection()),
            $page,
        );
    }

    public function show(Site $site): JsonResponse
    {
        $this->authorize('view', $site);

        $site->load(['project', 'siteManager', 'siteSupervisor', 'shift']);

        return ApiResponse::success('Site retrieved.', new SiteResource($site));
    }

    public function store(StoreSiteRequest $request): JsonResponse
    {
        $site = Site::create($request->validated());

        return ApiResponse::created(
            'Site created.',
            new SiteResource($site->load(['project', 'siteManager', 'siteSupervisor', 'shift'])),
        );
    }

    public function update(UpdateSiteRequest $request, Site $site): JsonResponse
    {
        $this->authorize('update', $site);

        $site->update($request->validated());

        return ApiResponse::success(
            'Site updated.',
            new SiteResource($site->refresh()->load(['project', 'siteManager', 'siteSupervisor', 'shift'])),
        );
    }

    public function destroy(Site $site): JsonResponse
    {
        $this->authorize('delete', $site);

        // Posting history references this site with restrictOnDelete, and
        // more importantly it *should*: the table's whole purpose is saying
        // who stood here. Archiving the site keeps every row intact.
        if ($site->assignments()->exists()) {
            return ApiResponse::error(
                'This site still has assignment history. A site with history cannot be deleted.',
                ['site_id' => 'Site still has assignment history.'],
                422,
            );
        }

        $site->delete();

        return ApiResponse::success('Site deleted.');
    }
}
