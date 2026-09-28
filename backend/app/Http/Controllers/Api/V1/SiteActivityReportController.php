<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Concerns\AttachesReportPhotos;
use App\Http\Controllers\Concerns\BuildsResourceLists;
use App\Http\Controllers\Controller;
use App\Http\Requests\StoreReportPhotosRequest;
use App\Http\Requests\StoreSiteActivityReportRequest;
use App\Http\Requests\SubmitSiteActivityReportRequest;
use App\Http\Requests\UpdateSiteActivityReportRequest;
use App\Http\Resources\SiteActivityReportPhotoResource;
use App\Http\Resources\SiteActivityReportResource;
use App\Http\Resources\SiteResource;
use App\Http\Responses\ApiResponse;
use App\Http\Responses\PaginatedResponse;
use App\Models\Site;
use App\Models\SiteActivityReport;
use App\Models\SiteActivityReportPhoto;
use App\Services\SiteReport\ReportPhotoStore;
use App\Services\SiteReport\SiteActivityReportService;
use App\Support\Visibility;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * /api/v1/site-activity-reports
 *
 * The field worker's own account of a site-day, and the photographs to go
 * with it.
 *
 * The shape follows the other list modules — index/store/show/update behind
 * `permission:` for the coarse gate and `authorize()` for the row-level one
 * — with two additions that belong to reporting and nothing else:
 *
 *  - `reportable-sites` answers "which sites may I file about?", which is
 *    the question an Employee cannot ask `GET /sites` (no `sites.view`) and
 *    which the store endpoint would otherwise only answer *after* the form
 *    was submitted. It applies the same visibility rule as the service, so
 *    a picker never offers something the next call then refuses.
 *  - a separate `photos` pair rather than multipart create/update. A report
 *    saved with three photographs re-uploads all three on every correction,
 *    and a site with one bar of signal would never finish an edit.
 *
 * There is no `PUT .../{report}/status` and no `DELETE`. Status moves only
 * through `submit`, and a submitted report is evidence — withdrawing it is
 * not a verb this module has.
 */
class SiteActivityReportController extends Controller
{
    use AttachesReportPhotos;
    use BuildsResourceLists;

    /**
     * Photographs one report may hold in total. The per-request ceiling is
     * six (StoreReportPhotosRequest); this is the one that stops a repeat
     * caller growing a single row without bound.
     */
    public const MAX_PHOTOS_PER_REPORT = 12;

    public function __construct(
        private readonly SiteActivityReportService $service,
        private readonly ReportPhotoStore $photos,
    ) {}

    public function index(Request $request): JsonResponse
    {
        $this->authorize('viewAny', SiteActivityReport::class);

        $query = Visibility::siteActivityReportsFor(
            SiteActivityReport::query()->with(['employee', 'project', 'site']),
            $request->user(),
        );

        if ($employeeId = $this->param($request, 'employee_id')) {
            // A narrowing filter, not an override: the visibility scope is
            // already applied above, so asking for somebody else's name
            // returns nothing rather than handing their rows over.
            $query->where('employee_id', (int) $employeeId);
        }

        if ($projectId = $this->param($request, 'project_id')) {
            $query->where('project_id', (int) $projectId);
        }

        if ($siteId = $this->param($request, 'site_id')) {
            $query->where('site_id', (int) $siteId);
        }

        if ($workCategory = $this->param($request, 'work_category')) {
            $query->where('work_category', $workCategory);
        }

        if ($statuses = $this->param($request, 'status')) {
            $query->whereIn('status', array_filter(explode(',', $statuses)));
        }

        if ($from = $this->param($request, 'from', 'date_from')) {
            $query->where('report_date', '>=', $from);
        }

        if ($to = $this->param($request, 'to', 'date_to')) {
            $query->where('report_date', '<=', $to);
        }

        if ($term = $this->searchTerm($request)) {
            $escaped = '%'.$this->escapeLike($term).'%';

            $query->where(function ($q) use ($escaped) {
                $q->where('work_category', 'like', $escaped)
                    ->orWhere('work_performed', 'like', $escaped);
            });
        }

        $page = $query->orderBy(
            $this->sortColumn(
                $request,
                ['report_date', 'progress_percentage', 'status', 'created_at'],
                'report_date',
            ),
            $this->sortDirection($request, 'desc'),
        )->orderBy('id')->paginate($this->perPage($request));

        return PaginatedResponse::make(
            'Site activity reports retrieved.',
            SiteActivityReportResource::collection($page->getCollection()),
            $page,
        );
    }

    /**
     * The sites this account may file a report about.
     *
     * Same envelope and pagination as `GET /sites`, so the Flutter picker is
     * the same widget either way — but a *different* rule: not "may you see
     * the site directory?" but "may you write about this?". A supervisor
     * whose posting moved yesterday is not offered yesterday's site even
     * though `GET /sites` still shows it to them.
     */
    public function reportableSites(Request $request): JsonResponse
    {
        $this->authorize('viewAny', SiteActivityReport::class);

        $query = Visibility::reportableSitesFor(
            Site::query()->with('project')->where('status', Site::STATUS_ACTIVE),
            $request->user(),
        );

        if ($term = $this->searchTerm($request)) {
            $escaped = $this->escapeLike($term);

            $query->where(function ($q) use ($escaped) {
                $q->where('name', 'like', '%'.$escaped.'%')
                    ->orWhere('code', 'like', '%'.$escaped.'%');
            });
        }

        $page = $query->orderBy('name')->orderBy('id')->paginate($this->perPage($request));

        return PaginatedResponse::make(
            'Reportable sites retrieved.',
            SiteResource::collection($page->getCollection()),
            $page,
        );
    }

    public function store(StoreSiteActivityReportRequest $request): JsonResponse
    {
        $report = $this->service->create($request->user(), $request->validated());

        return ApiResponse::created(
            'Site activity report created.',
            new SiteActivityReportResource($report->load(['employee', 'project', 'site'])),
        );
    }

    public function show(Request $request, SiteActivityReport $siteActivityReport): JsonResponse
    {
        $this->authorize('view', $siteActivityReport);

        $siteActivityReport->load(['employee', 'project', 'site', 'photos']);

        return ApiResponse::success(
            'Site activity report retrieved.',
            new SiteActivityReportResource($siteActivityReport),
        );
    }

    public function update(
        UpdateSiteActivityReportRequest $request,
        SiteActivityReport $siteActivityReport,
    ): JsonResponse {
        $report = $this->service->update(
            $request->user(),
            $siteActivityReport,
            $request->validated(),
        );

        return ApiResponse::success(
            'Site activity report updated.',
            new SiteActivityReportResource($report->load(['employee', 'project', 'site', 'photos'])),
        );
    }

    public function submit(
        SubmitSiteActivityReportRequest $request,
        SiteActivityReport $siteActivityReport,
    ): JsonResponse {
        $report = $this->service->submit($request->user(), $siteActivityReport, $request->validated());

        return ApiResponse::success(
            'Site activity report submitted.',
            new SiteActivityReportResource($report->load(['employee', 'project', 'site', 'photos'])),
        );
    }

    public function storePhotos(
        StoreReportPhotosRequest $request,
        SiteActivityReport $siteActivityReport,
    ): JsonResponse {
        $this->assertReportIsEditable($siteActivityReport);

        $records = $this->attachReportPhotos(
            $request,
            $siteActivityReport,
            self::MAX_PHOTOS_PER_REPORT,
        );

        return ApiResponse::created(
            'Photographs added.',
            SiteActivityReportPhotoResource::collection($records),
        );
    }

    public function destroyPhoto(
        Request $request,
        SiteActivityReport $siteActivityReport,
        SiteActivityReportPhoto $photo,
    ): JsonResponse {
        $this->authorize('update', $siteActivityReport);

        $this->assertPhotoBelongsTo($siteActivityReport, $photo);
        $this->assertReportIsEditable($siteActivityReport);

        $this->photos->delete($photo->path);
        $photo->delete();

        return ApiResponse::success('Photograph removed.');
    }

    public function showPhoto(
        Request $request,
        SiteActivityReport $siteActivityReport,
        SiteActivityReportPhoto $photo,
    ): StreamedResponse {
        // Read, not write: a viewer needs `view`, and the row-level question
        // is the same one `GET .../{report}` answers.
        $this->authorize('view', $siteActivityReport);

        $this->assertPhotoBelongsTo($siteActivityReport, $photo);

        $response = $this->photos->response($photo->path);

        if ($response === null) {
            abort(404, 'That photograph is no longer available.');
        }

        return $response;
    }

    protected function reportPhotoStore(): ReportPhotoStore
    {
        return $this->photos;
    }
}
