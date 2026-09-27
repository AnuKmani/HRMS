<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Concerns\BuildsResourceLists;
use App\Http\Controllers\Controller;
use App\Http\Requests\EndSiteVisitRequest;
use App\Http\Requests\StartSiteVisitRequest;
use App\Http\Resources\SiteVisitResource;
use App\Http\Responses\ApiResponse;
use App\Http\Responses\PaginatedResponse;
use App\Models\SiteVisit;
use App\Services\Attendance\SiteVisitService;
use App\Support\Visibility;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * /api/v1/site-visits
 *
 * Start and end are self-service and carry no `permission:` middleware —
 * a visit is something you record about your own movement, so the policy's
 * question is "are you a linked employee?", not "do you hold a grant?".
 *
 * The collection and the single record are behind `attendance.view`, because
 * reading where somebody went is the same privilege as reading when they
 * arrived, and splitting it across two permissions would only guarantee that
 * one of them gets forgotten.
 */
class SiteVisitController extends Controller
{
    use BuildsResourceLists;

    public function __construct(private readonly SiteVisitService $visits) {}

    public function index(Request $request): JsonResponse
    {
        $this->authorize('viewAny', SiteVisit::class);

        $query = SiteVisit::query()->with(['employee', 'site', 'project']);

        Visibility::attendanceFor($query, $request->user());

        if ($value = $this->param($request, 'employee_id', 'employee')) {
            $query->where('employee_id', (int) $value);
        }

        if ($value = $this->param($request, 'project_id', 'project')) {
            $query->where('project_id', (int) $value);
        }

        if ($value = $this->param($request, 'site_id', 'site')) {
            $query->where('site_id', (int) $value);
        }

        if ($value = $this->param($request, 'status')) {
            $query->where('status', $value);
        }

        if ($value = $this->param($request, 'date', 'started_at')) {
            $query->whereDate('started_at', $value);
        }

        if ($value = $this->param($request, 'date_from', 'from')) {
            $query->whereDate('started_at', '>=', $value);
        }

        if ($value = $this->param($request, 'date_to', 'to')) {
            $query->whereDate('started_at', '<=', $value);
        }

        $query->orderBy(
            $this->sortColumn($request, ['started_at', 'ended_at', 'status', 'created_at', 'id'], 'started_at'),
            $this->sortDirection($request, 'desc'),
        )->orderBy('id');

        $page = $query->paginate($this->perPage($request));

        return PaginatedResponse::make(
            'Site visits retrieved.',
            SiteVisitResource::collection($page->getCollection()),
            $page,
        );
    }

    /**
     * GET /api/v1/site-visits/today — no `permission:` middleware: the
     * caller's own visits, decided by the policy.
     */
    public function today(Request $request): JsonResponse
    {
        $this->authorize('today', SiteVisit::class);

        $visits = array_map(
            fn (SiteVisit $visit) => new SiteVisitResource($visit),
            $this->visits->today($request->user()),
        );

        return ApiResponse::success('Today\'s site visits retrieved.', (object) ['items' => $visits]);
    }

    public function store(StartSiteVisitRequest $request): JsonResponse
    {
        $visit = $this->visits->start($request->user(), $request->validated());
        $visit->load(['site.project', 'project']);

        return ApiResponse::created('Site visit started.', new SiteVisitResource($visit));
    }

    public function end(EndSiteVisitRequest $request, SiteVisit $siteVisit): JsonResponse
    {
        $visit = $this->visits->end($request->user(), $siteVisit, $request->validated());
        $visit->load(['site.project', 'project']);

        return ApiResponse::success('Site visit ended.', new SiteVisitResource($visit));
    }
}
